<?php
declare(strict_types=1);
/*
 * Checkout · creación atómica del pedido.
 *
 * Orden (no cambiar sin revisar la transacción):
 *   1. Validar datos del cliente
 *   2. Bloquear productos y variantes (FOR UPDATE)
 *   3. Validar líneas y stock disponible
 *   4. Cliente, promociones y cupón (recalculados en servidor)
 *   5. Insertar pedido y asignar stock (libro mayor)
 *   6. Registrar uso de cupón y vincular carrito
 *   7. COMMIT y después, notificar (nunca bloquea el pedido)
 *
 * Nota de migración: `mk_evaluar_promociones`, `mk_cupon`, `crm_cliente_por_datos`
 * y `ob_encolar` siguen siendo helpers globales hasta migrar sus dominios.
 */
namespace App\Services;

use App\Core\UserError;
use App\Repositories\Contracts\CheckoutRepositoryInterface;
use PDO;

final class CheckoutService
{
    public function __construct(
        private PDO $pdo,
        private CheckoutRepositoryInterface $repo,
        private CrmService $crm,
    ) {
    }

    private static function req(bool $ok, string $msg): void
    {
        if (!$ok) {
            throw new UserError($msg);
        }
    }

    private static function s($v, int $max): string
    {
        return \App\Core\Texto::s($v, $max);
    }

    /** 1. Datos del cliente. Devuelve los valores normalizados. */
    public static function validarDatos(array $b): array
    {
        self::req(($b['web'] ?? '') === '', 'Solicitud rechazada');
        $d = [
            'nombre' => self::s($b['nombre'] ?? '', 100),
            'tel'    => preg_replace('/[^\d+]/', '', self::s($b['telefono'] ?? '', 20)),
            'ciudad' => self::s($b['ciudad'] ?? '', 60),
            'dir'    => self::s($b['direccion'] ?? '', 200),
            'mail'   => self::s($b['email'] ?? '', 120),
            'notas'  => self::s($b['notas'] ?? '', 300),
        ];
        self::req(mb_strlen($d['nombre']) >= 3, 'Escribe tu nombre completo');
        self::req(preg_match('/^\+?\d{7,15}$/', $d['tel']) === 1, 'Teléfono no válido');
        self::req($d['ciudad'] !== '', 'Indica tu ciudad');
        self::req(mb_strlen($d['dir']) >= 5, 'Indica tu dirección');
        self::req($d['mail'] !== '' && filter_var($d['mail'], FILTER_VALIDATE_EMAIL) !== false, 'Escribe un correo válido para confirmar tu pedido');
        return $d;
    }

    /** 3. Valida cada línea contra el catálogo bloqueado. Devuelve [subtotal, líneas, líneas promo]. */
    public static function validarLineas(array $items, array $P, array $V): array
    {
        $sub = 0;
        $need = [];
        $lineas = [];
        $lineasPromo = [];
        foreach ($items as $i) {
            $id = (int)($i['id'] ?? 0);
            $qty = (int)($i['qty'] ?? 0);
            $talla = self::s($i['talla'] ?? '', 10);
            self::req(isset($P[$id]), 'Un producto de tu carrito ya no está disponible');
            self::req($qty >= 1 && $qty <= 20, 'Cantidad no válida');
            self::req(!empty($V[$id][$talla]), 'Elige una talla válida para ' . $P[$id]['nombre']);
            $disp = array_sum(array_column($V[$id][$talla], 'stock'));
            $k = $id . '|' . $talla;
            $need[$k] = ($need[$k] ?? 0) + $qty;
            self::req($need[$k] <= $disp, 'Stock insuficiente de ' . $P[$id]['nombre'] . ($talla !== '' ? " (talla $talla)" : ''));
            $sub += (int)$P[$id]['precio'] * $qty;
            $lineas[] = ['id' => $id, 'qty' => $qty, 'talla' => $talla];
            $lineasPromo[] = ['producto_id' => $id, 'categoria' => (string)$P[$id]['categoria_id'], 'precio' => (int)$P[$id]['precio'], 'cantidad' => $qty];
        }
        return [$sub, $lineas, $lineasPromo];
    }

    /** 4. Promociones y cupón. */
    private function calcularDescuentos(int $sub, array $lineasPromo, int $clienteId, string $codigoCupon): array
    {
        $pedidosEntregados = $this->repo->contarPedidosEntregados($clienteId);
        $promos = mk_evaluar_promociones($lineasPromo, $sub);
        $descuentoPromo = (int)$promos['descuento'];

        $cup = null;
        $descuentoCupon = 0;
        if ($codigoCupon !== '' && !$promos['exclusiva']) {
            $row = $this->repo->cuponBloqueado(strtoupper($codigoCupon));
            [$cup, $err, $descuentoCupon] = mk_cupon($row, $sub, $clienteId, $pedidosEntregados);
            self::req($cup !== null, (string)$err);
        }
        $descuento = $descuentoPromo + $descuentoCupon;
        $total = $sub - $descuento;
        self::req($total >= 0, 'Descuento no válido');
        return [
            'descuento' => $descuento, 'descuento_promo' => $descuentoPromo,
            'descuento_cupon' => $descuentoCupon, 'total' => $total, 'cupon' => $cup,
        ];
    }

