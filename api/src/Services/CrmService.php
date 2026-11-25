<?php
declare(strict_types=1);
/*
 * CRM y RMA (Fase 4) · Clientes y devoluciones.
 */
namespace App\Services;

use App\Core\UserError;
use App\Repositories\Contracts\CrmRepositoryInterface;
use PDO;

final class CrmService
{
    public function __construct(private PDO $pdo, private CrmRepositoryInterface $repo)
    {
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

    private function uid(): ?int
    {
        return (int)($_SESSION['uid'] ?? 0) ?: null;
    }

    /* --------------------------- identificación ----------------------------- */

    /** Busca (o crea) un cliente por email o teléfono normalizado. Devuelve su id. */
    public function clientePorDatos(string $nombre, string $email, string $tel): int
    {
        $email = strtolower(trim($email));
        $tel = preg_replace('/\D+/', '', $tel);

        if ($email !== '') {
            $id = $this->repo->clientePorEmail($email);
            if ($id) {
                return $id;
            }
        }
        if ($tel !== '') {
            $id = $this->repo->clientePorTelefono($tel);
            if ($id) {
                return $id;
            }
        }
        return $this->repo->insertarCliente($nombre, $email === '' ? null : $email, $tel === '' ? null : $tel);
    }

    /** Segmento de un cliente según sus compras entregadas. */
    public static function segmento(array $c): string
    {
        $pedidos = (int)($c['total_pedidos'] ?? 0);
        $gasto = (int)($c['total_gastado'] ?? 0);
        $ultima = $c['ultima_compra'] ?? null;

        if ($pedidos === 0) {
            return 'sin_compras';
        }
        if ($pedidos >= 5 || $gasto >= 5000000) {
            return 'vip';
        }
        if ($pedidos >= 2) {
            return 'recurrente';
        }
        if ($pedidos === 1) {
            return 'nuevo';
        }
        if ($ultima && strtotime((string)$ultima) < strtotime('-90 days')) {
            return 'inactivo';
        }
        return 'nuevo';
    }

    /* ------------------------------- clientes ------------------------------- */

    public function clientes(array $g): array
    {
        $q      = self::s($g['q'] ?? '', 80);
        $segmento = self::s($g['segmento'] ?? '', 30);
        $pagina = max(1, (int)($g['pagina'] ?? 1));
        $limite = min(100, max(1, (int)($g['limite'] ?? 20)));

        $where = ['1=1'];
        $args = [];
        if ($q !== '') {
            $where[] = '(c.nombre LIKE ? OR c.email LIKE ? OR c.telefono LIKE ? OR c.documento LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like, $like);
        }
        $w = implode(' AND ', $where);

        $total = $this->repo->contarClientes($w, $args);
        $offset = ($pagina - 1) * $limite;
        $items = array_map(fn($x) => [
            'id' => (int)$x['id'], 'nombre' => (string)$x['nombre'], 'email' => $x['email'],
            'telefono' => $x['telefono'], 'documento' => $x['documento'],
            'total_pedidos' => (int)$x['total_pedidos'], 'total_gastado' => (int)$x['total_gastado'],
            'ultima_compra' => $x['ultima_compra'], 'segmento' => self::segmento($x),
        ], $this->repo->listarClientes($w, $args, $limite, $offset));

        if ($segmento !== '') {
            $items = array_values(array_filter($items, fn($c) => $c['segmento'] === $segmento));
            $total = count($items);
        }

        return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'limite' => $limite, 'paginas' => max(1, (int)ceil($total / $limite))];
    }

    public function clienteDetalle(int $id): array
    {
        $c = $this->repo->cliente($id);
        if ($c === null) {
            throw new UserError('Cliente no encontrado');
        }

        $direcciones = array_map(fn($x) => [
            'id' => (int)$x['id'], 'alias' => (string)$x['alias'], 'destinatario' => (string)$x['destinatario'],
            'telefono' => (string)$x['telefono'], 'departamento' => (string)$x['departamento'],
            'ciudad' => (string)$x['ciudad'], 'direccion' => (string)$x['direccion'],
            'indicaciones' => (string)$x['indicaciones'], 'principal' => (bool)$x['principal'],
        ], $this->repo->direccionesDe($id));

        $pedidos = array_map(fn($x) => ['id' => (int)$x['id'], 'estado' => (string)$x['estado'], 'total' => (int)$x['total'], 'creado' => (string)$x['creado']], $this->repo->pedidosDe($id));
        $devoluciones = array_map(fn($x) => ['id' => (int)$x['id'], 'estado' => (string)$x['estado'], 'monto' => (int)$x['monto'], 'creado' => (string)$x['creado']], $this->repo->devolucionesDeCliente($id));

        return [
            'cliente' => [
                'id' => (int)$c['id'], 'nombre' => (string)$c['nombre'], 'email' => $c['email'],
                'telefono' => $c['telefono'], 'tipo_doc' => (string)$c['tipo_doc'], 'documento' => $c['documento'],
                'notas' => $c['notas'], 'acepta_marketing' => (bool)$c['acepta_marketing'],
                'total_pedidos' => (int)$c['total_pedidos'], 'total_gastado' => (int)$c['total_gastado'],
                'primera_compra' => $c['primera_compra'], 'ultima_compra' => $c['ultima_compra'],
                'segmento' => self::segmento($c), 'creado' => (string)$c['creado'],
            ],
            'direcciones' => $direcciones,
            'pedidos' => $pedidos,
            'devoluciones' => $devoluciones,
        ];
    }

