<?php
declare(strict_types=1);
/*
 * Acceso a datos de CRM (PDO preparado). Único lugar de este dominio donde se
 * escribe SQL.
 */
namespace App\Repositories;

use App\Repositories\Contracts\CrmRepositoryInterface;
use PDO;

final class CrmRepository implements CrmRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function clientePorEmail(string $email): int
    {
        $q = $this->pdo->prepare('SELECT id FROM clientes WHERE email = ?');
        $q->execute([$email]);
        return (int)$q->fetchColumn();
    }

    public function clientePorTelefono(string $tel): int
    {
        $q = $this->pdo->prepare('SELECT id FROM clientes WHERE telefono = ?');
        $q->execute([$tel]);
        return (int)$q->fetchColumn();
    }

    public function insertarCliente(string $nombre, ?string $email, ?string $tel): int
    {
        $this->pdo->prepare('INSERT INTO clientes (nombre, email, telefono) VALUES (?,?,?)')
            ->execute([$nombre, $email, $tel]);
        return (int)$this->pdo->lastInsertId();
    }

    public function contarClientes(string $where, array $args): int
    {
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM clientes c WHERE $where");
        $q->execute($args);
        return (int)$q->fetchColumn();
    }

    public function listarClientes(string $where, array $args, int $limite, int $offset): array
    {
        $sql = "SELECT c.* FROM clientes c WHERE $where ORDER BY c.ultima_compra DESC, c.id DESC LIMIT $limite OFFSET $offset";
        $q = $this->pdo->prepare($sql);
        $q->execute($args);
        return $q->fetchAll();
    }

    public function cliente(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM clientes WHERE id = ?');
        $q->execute([$id]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function direccionesDe(int $clienteId): array
    {
        $q = $this->pdo->prepare('SELECT * FROM cliente_direcciones WHERE cliente_id = ? ORDER BY principal DESC, id');
        $q->execute([$clienteId]);
        return $q->fetchAll();
    }

    public function pedidosDe(int $clienteId): array
    {
        $q = $this->pdo->prepare('SELECT * FROM pedidos WHERE cliente_id = ? ORDER BY id DESC LIMIT 100');
        $q->execute([$clienteId]);
        return $q->fetchAll();
    }

    public function devolucionesDeCliente(int $clienteId): array
    {
        $q = $this->pdo->prepare('SELECT d.*, p.total AS pedido_total FROM devoluciones d JOIN pedidos p ON p.id = d.pedido_id WHERE p.cliente_id = ? ORDER BY d.id DESC');
        $q->execute([$clienteId]);
        return $q->fetchAll();
    }

    public function actualizarCliente(int $id, string $nombre, ?string $email, ?string $tel, string $tipoDoc, ?string $documento, ?string $notas, int $acepta): void
    {
        $this->pdo->prepare('UPDATE clientes SET nombre=?, email=?, telefono=?, tipo_doc=?, documento=?, notas=?, acepta_marketing=? WHERE id=?')
            ->execute([$nombre, $email, $tel, $tipoDoc, $documento, $notas, $acepta, $id]);
    }

    public function quitarPrincipalDirecciones(int $clienteId): void
    {
        $this->pdo->prepare('UPDATE cliente_direcciones SET principal = 0 WHERE cliente_id = ?')->execute([$clienteId]);
    }

    public function insertarDireccion(int $clienteId, string $alias, string $dest, string $tel, string $dep, string $ciudad, string $dir, string $ind, int $principal): void
    {
        $this->pdo->prepare('INSERT INTO cliente_direcciones (cliente_id, alias, destinatario, telefono, departamento, ciudad, direccion, indicaciones, principal) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$clienteId, $alias, $dest, $tel, $dep, $ciudad, $dir, $ind, $principal]);
    }

    public function actualizarDireccion(int $id, int $clienteId, string $alias, string $dest, string $tel, string $dep, string $ciudad, string $dir, string $ind, int $principal): void
    {
        $this->pdo->prepare('UPDATE cliente_direcciones SET alias=?, destinatario=?, telefono=?, departamento=?, ciudad=?, direccion=?, indicaciones=?, principal=? WHERE id=? AND cliente_id=?')
            ->execute([$alias, $dest, $tel, $dep, $ciudad, $dir, $ind, $principal, $id, $clienteId]);
    }

    public function fusionarClientes(int $origen, int $destino): void
    {
        $this->pdo->prepare('UPDATE pedidos SET cliente_id = ? WHERE cliente_id = ?')->execute([$destino, $origen]);
        $this->pdo->prepare('UPDATE cliente_direcciones SET cliente_id = ? WHERE cliente_id = ?')->execute([$destino, $origen]);
        $this->pdo->prepare('UPDATE clientes SET activo = 0 WHERE id = ?')->execute([$origen]);
        $this->pdo->prepare('UPDATE clientes SET total_pedidos=(SELECT COUNT(*) FROM pedidos WHERE cliente_id=? AND estado="entregado"),
                       total_gastado=(SELECT COALESCE(SUM(total),0) FROM pedidos WHERE cliente_id=? AND estado="entregado"),
                       primera_compra=(SELECT MIN(creado) FROM pedidos WHERE cliente_id=? AND estado="entregado"),
                       ultima_compra=(SELECT MAX(creado) FROM pedidos WHERE cliente_id=? AND estado="entregado") WHERE id=?')
            ->execute([$destino, $destino, $destino, $destino, $destino]);
    }

    public function contarDevoluciones(string $where, array $args): int
    {
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM devoluciones d JOIN pedidos p ON p.id=d.pedido_id WHERE $where");
        $q->execute($args);
        return (int)$q->fetchColumn();
    }

    public function listarDevoluciones(string $where, array $args, int $limite, int $offset): array
    {
        $sql = "SELECT d.*, p.id AS pedido_num, p.cliente_nombre, p.total AS pedido_total
                  FROM devoluciones d JOIN pedidos p ON p.id = d.pedido_id
                 WHERE $where ORDER BY d.id DESC LIMIT $limite OFFSET $offset";
        $q = $this->pdo->prepare($sql);
        $q->execute($args);
        return $q->fetchAll();
    }

    public function pedidoBloqueado(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM pedidos WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function insertarDevolucion(int $pedidoId, string $motivo, string $descripcion, ?int $usuarioId): int
    {
        $this->pdo->prepare('INSERT INTO devoluciones (pedido_id, motivo, descripcion, estado, monto, usuario_id) VALUES (?,?,?,?,0,?)')
            ->execute([$pedidoId, $motivo, $descripcion, 'solicitada', $usuarioId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function pedidoItem(int $itemId, int $pedidoId): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM pedido_items WHERE id = ? AND pedido_id = ?');
        $q->execute([$itemId, $pedidoId]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function yaDevuelto(int $itemId): int
    {
        $q = $this->pdo->prepare('SELECT COALESCE(SUM(cantidad),0) FROM devolucion_items WHERE pedido_item_id = ?');
        $q->execute([$itemId]);
        return (int)$q->fetchColumn();
    }

    public function insertarDevolucionItem(int $devId, int $itemId, int $cant, string $accion, int $reingresa): void
    {
        $this->pdo->prepare('INSERT INTO devolucion_items (devolucion_id, pedido_item_id, cantidad, accion, reingresa_stock) VALUES (?,?,?,?,?)')
            ->execute([$devId, $itemId, $cant, $accion, $reingresa]);
    }

    public function fijarMontoDevolucion(int $devId, int $monto): void
    {
        $this->pdo->prepare('UPDATE devoluciones SET monto = ? WHERE id = ?')->execute([$monto, $devId]);
    }

    public function devolucionBloqueada(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM devoluciones WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function devolucionItems(int $devId): array
    {
        $q = $this->pdo->prepare('SELECT * FROM devolucion_items WHERE devolucion_id = ?');
        $q->execute([$devId]);
        return $q->fetchAll();
    }

    public function varianteDePedidoItem(int $itemId): int
    {
        $q = $this->pdo->prepare('SELECT variante_id FROM pedido_items WHERE id = ?');
        $q->execute([$itemId]);
        return (int)$q->fetchColumn();
    }

    public function sumarStock(int $vid, int $cant): void
    {
        $this->pdo->prepare('UPDATE variantes SET stock = stock + ? WHERE id = ?')->execute([$cant, $vid]);
    }

    public function stockDe(int $vid): int
    {
        $q = $this->pdo->prepare('SELECT stock FROM variantes WHERE id = ?');
        $q->execute([$vid]);
        return (int)$q->fetchColumn();
    }

    public function insertarMovimientoDevolucion(int $vid, int $cant, int $stockDespues, string $motivo, int $refId, ?int $usuarioId): void
    {
        $this->pdo->prepare('INSERT INTO movimientos_stock (variante_id, tipo, cantidad, stock_despues, motivo, ref_tipo, ref_id, usuario_id) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$vid, 'devolucion', $cant, $stockDespues, $motivo, 'devolucion', $refId, $usuarioId]);
    }

    public function reembolsarPagos(int $pedidoId): void
    {
        $this->pdo->prepare("UPDATE pagos SET estado = 'reembolsado' WHERE pedido_id = ?")->execute([$pedidoId]);
    }

    public function marcarPedidoDevuelto(int $pedidoId): void
    {
        $this->pdo->prepare("UPDATE pedidos SET estado = 'devuelto', pago_estado = 'reembolsado' WHERE id = ?")->execute([$pedidoId]);
    }

    public function insertarEventoPedido(int $pedidoId, string $antes, string $despues, string $nota, ?int $usuarioId): void
    {
        $this->pdo->prepare('INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota, usuario_id) VALUES (?,?,?,?,?)')
            ->execute([$pedidoId, $antes, $despues, $nota, $usuarioId]);
    }

    public function fijarEstadoDevolucion(int $id, string $estado): void
    {
        $this->pdo->prepare('UPDATE devoluciones SET estado = ? WHERE id = ?')->execute([$estado, $id]);
    }
}