    /** 6. Uso de cupón y vínculo con el carrito persistente. */
    private function registrarCuponYCarrito(int $pid, array $desc, string $carritoToken): void
    {
        if ($desc['cupon']) {
            $this->repo->registrarCupon($desc['cupon']['codigo']);
            $this->repo->insertarCuponUso($desc['cupon']['codigo'], $pid, $desc['descuento_cupon']);
        }
        if ($carritoToken !== '') {
            $this->repo->vincularCarrito($carritoToken, $pid);
        }
    }

    /** 7. Confirmación por correo (outbox). */
    private function encolarConfirmacion(int $pid, string $nombre, string $mail, int $total): int
    {
        $waTexto = rawurlencode("Hola MotoGear, quiero pagar mi pedido #$pid por $" . number_format($total, 0, ',', '.') . " a nombre de $nombre.");
        $waLink = 'https://api.whatsapp.com/send?phone=573167800764&text=' . $waTexto;
        $html = '<p>Hola ' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Recibimos tu pedido <strong>#' . $pid . '</strong> por <strong>$' . number_format($total, 0, ',', '.') . '</strong>.</p>'
            . '<p>Para completar el pago, escríbenos por WhatsApp y te indicamos los pasos:</p>'
            . '<p style="font-size:16px"><a href="' . $waLink . '">' . $waLink . '</a></p>'
            . '<p>Gracias por comprar en MotoGear.</p>';
        return ob_encolar('correo', ['para' => $mail, 'asunto' => "Tu pedido #$pid en MotoGear", 'html' => $html]);
    }

    /** Punto de entrada del checkout. Devuelve la respuesta JSON. */
    public function crear(array $b, string $ip, string $idem): array
    {
        $d = self::validarDatos($b);

        $items = $b['items'] ?? [];
        self::req(is_array($items) && count($items) >= 1 && count($items) <= 50, 'Tu carrito está vacío');
        foreach ($items as $it) {
            self::req(is_array($it), 'Carrito no válido');
        }

        // Idempotencia: un reintento con la misma clave devuelve el pedido ya creado.
        if ($idem !== '') {
            $ex = $this->repo->pedidoPorIdempotencia($idem);
            if ($ex !== null) {
                return ['id' => (int)$ex['id'], 'total' => (int)$ex['total'], 'repetido' => true];
            }
        }

        self::req($this->repo->contarPedidosPorIp($ip) < 10, 'Demasiados pedidos seguidos. Intenta más tarde');

        $this->pdo->beginTransaction();
        try {
            $ids = array_values(array_unique(array_map(fn($i) => (int)($i['id'] ?? 0), $items)));
            $cat = $this->repo->cargarCatalogo($ids);
            $P = $cat['productos'];
            $V = $cat['variantes'];
            [$sub, $lineas, $lineasPromo] = self::validarLineas($items, $P, $V);
            $clienteId = $this->crm->clientePorDatos($d['nombre'], $d['mail'], $d['tel']);
            $desc = $this->calcularDescuentos($sub, $lineasPromo, $clienteId, self::s($b['cupon'] ?? '', 30));

            $codigoCupon = $desc['cupon'] ? $desc['cupon']['codigo'] : null;
            $valores = [
                $d['nombre'], $d['tel'], $d['mail'], $d['ciudad'], $d['dir'], $d['notas'],
                $sub, $desc['descuento'], $codigoCupon, $desc['total'], $ip,
                $clienteId, $d['nombre'], $d['mail'], $d['tel'], $d['ciudad'], $d['dir'], $d['notas'], $codigoCupon,
                'pendiente_pago', 'pendiente', 'contraentrega', date('Y-m-d H:i:s', time() + 7200), $idem === '' ? null : $idem,
            ];
            $pid = $this->repo->insertarPedido($valores);
            $this->repo->insertarEventoInicial($pid);
            $this->repo->reservarStock($pid, $lineas, $P, $V);
            $this->registrarCuponYCarrito($pid, $desc, self::s($b['carrito_token'] ?? '', 36));
            $avisoId = $this->encolarConfirmacion($pid, $d['nombre'], $d['mail'], $desc['total']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['id' => $pid, 'total' => $desc['total'], 'descuento' => $desc['descuento'], '_aviso' => $avisoId];
    }
}