    public function clienteGuardar(array $b): array
    {
        $d = $b['data'] ?? [];
        $id = (int)($d['id'] ?? 0);
        $nombre = self::s($d['nombre'] ?? '', 100);
        $email = strtolower(self::s($d['email'] ?? '', 120));
        $tel = preg_replace('/\D+/', '', self::s($d['telefono'] ?? '', 20));
        $notas = ($d['notas'] ?? '') === '' ? null : mb_substr(trim((string)$d['notas']), 0, 4000);
        $acepta = !empty($d['acepta_marketing']) ? 1 : 0;
        $tipoDoc = in_array($d['tipo_doc'] ?? '', ['', 'CC', 'CE', 'NIT', 'PP'], true) ? (string)($d['tipo_doc'] ?? '') : '';
        $documento = self::s($d['documento'] ?? '', 20);

        self::req($nombre !== '', 'Escribe el nombre');
        self::req($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'Correo no válido');

        $this->repo->actualizarCliente($id, $nombre, $email === '' ? null : $email, $tel === '' ? null : $tel, $tipoDoc, $documento === '' ? null : $documento, $notas, $acepta);

        auditar('cliente.editar', 'clientes', $id, ['nombre' => $nombre, 'email' => $email]);
        return ['ok' => true];
    }

    public function clienteDireccion(array $b): array
    {
        $d = $b['data'] ?? [];
        $clienteId = (int)($d['cliente_id'] ?? 0);
        $id = (int)($d['id'] ?? 0);
        $alias = self::s($d['alias'] ?? '', 40);
        $dest = self::s($d['destinatario'] ?? '', 100);
        $tel = self::s($d['telefono'] ?? '', 20);
        $dep = self::s($d['departamento'] ?? '', 40);
        $ciudad = self::s($d['ciudad'] ?? '', 60);
        $dir = self::s($d['direccion'] ?? '', 200);
        $ind = self::s($d['indicaciones'] ?? '', 200);
        $principal = !empty($d['principal']) ? 1 : 0;

        self::req($clienteId > 0 && $dest !== '' && $dir !== '', 'Destinatario y dirección son obligatorios');

        $this->pdo->beginTransaction();
        try {
            if ($principal) {
                $this->repo->quitarPrincipalDirecciones($clienteId);
            }
            if ($id) {
                $this->repo->actualizarDireccion($id, $clienteId, $alias, $dest, $tel, $dep, $ciudad, $dir, $ind, $principal);
            } else {
                $this->repo->insertarDireccion($clienteId, $alias, $dest, $tel, $dep, $ciudad, $dir, $ind, $principal);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['ok' => true];
    }

    public function clienteFusionar(array $b): array
    {
        $origen = (int)($b['origen'] ?? 0);
        $destino = (int)($b['destino'] ?? 0);
        self::req($origen > 0 && $destino > 0 && $origen !== $destino, 'Cliente origen y destino son obligatorios');

        $this->pdo->beginTransaction();
        try {
            $this->repo->fusionarClientes($origen, $destino);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        auditar('cliente.fusionar', 'clientes', $destino, ['origen' => $origen]);
        return ['ok' => true];
    }

    /* ------------------------------ devoluciones ---------------------------- */

    public function devoluciones(array $g): array
    {
        $estado = self::s($g['estado'] ?? '', 30);
        $pagina = max(1, (int)($g['pagina'] ?? 1));
        $limite = min(100, max(1, (int)($g['limite'] ?? 20)));

        $where = ['1=1'];
        $args = [];
        if ($estado !== '') {
            $where[] = 'd.estado = ?';
            $args[] = $estado;
        }
        $w = implode(' AND ', $where);

        $total = $this->repo->contarDevoluciones($w, $args);
        $offset = ($pagina - 1) * $limite;
        $items = array_map(fn($x) => [
            'id' => (int)$x['id'], 'pedido_id' => (int)$x['pedido_id'], 'cliente' => (string)$x['cliente_nombre'],
            'motivo' => (string)$x['motivo'], 'estado' => (string)$x['estado'], 'monto' => (int)$x['monto'],
            'creado' => (string)$x['creado'],
        ], $this->repo->listarDevoluciones($w, $args, $limite, $offset));

        return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'limite' => $limite, 'paginas' => max(1, (int)ceil($total / $limite))];
    }

    public function devolucionCrear(array $b): array
    {
        $pedidoId = (int)($b['pedido_id'] ?? 0);
        $motivo = self::s($b['motivo'] ?? '', 30);
        $descripcion = self::s($b['descripcion'] ?? '', 500);
        $items = $b['items'] ?? [];

        self::req(in_array($motivo, ['talla', 'defecto', 'no_corresponde', 'arrepentimiento', 'otro'], true), 'Motivo no válido');
        self::req(is_array($items) && count($items) > 0, 'Indica al menos un ítem');

        $this->pdo->beginTransaction();
        try {
            $p = $this->repo->pedidoBloqueado($pedidoId);
            if ($p === null) {
                throw new UserError('Pedido no encontrado');
            }
            if (!in_array($p['estado'], ['enviado', 'entregado'], true)) {
                throw new UserError('Solo se puede devolver un pedido enviado o entregado');
            }

            $devId = $this->repo->insertarDevolucion($pedidoId, $motivo, $descripcion, $this->uid());

            $monto = 0;
            foreach ($items as $it) {
                $itemId = (int)($it['pedido_item_id'] ?? 0);
                $cant = (int)($it['cantidad'] ?? 0);
                $accion = ($it['accion'] ?? 'reembolso') === 'cambio' ? 'cambio' : 'reembolso';
                $reingresa = !empty($it['reingresa_stock']) ? 1 : 0;

                $item = $this->repo->pedidoItem($itemId, $pedidoId);
                if ($item === null) {
                    throw new UserError('Ítem no válido');
                }
                $yaDevuelto = $this->repo->yaDevuelto($itemId);
                self::req($cant >= 1 && ($cant + $yaDevuelto) <= (int)$item['cantidad'], 'Cantidad a devolver no válida');

                $this->repo->insertarDevolucionItem($devId, $itemId, $cant, $accion, $reingresa);
                $monto += (int)$item['precio'] * $cant;
            }
            $this->repo->fijarMontoDevolucion($devId, $monto);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        auditar('devolucion.crear', 'devoluciones', $devId, ['pedido' => $pedidoId, 'motivo' => $motivo]);
        return ['ok' => true, 'id' => $devId];
    }

    public function devolucionTransicion(array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        $hacia = self::s($b['estado'] ?? '', 30);

        $T = [
            'solicitada' => ['aprobada' => true, 'rechazada' => true],
            'aprobada'   => ['recibida' => true],
            'recibida'   => ['reembolsada' => true],
        ];

        $this->pdo->beginTransaction();
        try {
            $d = $this->repo->devolucionBloqueada($id);
            if ($d === null) {
                throw new UserError('Devolución no encontrada');
            }
            $desde = (string)$d['estado'];
            if (empty($T[$desde][$hacia])) {
                throw new UserError("No se puede pasar de '$desde' a '$hacia'");
            }

            if ($hacia === 'recibida') {
                foreach ($this->repo->devolucionItems($id) as $it) {
                    if (!(int)$it['reingresa_stock']) {
                        continue;
                    }
                    $vid = $this->repo->varianteDePedidoItem((int)$it['pedido_item_id']);
                    if (!$vid) {
                        continue;
                    }
                    $cant = (int)$it['cantidad'];
                    $this->repo->sumarStock($vid, $cant);
                    $st = $this->repo->stockDe($vid);
                    $this->repo->insertarMovimientoDevolucion($vid, $cant, $st, 'Devolución #' . $id, $id, $this->uid());
                }
            }

            if ($hacia === 'reembolsada') {
                $this->repo->reembolsarPagos((int)$d['pedido_id']);
                $this->repo->marcarPedidoDevuelto((int)$d['pedido_id']);
                $this->repo->insertarEventoPedido((int)$d['pedido_id'], 'entregado', 'devuelto', 'RMA reembolsada #' . $id, $this->uid());
            }

            $this->repo->fijarEstadoDevolucion($id, $hacia);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        auditar('devolucion.estado', 'devoluciones', $id, ['antes' => $desde, 'despues' => $hacia]);
        return ['ok' => true, 'estado' => $hacia];
    }
}
