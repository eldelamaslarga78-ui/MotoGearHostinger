<?php
declare(strict_types=1);
/*
 * Marketing (Fase 5) · Cupones avanzados, promociones y carritos.
 */
namespace App\Services;

use App\Core\Config;
use App\Core\UserError;
use App\Repositories\Contracts\MarketingRepositoryInterface;
use PDO;

final class MarketingService
{
    public function __construct(
        private PDO $pdo,
        private MarketingRepositoryInterface $repo,
        private OutboxService $outbox,
        private CrmService $crm,
        private Config $config,
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

    /* --------------------------- validación de cupón ------------------------ */

    /** Valida un cupón avanzado y calcula el descuento. [cupón, error, descuento] */
    public static function cupon(?array $c, int $subtotal, int $clienteId, int $pedidosEntregados, int $usosPorCliente = 0): array
    {
        if (!$c) {
            return [null, 'Cupón no válido'];
        }
        if (!(int)$c['activo']) {
            return [null, 'Cupón no válido'];
        }
        if ($c['desde'] && $c['desde'] > date('Y-m-d H:i:s')) {
            return [null, 'Cupón aún no vigente'];
        }
        if ($c['vence'] && $c['vence'] < date('Y-m-d')) {
            return [null, 'Cupón vencido'];
        }
        if ($subtotal < (int)$c['minimo']) {
            return [null, 'Compra mínima de $' . number_format((int)$c['minimo'], 0, ',', '.')];
        }
        if ($c['usos_max'] !== null && (int)$c['usos'] >= (int)$c['usos_max']) {
            return [null, 'Cupón agotado'];
        }

        if ($clienteId && $c['max_por_cliente'] !== null) {
            if ($usosPorCliente >= (int)$c['max_por_cliente']) {
                return [null, 'Ya usaste este cupón el máximo de veces'];
            }
        }
        if ((int)$c['primera_compra'] && $pedidosEntregados > 0) {
            return [null, 'Este cupón es solo para tu primera compra'];
        }

        $tipo = (string)($c['tipo'] ?? 'pct');
        $valor = (int)($c['valor'] ?? 0);
        if ($valor === 0 && isset($c['pct']) && (int)$c['pct'] > 0) {
            $valor = (int)$c['pct'];
        }
        $descuento = 0;
        if ($tipo === 'fijo') {
            $descuento = min($valor, $subtotal);
        } elseif ($tipo === 'pct') {
            $descuento = intdiv($subtotal * min(100, $valor), 100);
        } elseif ($tipo === 'envio_gratis') {
            $descuento = 0;
        }
        return [$c, null, $descuento];
    }

    /** Valida un cupón por código (público, checkout). */
    public function cuponValidar(array $b): array
    {
        $codigo = strtoupper(self::s($b['codigo'] ?? '', 30));
        $subtotal = (int)($b['subtotal'] ?? 0);
        $email = strtolower(self::s($b['email'] ?? '', 120));

        $clienteId = 0;
        $entregados = 0;
        if ($email !== '') {
            $clienteId = $this->repo->clientePorEmail($email);
            if ($clienteId) {
                $entregados = $this->repo->pedidosEntregadosDe($clienteId);
            }
        }

        $c = $this->repo->cupon($codigo);
        $usos = $clienteId ? $this->repo->usosPorCliente($codigo, $clienteId) : 0;
        [$cup, $err, $desc] = self::cupon($c, $subtotal, $clienteId, $entregados, $usos);
        if ($err) {
            return ['valido' => false, 'error' => $err];
        }
        $valor = (int)($cup['valor'] ?? 0);
        if ($valor === 0 && isset($cup['pct']) && (int)$cup['pct'] > 0) {
            $valor = (int)$cup['pct'];
        }
        return ['valido' => true, 'codigo' => (string)$cup['codigo'], 'tipo' => (string)$cup['tipo'], 'valor' => $valor, 'pct' => $cup['tipo'] === 'pct' ? $valor : 0, 'descuento' => $desc];
    }

    /** Usos de un cupón por parte de un cliente concreto. */
    public function usosPorCliente(string $codigo, int $clienteId): int
    {
        return $this->repo->usosPorCliente($codigo, $clienteId);
    }

    /* ------------------------------- promociones ----------------------------- */

    public function evaluarPromociones(array $lineas, int $subtotal): array
    {
        $promos = $this->repo->promocionesActivas();
        $descuento = 0;
        $detalle = [];
        $exclusivaAplicada = false;

        foreach ($promos as $p) {
            $ben = json_decode((string)$p['beneficios'], true) ?: [];
            $itemsIds = $this->repo->promocionItems((int)$p['id']);

            $elegibles = [];
            foreach ($lineas as $l) {
                $ok = !$itemsIds;
                foreach ($itemsIds as $pi) {
                    if ($pi['producto_id'] && (int)$l['producto_id'] === (int)$pi['producto_id']) {
                        $ok = true;
                        break;
                    }
                    if ($pi['categoria_id'] && $l['categoria'] === $pi['categoria_id']) {
                        $ok = true;
                        break;
                    }
                }
                if ($ok) {
                    $elegibles[] = $l;
                }
            }
            if (!$elegibles) {
                continue;
            }

            $d = 0;
            $tipo = (string)$p['tipo'];
            if ($tipo === 'descuento_categoria') {
                $pct = (int)($ben['pct'] ?? 0);
                foreach ($elegibles as $l) {
                    $d += intdiv((int)$l['precio'] * (int)$l['cantidad'] * $pct, 100);
                }
            } elseif ($tipo === 'volumen') {
                $desde = (int)($ben['desde'] ?? 0);
                $pct = (int)($ben['pct'] ?? 0);
                $unidades = array_sum(array_column($elegibles, 'cantidad'));
                if ($unidades >= $desde) {
                    foreach ($elegibles as $l) {
                        $d += intdiv((int)$l['precio'] * (int)$l['cantidad'] * $pct, 100);
                    }
                }
            } elseif ($tipo === 'nxm') {
                $lleva = (int)($ben['lleva'] ?? 2);
                $paga = (int)($ben['paga'] ?? 1);
                $gratis = max(0, $lleva - $paga);
                $todas = [];
                foreach ($elegibles as $l) {
                    for ($i = 0; $i < (int)$l['cantidad']; $i++) {
                        $todas[] = (int)$l['precio'];
                    }
                }
                sort($todas);
                $grupos = intdiv(count($todas), $lleva);
                for ($g = 0; $g < $grupos; $g++) {
                    $ini = $g * $lleva;
                    for ($f = 0; $f < $gratis; $f++) {
                        if (isset($todas[$ini + $f])) {
                            $d += $todas[$ini + $f];
                        }
                    }
                }
            } elseif ($tipo === 'combo') {
                $pct = (int)($ben['pct'] ?? 10);
                $requeridos = count($itemsIds);
                $presentes = 0;
                foreach ($itemsIds as $pi) {
                    foreach ($lineas as $l) {
                        if (($pi['producto_id'] && (int)$l['producto_id'] === (int)$pi['producto_id'])
                            || ($pi['categoria_id'] && $l['categoria'] === $pi['categoria_id'])) {
                            $presentes++;
                            break;
                        }
                    }
                }
                if ($presentes >= $requeridos && $requeridos > 0) {
                    foreach ($elegibles as $l) {
                        $d += intdiv((int)$l['precio'] * (int)$l['cantidad'] * $pct, 100);
                    }
                }
            }

            if ($d > 0) {
                $descuento += $d;
                $detalle[] = ['nombre' => (string)$p['nombre'], 'descuento' => $d];
                if ((int)$p['exclusiva']) {
                    $exclusivaAplicada = true;
                    break;
                }
            }
        }
        return ['descuento' => $descuento, 'detalle' => $detalle, 'exclusiva' => $exclusivaAplicada];
    }

    public function promocionBorrar(int $id): array
    {
        $nombre = $this->repo->promocionNombre($id);
        self::req($nombre !== null, 'Promoción no encontrada');
        $this->repo->borrarPromocion($id);
        auditar('promocion.borrar', 'promociones', $id, ['nombre' => $nombre]);
        return ['ok' => true];
    }

    public function promociones(?array $b = null): array
    {
        if ($b !== null && isset($b['data'])) {
            $d = $b['data'];
            $id = (int)($d['id'] ?? 0);
            $nombre = self::s($d['nombre'] ?? '', 80);
            $tipo = self::s($d['tipo'] ?? '', 30);
            $prioridad = (int)($d['prioridad'] ?? 10);
            $desde = ($d['desde'] ?? '') === '' ? null : self::s($d['desde'], 19);
            $hasta = ($d['hasta'] ?? '') === '' ? null : self::s($d['hasta'], 19);
            $activa = !empty($d['activa']) ? 1 : 0;
            $exclusiva = !empty($d['exclusiva']) ? 1 : 0;
            $condiciones = json_encode($d['condiciones'] ?? [], JSON_UNESCAPED_UNICODE);
            $beneficios = json_encode($d['beneficios'] ?? [], JSON_UNESCAPED_UNICODE);

            self::req($nombre !== '' && in_array($tipo, ['nxm', 'volumen', 'combo', 'descuento_categoria'], true), 'Nombre y tipo son obligatorios');

            $v = [$nombre, $tipo, $prioridad, $desde, $hasta, $condiciones, $beneficios, $activa, $exclusiva];
            $this->pdo->beginTransaction();
            try {
                if ($id) {
                    $this->repo->actualizarPromocion($v, $id);
                } else {
                    $id = $this->repo->insertarPromocion($v);
                }
                $this->repo->borrarPromocionItems($id);
                foreach (($d['items'] ?? []) as $it) {
                    $this->repo->insertarPromocionItem($id, ($it['producto_id'] ?? '') === '' ? null : (int)$it['producto_id'], ($it['categoria_id'] ?? '') === '' ? null : self::s($it['categoria_id'], 40));
                }
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
            return ['ok' => true, 'id' => $id];
        }
        return $this->repo->promociones();
    }

    /* -------------------------------- carritos ------------------------------- */

    public function carrito(array $b): array
    {
        $token = self::s($b['token'] ?? '', 36);
        $email = strtolower(self::s($b['email'] ?? '', 120));
        $telefono = self::s($b['telefono'] ?? '', 20);
        $items = $b['items'] ?? [];

        self::req(preg_match('/^[a-f0-9-]{36}$/i', $token) === 1, 'Token de carrito no válido');
        self::req($email !== '', 'El correo es obligatorio para guardar el carrito');

        $clienteId = $this->crm->clientePorDatos('', $email, $telefono);

        $this->pdo->beginTransaction();
        try {
            $carritoId = $this->repo->carritoPorToken($token);
            if (!$carritoId) {
                $carritoId = $this->repo->insertarCarrito($token, $clienteId, $email, $telefono === '' ? null : $telefono);
            } else {
                $this->repo->actualizarCarrito($carritoId, $email, $telefono === '' ? null : $telefono);
                $this->repo->borrarCarritoItems($carritoId);
            }

            $subtotal = 0;
            foreach ($items as $it) {
                $vid = (int)($it['variante_id'] ?? 0);
                $cant = max(1, (int)($it['cantidad'] ?? 1));
                $v = $this->repo->variantePrecio($vid);
                if (!$v) {
                    continue;
                }
                $precio = (int)$v['base'] + (int)$v['precio'];
                $this->repo->insertarCarritoItem($carritoId, $vid, $cant, $precio);
                $subtotal += $precio * $cant;
            }
            $this->repo->fijarSubtotal($carritoId, $subtotal);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['ok' => true, 'carrito_id' => $carritoId, 'subtotal' => $subtotal];
    }

    public function carritos(array $g): array
    {
        $pagina = max(1, (int)($g['pagina'] ?? 1));
        $limite = min(100, max(1, (int)($g['limite'] ?? 30)));
        $offset = ($pagina - 1) * $limite;
        $total = $this->repo->contarCarritosAbandonados();
        $items = array_map(fn($x) => [
            'id' => (int)$x['id'], 'email' => $x['email'], 'telefono' => $x['telefono'],
            'subtotal' => (int)$x['subtotal'], 'n_items' => (int)$x['n_items'],
            'ultima_actividad' => (string)$x['ultima_actividad'], 'recordatorios' => (int)$x['recordatorios'],
        ], $this->repo->listarCarritosAbandonados($limite, $offset));
        return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'limite' => $limite, 'paginas' => max(1, (int)ceil($total / $limite))];
    }

    public function carritoRecuperar(array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        $c = $this->repo->carrito($id);
        if (!$c || !$c['email']) {
            throw new UserError('Carrito sin correo');
        }

        $enlace = 'https://ecomerce.sebasdigital.site/carrito.html?token=' . urlencode($c['token']);
        $html = '<p>Hola,</p><p>Dejaste artículos en tu carrito de MotoGear. Recupéralos aquí:</p><p><a href="' . $enlace . '">' . $enlace . '</a></p>';
        $avisoId = $this->outbox->encolar('correo', ['para' => (string)$c['email'], 'asunto' => 'Tu carrito te espera en MotoGear', 'html' => $html]);
        $this->repo->marcarCarritoRecuperado($id);
        auditar('carrito.recuperar', 'carritos', $id, ['email' => (string)$c['email']]);
        $this->outbox->procesarIds([$avisoId], 6);
        return ['ok' => true];
    }

    public function marketingResumen(): array
    {
        $porEstado = $this->repo->resumenCarritos();
        return [
            'abandonados' => $porEstado['abandonado'] ?? ['n' => 0, 'monto' => 0],
            'recuperados' => $porEstado['recuperado'] ?? ['n' => 0, 'monto' => 0],
            'convertidos' => $porEstado['convertido'] ?? ['n' => 0, 'monto' => 0],
            'tasa' => null,
        ];
    }

    /* ----------------------------- abandono (cron) --------------------------- */

    public function marcarAbandonos(): int
    {
        $horas = (int)$this->config->get('carrito_abandono_horas', '2');
        return $this->repo->marcarAbandonos($horas);
    }

    public function enviarRecordatorios(): int
    {
        $horas = (int)$this->config->get('carrito_recordatorio_horas', '24');
        $n = 0;
        foreach ($this->repo->carritosParaRecordatorio($horas) as $c) {
            $enlace = 'https://ecomerce.sebasdigital.site/carrito.html?token=' . urlencode($c['token']);
            $html = '<p>Hola,</p><p>Dejaste artículos en tu carrito de MotoGear. Recupéralos aquí:</p><p><a href="' . $enlace . '">' . $enlace . '</a></p>';
            $this->pdo->beginTransaction();
            try {
                $this->outbox->encolar('correo', ['para' => (string)$c['email'], 'asunto' => 'Tu carrito te espera en MotoGear', 'html' => $html]);
                $this->repo->marcarRecordatorio((int)$c['id']);
                $this->pdo->commit();
                $n++;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }
        return $n;
    }
}
