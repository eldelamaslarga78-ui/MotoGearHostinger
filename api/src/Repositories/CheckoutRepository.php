<?php
declare(strict_types=1);
/*
 * Acceso a datos del checkout (PDO preparado). Único lugar de este dominio donde
 * se escribe SQL.
 */
namespace App\Repositories;

use App\Repositories\Contracts\CheckoutRepositoryInterface;
use PDO;

final class CheckoutRepository implements CheckoutRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function cargarCatalogo(array $ids): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $q = $this->pdo->prepare("SELECT * FROM productos WHERE activo=1 AND id IN ($ph) FOR UPDATE");
        $q->execute($ids);
        $P = [];
        foreach ($q->fetchAll() as $row) {
            $P[(int)$row['id']] = $row;
        }

        $q = $this->pdo->prepare("SELECT id, producto_id, talla, color, sku, stock
                                   FROM variantes WHERE activo=1 AND producto_id IN ($ph)
                               ORDER BY (color='') DESC, stock DESC, id FOR UPDATE");
        $q->execute($ids);
        $V = [];
        foreach ($q->fetchAll() as $v) {
            $V[(int)$v['producto_id']][(string)$v['talla']][] = [
                'id' => (int)$v['id'], 'color' => (string)$v['color'],
                'sku' => (string)$v['sku'], 'stock' => (int)$v['stock'],
            ];
        }
        return ['productos' => $P, 'variantes' => $V];
    }

    public function reservarStock(int $pid, array $lineas, array $P, array &$V): void
    {
        $insItem = $this->pdo->prepare('INSERT INTO pedido_items (pedido_id, variante_id, producto_id, nombre, sku, talla, color, precio, cantidad) VALUES (?,?,?,?,?,?,?,?,?)');
        $updVar  = $this->pdo->prepare('UPDATE variantes SET stock = ? WHERE id = ?');
        $insMov  = $this->pdo->prepare('INSERT INTO movimientos_stock (variante_id, tipo, cantidad, stock_despues, motivo, ref_tipo, ref_id) VALUES (?,?,?,?,?,?,?)');

        foreach ($lineas as $l) {
            $restante = $l['qty'];
            foreach ($V[$l['id']][$l['talla']] as $k => $v) {
                if ($restante <= 0) {
                    break;
                }
                if ($v['stock'] <= 0) {
                    continue;
                }
                $take = min($restante, $v['stock']);
                $nuevo = $v['stock'] - $take;
                $updVar->execute([$nuevo, $v['id']]);
                $insMov->execute([$v['id'], 'salida', -$take, $nuevo, 'Pedido #' . $pid, 'pedido', $pid]);
                $insItem->execute([$pid, $v['id'], $l['id'], $P[$l['id']]['nombre'], $v['sku'], $l['talla'], $v['color'], (int)$P[$l['id']]['precio'], $take]);
                $V[$l['id']][$l['talla']][$k]['stock'] = $nuevo;
                $restante -= $take;
            }
            if ($restante !== 0) {
                throw new \App\Core\UserError('Stock insuficiente de ' . $P[$l['id']]['nombre']);
            }
        }
    }

    public function contarPedidosEntregados(int $clienteId): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM pedidos WHERE cliente_id = ? AND estado = "entregado"');
        $q->execute([$clienteId]);
        return (int)$q->fetchColumn();
    }

    public function cuponBloqueado(string $codigo): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM cupones WHERE codigo = ? FOR UPDATE');
        $q->execute([$codigo]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function insertarPedido(array $v): int
    {
        $this->pdo->prepare('INSERT INTO pedidos
                (cliente, telefono, email, ciudad, direccion, notas, subtotal, descuento, cupon, total, ip,
                 cliente_id, cliente_nombre, cliente_email, cliente_tel, envio_ciudad, envio_direccion, notas_cliente, cupon_codigo,
                 estado, pago_estado, metodo_pago, expira_pago, idempotencia)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute($v);
        return (int)$this->pdo->lastInsertId();
    }

    public function insertarEventoInicial(int $pid): void
    {
        $this->pdo->prepare('INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota) VALUES (?,?,?,?)')
            ->execute([$pid, null, 'pendiente_pago', 'Pedido creado desde la tienda']);
    }

    public function registrarCupon(string $codigo): void
    {
        $this->pdo->prepare('UPDATE cupones SET usos = usos + 1 WHERE codigo = ?')->execute([$codigo]);
    }

    public function insertarCuponUso(string $codigo, int $pid, int $monto): void
    {
        $this->pdo->prepare('INSERT INTO cupon_usos (cupon_codigo, pedido_id, monto) VALUES (?,?,?)')
            ->execute([$codigo, $pid, $monto]);
    }

    public function vincularCarrito(string $token, int $pid): void
    {
        $this->pdo->prepare('UPDATE carritos SET estado = "convertido", pedido_id = ? WHERE token = ? AND estado = "activo"')
            ->execute([$pid, $token]);
    }

    public function contarPedidosPorIp(string $ip): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM pedidos WHERE ip=? AND creado > NOW() - INTERVAL 1 HOUR');
        $q->execute([$ip]);
        return (int)$q->fetchColumn();
    }

    public function pedidoPorIdempotencia(string $idem): ?array
    {
        $q = $this->pdo->prepare('SELECT id, total FROM pedidos WHERE idempotencia = ?');
        $q->execute([$idem]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }
}
