<?php
declare(strict_types=1);
/*
 * Pedidos (Fase 3) · Máquina de estados, listado, detalle, pago, guía, factura
 * y transportadoras. Toda transición: BEGIN → SELECT … FOR UPDATE → validar →
 * UPDATE → INSERT evento → efectos → COMMIT.
 */
namespace App\Services;

use App\Core\UserError;
use App\Repositories\Contracts\PedidoRepositoryInterface;
use PDO;

final class PedidoService
{
    /** Única fuente de verdad: estado origen => [destino => permiso]. */
    private const TRANSICIONES = [
        'pendiente_pago' => ['confirmado' => 'pedidos.editar', 'cancelado' => 'pedidos.cancelar'],
        'confirmado'     => ['en_preparacion' => 'pedidos.editar'],
        'en_preparacion' => ['enviado' => 'envios.gestionar'],
        'enviado'        => ['entregado' => 'pedidos.editar'],
    ];

    public function __construct(private PDO $pdo, private PedidoRepositoryInterface $repo)
    {
    }

    public static function permisoTransicion(string $desde, string $hacia): ?string
    {
        return self::TRANSICIONES[$desde][$hacia] ?? null;
    }

    private function req(bool $ok, string $msg): void
    {
        if (!$ok) {
            throw new UserError($msg);
        }
    }

    private static function s($v, int $max): string
    {
        return \App\Core\Texto::s($v, $max);
    }

    private function uid(): ?int
    {
        return (int)($_SESSION['uid'] ?? 0) ?: null;
    }

    private function transicionesDisponibles(string $estado): array
    {
        $disponibles = self::TRANSICIONES[$estado] ?? [];
        $resultado = [];
        foreach ($disponibles as $hacia => $permiso) {
            if (puede($permiso)) {
                $resultado[] = $hacia;
            }
        }
        return $resultado;
    }

    /* -------------------------------- listado ------------------------------- */

