<?php
declare(strict_types=1);
/*
 * Acceso a datos de pedidos (PDO preparado). Único lugar de este dominio donde
 * se escribe SQL.
 */
namespace App\Repositories;

use App\Repositories\Contracts\PedidoRepositoryInterface;
use PDO;

final class PedidoRepository implements PedidoRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function contar(string $where, array $args): int
    {
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM pedidos p WHERE $where");
        $q->execute($args);
        return (int)$q->fetchColumn();
    }

    public function listar(string $where, array $args, int $limite, int $offset): array
    {
        $sql = "SELECT p.id, p.cliente_nombre, p.cliente_email, p.cliente_tel, p.envio_ciudad,
                       p.total, p.estado, p.pago_estado, p.metodo_pago, p.creado,
                       (SELECT COUNT(*) FROM pedido_items i WHERE i.pedido_id = p.id) AS n_items
                  FROM pedidos p
                 WHERE $where
                 ORDER BY p.id DESC
                 LIMIT $limite OFFSET $offset";
        $q = $this->pdo->prepare($sql);
        $q->execute($args);
        return $q->fetchAll();
    }

    public function pedido(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM pedidos WHERE id = ?');
        $q->execute([$id]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function itemsDe(int $id): array
    {
        $q = $this->pdo->prepare('SELECT * FROM pedido_items WHERE pedido_id = ? ORDER BY id');
        $q->execute([$id]);
        return $q->fetchAll();
    }

    public function pagosDe(int $id): array
    {
        $q = $this->pdo->prepare('SELECT * FROM pagos WHERE pedido_id = ? ORDER BY id DESC');
        $q->execute([$id]);
        return $q->fetchAll();
    }

    public function envioDe(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT e.*, t.nombre AS transportadora, t.tracking_url FROM envios e LEFT JOIN transportadoras t ON t.id = e.transportadora_id WHERE e.pedido_id = ?');
        $q->execute([$id]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function facturaDe(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM facturas WHERE pedido_id = ?');
        $q->execute([$id]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function eventosDe(int $id): array
    {
        $q = $this->pdo->prepare('SELECT e.*, u.nombre AS usuario FROM pedido_eventos e LEFT JOIN usuarios u ON u.id = e.usuario_id WHERE e.pedido_id = ? ORDER BY e.id');
        $q->execute([$id]);
        return $q->fetchAll();
    }

    public function transportadorasActivas(): array
    {
        $q = $this->pdo->prepare('SELECT * FROM transportadoras WHERE activo = 1 ORDER BY nombre');
        $q->execute();
        return $q->fetchAll();
    }

    public function pedidoBloqueado(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM pedidos WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function estadoDe(int $id): ?string
    {
        $q = $this->pdo->prepare('SELECT estado FROM pedidos WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $v = $q->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    public function devolverStock(int $pedidoId, ?int $usuarioId): void
    {
        $q = $this->pdo->prepare('SELECT variante_id, producto_id, talla, cantidad FROM pedido_items WHERE pedido_id = ?');
        $q->execute([$pedidoId]);
        $items = $q->fetchAll();

        $stock = $this->pdo->prepare('SELECT stock FROM variantes WHERE id = ?');
        $busca = $this->pdo->prepare("SELECT id FROM variantes WHERE producto_id = ? AND talla = ? ORDER BY (color='') DESC, id LIMIT 1");
        $suma  = $this->pdo->prepare('UPDATE variantes SET stock = stock + ? WHERE id = ?');
        $mov   = $this->pdo->prepare('INSERT INTO movimientos_stock (variante_id, tipo, cantidad, stock_despues, motivo, ref_tipo, ref_id, usuario_id) VALUES (?,?,?,?,?,?,?,?)');

        foreach ($items as $it) {
            $vid = (int)($it['variante_id'] ?? 0);
            if (!$vid) {
                $busca->execute([(int)$it['producto_id'], (string)$it['talla']]);
                $vid = (int)$busca->fetchColumn();
            }
            if (!$vid) {
                continue;
            }
            $cant = (int)$it['cantidad'];
            $suma->execute([$cant, $vid]);
            $stock->execute([$vid]);
            $mov->execute([$vid, 'devolucion', $cant, (int)$stock->fetchColumn(), 'Cancelación pedido #' . $pedidoId, 'cancelacion', $pedidoId, $usuarioId]);
        }
    }

    public function revertirCupon(string $codigo): void
    {
        $this->pdo->prepare('UPDATE cupones SET usos = GREATEST(usos - 1, 0) WHERE codigo = ?')->execute([$codigo]);
    }

    public function marcarEntregado(int $id): void
    {
        $this->pdo->prepare('UPDATE pedidos SET entregado_en = NOW() WHERE id = ?')->execute([$id]);
    }

    public function fijarEstado(int $id, string $estado): void
    {
        $this->pdo->prepare('UPDATE pedidos SET estado = ? WHERE id = ?')->execute([$estado, $id]);
    }

    public function insertarEvento(int $pedidoId, ?string $antes, string $despues, ?string $nota, ?int $usuarioId): void
    {
        $this->pdo->prepare('INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota, usuario_id) VALUES (?,?,?,?,?)')
            ->execute([$pedidoId, $antes, $despues, $nota, $usuarioId]);
    }

    public function insertarPago(int $pedidoId, string $pasarela, ?string $referencia, string $estado, int $monto): void
    {
        $this->pdo->prepare('INSERT INTO pagos (pedido_id, pasarela, referencia_externa, estado, monto) VALUES (?,?,?,?,?)')
            ->execute([$pedidoId, $pasarela, $referencia, $estado, $monto]);
    }

    public function fijarPago(int $id, string $metodo): void
    {
        $this->pdo->prepare('UPDATE pedidos SET pago_estado = ?, metodo_pago = ?, estado = ? WHERE id = ?')
            ->execute(['pagado', $metodo, 'confirmado', $id]);
    }

    public function fijarNota(int $id, ?string $nota): void
    {
        $this->pdo->prepare('UPDATE pedidos SET notas_internas = ? WHERE id = ?')->execute([$nota, $id]);
    }

    public function upsertEnvio(int $id, int $transportadora, string $guia): void
    {
        $this->pdo->prepare("INSERT INTO envios (pedido_id, transportadora_id, guia, estado, fecha_despacho) VALUES (?,?,?,'generada',NOW())
                             ON DUPLICATE KEY UPDATE transportadora_id = VALUES(transportadora_id), guia = VALUES(guia), estado = 'generada', fecha_despacho = NOW()")
            ->execute([$id, $transportadora, $guia]);
    }

    public function consecutivoFactura(): int
    {
        $q = $this->pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'factura_consecutivo' FOR UPDATE");
        $q->execute();
        return (int)$q->fetchColumn();
    }

    public function fijarConsecutivo(int $n): void
    {
        $this->pdo->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'factura_consecutivo'")->execute([(string)$n]);
    }

    public function insertarFactura(int $pedidoId, int $numero, string $completo, int $subtotal, int $descuento, int $impuestos, int $total): void
    {
        $this->pdo->prepare('INSERT INTO facturas (pedido_id, numero, numero_completo, subtotal, descuento, impuestos, total) VALUES (?,?,?,?,?,?,?)')
            ->execute([$pedidoId, $numero, $completo, $subtotal, $descuento, $impuestos, $total]);
    }

    public function transportadoras(): array
    {
        return $this->pdo->query('SELECT * FROM transportadoras ORDER BY nombre')->fetchAll();
    }

    public function insertarTransportadora(string $nombre, string $url, int $activo): void
    {
        $this->pdo->prepare('INSERT INTO transportadoras (nombre, tracking_url, activo) VALUES (?,?,?)')->execute([$nombre, $url, $activo]);
    }

    public function actualizarTransportadora(int $id, string $nombre, string $url, int $activo): void
    {
        $this->pdo->prepare('UPDATE transportadoras SET nombre = ?, tracking_url = ?, activo = ? WHERE id = ?')->execute([$nombre, $url, $activo, $id]);
    }

    public function pendientesVencidos(): array
    {
        $q = $this->pdo->prepare("SELECT id FROM pedidos WHERE estado = 'pendiente_pago' AND expira_pago IS NOT NULL AND expira_pago < NOW()");
        $q->execute();
        return array_column($q->fetchAll(), 'id');
    }
}