    public function lista(array $g): array
    {
        $q      = self::s($g['q'] ?? '', 80);
        $estado = self::s($g['estado'] ?? '', 30);
        $pagina = max(1, (int)($g['pagina'] ?? 1));
        $limite = min(100, max(1, (int)($g['limite'] ?? 20)));

        $where = ['1=1'];
        $args = [];
        if ($q !== '') {
            $where[] = '(p.cliente_nombre LIKE ? OR p.cliente_email LIKE ? OR p.cliente_tel LIKE ? OR p.id = ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like, (ctype_digit($q) ? (int)$q : 0));
        }
        if ($estado !== '') {
            $where[] = 'p.estado = ?';
            $args[] = $estado;
        }
        $w = implode(' AND ', $where);

        $total = $this->repo->contar($w, $args);
        $offset = ($pagina - 1) * $limite;
        $items = array_map(fn($x) => [
            'id'          => (int)$x['id'],
            'cliente'     => (string)$x['cliente_nombre'],
            'email'       => (string)$x['cliente_email'],
            'telefono'    => (string)$x['cliente_tel'],
            'ciudad'      => (string)$x['envio_ciudad'],
            'total'       => (int)$x['total'],
            'estado'      => (string)$x['estado'],
            'pago_estado' => (string)$x['pago_estado'],
            'metodo_pago' => (string)$x['metodo_pago'],
            'n_items'     => (int)$x['n_items'],
            'creado'      => (string)$x['creado'],
        ], $this->repo->listar($w, $args, $limite, $offset));

        return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'limite' => $limite, 'paginas' => max(1, (int)ceil($total / $limite))];
    }

    /* -------------------------------- detalle ------------------------------- */

    public function detalle(int $id): array
    {
        $p = $this->repo->pedido($id);
        if ($p === null) {
            throw new UserError('Pedido no encontrado');
        }

        $items = array_map(fn($x) => [
            'id'          => (int)$x['id'],
            'nombre'      => (string)$x['nombre'],
            'sku'         => (string)$x['sku'],
            'talla'       => (string)$x['talla'],
            'color'       => (string)$x['color'],
            'precio'      => (int)$x['precio'],
            'cantidad'    => (int)$x['cantidad'],
            'total_linea' => (int)($x['total_linea'] ?? ($x['precio'] * $x['cantidad'])),
        ], $this->repo->itemsDe($id));

        $pagos = array_map(fn($x) => [
            'id' => (int)$x['id'], 'pasarela' => (string)$x['pasarela'], 'estado' => (string)$x['estado'],
            'monto' => (int)$x['monto'], 'referencia' => $x['referencia_externa'], 'creado' => (string)$x['creado'],
        ], $this->repo->pagosDe($id));

        $envio = $this->repo->envioDe($id);
        $factura = $this->repo->facturaDe($id);

        $eventos = array_map(fn($x) => [
            'id' => (int)$x['id'], 'antes' => $x['estado_anterior'], 'despues' => $x['estado_nuevo'],
            'nota' => (string)$x['nota'], 'usuario' => $x['usuario'], 'creado' => (string)$x['creado'],
        ], $this->repo->eventosDe($id));

        $transportadoras = array_map(fn($x) => ['id' => (int)$x['id'], 'nombre' => (string)$x['nombre']], $this->repo->transportadorasActivas());

        return [
            'pedido' => [
                'id' => (int)$p['id'], 'cliente_nombre' => (string)$p['cliente_nombre'],
                'cliente_email' => (string)$p['cliente_email'], 'cliente_tel' => (string)$p['cliente_tel'],
                'envio_ciudad' => (string)$p['envio_ciudad'], 'envio_direccion' => (string)$p['envio_direccion'],
                'envio_departamento' => (string)$p['envio_departamento'], 'envio_indicaciones' => (string)$p['envio_indicaciones'],
                'subtotal' => (int)$p['subtotal'], 'descuento' => (int)$p['descuento'],
                'costo_envio' => (int)$p['costo_envio'], 'impuestos' => (int)$p['impuestos'], 'total' => (int)$p['total'],
                'cupon_codigo' => $p['cupon_codigo'],
                'estado' => (string)$p['estado'], 'pago_estado' => (string)$p['pago_estado'],
                'metodo_pago' => (string)$p['metodo_pago'], 'notas_cliente' => (string)$p['notas_cliente'],
                'notas_internas' => $p['notas_internas'], 'expira_pago' => $p['expira_pago'],
                'creado' => (string)$p['creado'], 'entregado_en' => $p['entregado_en'],
            ],
            'items' => $items,
            'pagos' => $pagos,
            'envio' => $envio !== null ? [
                'id' => (int)$envio['id'], 'guia' => $envio['guia'], 'estado' => (string)$envio['estado'],
                'transportadora_id' => $envio['transportadora_id'] ? (int)$envio['transportadora_id'] : null,
                'transportadora' => $envio['transportadora'],
                'tracking_url' => $envio['tracking_url'] ? str_replace('{guia}', (string)$envio['guia'], $envio['tracking_url']) : '',
            ] : null,
            'factura' => $factura !== null ? [
                'numero_completo' => (string)$factura['numero_completo'], 'fecha' => (string)$factura['fecha'],
                'total' => (int)$factura['total'],
            ] : null,
            'eventos' => $eventos,
            'transportadoras' => $transportadoras,
            'transiciones' => $this->transicionesDisponibles((string)$p['estado']),
        ];
    }

    /* ------------------------------ transiciones ---------------------------- */

    public function transicion(array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        $hacia = self::s($b['estado'] ?? '', 30);

        $this->pdo->beginTransaction();
        try {
            $p = $this->repo->pedidoBloqueado($id);
            if ($p === null) {
                throw new UserError('Pedido no encontrado');
            }
            $desde = (string)$p['estado'];

            $permiso = self::permisoTransicion($desde, $hacia);
            if ($permiso === null) {
                throw new UserError("No se puede pasar de '$desde' a '$hacia'");
            }
            if (!puede($permiso)) {
                throw new UserError('No tienes permiso para esta acción');
            }

            if ($hacia === 'cancelado') {
                $this->repo->devolverStock($id, $this->uid());
                if ($p['cupon_codigo']) {
                    $this->repo->revertirCupon((string)$p['cupon_codigo']);
                }
            }
            if ($hacia === 'entregado') {
                $this->repo->marcarEntregado($id);
            }

            $this->repo->fijarEstado($id, $hacia);
            $this->repo->insertarEvento($id, $desde, $hacia, null, $this->uid());
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        auditar('pedido.estado', 'pedidos', $id, ['antes' => $desde, 'despues' => $hacia]);
        return ['ok' => true, 'estado' => $hacia];
    }

    /** Registra un pago manual y confirma el pedido. */
    public function pago(array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        $metodo = self::s($b['metodo'] ?? 'transferencia', 30);
        $monto = (int)($b['monto'] ?? 0);
        $referencia = self::s($b['referencia'] ?? '', 100);
        $this->req(in_array($metodo, ['transferencia', 'nequi', 'efectivo', 'contraentrega', 'otro'], true), 'Método no válido');

        $this->pdo->beginTransaction();
        try {
            $p = $this->repo->pedidoBloqueado($id);
            if ($p === null) {
                throw new UserError('Pedido no encontrado');
            }
            if ((string)$p['estado'] !== 'pendiente_pago') {
                throw new UserError('Solo se puede pagar un pedido pendiente de pago');
            }
            $monto = $monto > 0 ? $monto : (int)$p['total'];

            $this->repo->insertarPago($id, $metodo === 'contraentrega' ? 'contraentrega' : 'manual', $referencia === '' ? null : $referencia, 'aprobado', $monto);
            $this->repo->fijarPago($id, $metodo);
            $this->repo->insertarEvento($id, 'pendiente_pago', 'confirmado', 'Pago registrado', $this->uid());
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        auditar('pedido.pago', 'pedidos', $id, ['monto' => $monto, 'metodo' => $metodo]);
        return ['ok' => true];
    }

    /** Guarda la nota interna. */
    public function nota(array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        $nota = ($b['nota'] ?? '') === '' ? null : mb_substr(trim((string)$b['nota']), 0, 4000);
        $this->repo->fijarNota($id, $nota);
        auditar('pedido.nota', 'pedidos', $id);
        return ['ok' => true];
    }

    /** Guarda la guía y pasa el pedido a 'enviado'. */
    public function guia(array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        $transportadora = (int)($b['transportadora_id'] ?? 0);
        $guia = self::s($b['guia'] ?? '', 60);
        $this->req($transportadora > 0, 'Elige una transportadora');
        $this->req($guia !== '', 'Escribe el número de guía');

        $this->pdo->beginTransaction();
        try {
            $estado = $this->repo->estadoDe($id);
            if ($estado === null) {
                throw new UserError('Pedido no encontrado');
            }
            if (!in_array($estado, ['en_preparacion', 'confirmado'], true)) {
                throw new UserError('No se puede despachar en este estado');
            }

            $this->repo->upsertEnvio($id, $transportadora, $guia);
            $this->repo->fijarEstado($id, 'enviado');
            $this->repo->insertarEvento($id, $estado, 'enviado', 'Guía ' . $guia, $this->uid());
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        auditar('pedido.guia', 'pedidos', $id, ['guia' => $guia, 'transportadora' => $transportadora]);
        return ['ok' => true];
    }

    /* -------------------------------- factura ------------------------------- */

    public function facturaEmitir(int $id): array
    {
        $this->pdo->beginTransaction();
        try {
            $p = $this->repo->pedidoBloqueado($id);
            if ($p === null) {
                throw new UserError('Pedido no encontrado');
            }
            if ((string)$p['pago_estado'] !== 'pagado') {
                throw new UserError('Emitir factura requiere el pago registrado');
            }
            if ($this->repo->facturaDe($id) !== null) {
                throw new UserError('El pedido ya tiene factura');
            }

            $n = $this->repo->consecutivoFactura() + 1;
            $this->repo->fijarConsecutivo($n);
            $numeroCompleto = 'MG-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
            $this->repo->insertarFactura($id, $n, $numeroCompleto, (int)$p['subtotal'], (int)$p['descuento'], (int)$p['impuestos'], (int)$p['total']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        auditar('factura.emitir', 'pedidos', $id, ['numero' => $numeroCompleto]);
        return ['ok' => true, 'numero' => $numeroCompleto];
    }

    /** Datos de la factura para la vista imprimible. */
    public function facturaVer(int $id): array
    {
        $f = $this->repo->facturaDe($id);
        if ($f === null) {
            throw new UserError('Factura no encontrada');
        }
        return [
            'factura' => $f,
            'pedido'  => $this->repo->pedido($id),
            'items'   => $this->repo->itemsDe($id),
        ];
    }

    /* ----------------------------- transportadoras -------------------------- */

    public function transportadoras(?array $b): array
    {
        if ($b !== null && isset($b['nombre'])) {
            $id = (int)($b['id'] ?? 0);
            $nombre = self::s($b['nombre'], 60);
            $url = self::s($b['tracking_url'] ?? '', 200);
            $activo = array_key_exists('activo', $b) ? (!empty($b['activo']) ? 1 : 0) : 1;
            $this->req($nombre !== '', 'Escribe el nombre');
            if ($id) {
                $this->repo->actualizarTransportadora($id, $nombre, $url, $activo);
            } else {
                $this->repo->insertarTransportadora($nombre, $url, $activo);
            }
            return ['ok' => true];
        }
        return array_map(fn($x) => ['id' => (int)$x['id'], 'nombre' => (string)$x['nombre'], 'tracking_url' => (string)$x['tracking_url'], 'activo' => (bool)$x['activo']], $this->repo->transportadoras());
    }

    /* --------------------------- expiración (cron) --------------------------- */

    public function expirarPendientes(): int
    {
        $ids = $this->repo->pendientesVencidos();
        $n = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            $this->pdo->beginTransaction();
            try {
                $p = $this->repo->pedidoBloqueado($id);
                if ($p === null || $p['estado'] !== 'pendiente_pago') {
                    $this->pdo->rollBack();
                    continue;
                }
                $this->repo->devolverStock($id, null);
                if ($p['cupon_codigo']) {
                    $this->repo->revertirCupon((string)$p['cupon_codigo']);
                }
                $this->repo->fijarEstado($id, 'cancelado');
                $this->repo->insertarEvento($id, 'pendiente_pago', 'cancelado', 'Expirado por falta de pago', null);
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
