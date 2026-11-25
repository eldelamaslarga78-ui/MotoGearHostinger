<?php
declare(strict_types=1);
/*
 * Acceso a datos de marketing (PDO preparado). Único lugar de este dominio donde
 * se escribe SQL.
 */
namespace App\Repositories;

use App\Repositories\Contracts\MarketingRepositoryInterface;
use PDO;

final class MarketingRepository implements MarketingRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function cupon(string $codigo): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM cupones WHERE codigo = ?');
        $q->execute([$codigo]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function usosPorCliente(string $codigo, int $clienteId): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM cupon_usos WHERE cupon_codigo = ? AND pedido_id IN (SELECT id FROM pedidos WHERE cliente_id = ?)');
        $q->execute([$codigo, $clienteId]);
        return (int)$q->fetchColumn();
    }

    public function promocionesActivas(): array
    {
        return $this->pdo->query('SELECT * FROM promociones WHERE activa = 1 AND (desde IS NULL OR desde <= NOW()) AND (hasta IS NULL OR hasta >= NOW()) ORDER BY prioridad, id')->fetchAll();
    }

    public function promocionItems(int $promoId): array
    {
        $q = $this->pdo->prepare('SELECT producto_id, categoria_id FROM promocion_items WHERE promocion_id = ?');
        $q->execute([$promoId]);
        return $q->fetchAll();
    }

    public function promociones(): array
    {
        return $this->pdo->query('SELECT * FROM promociones ORDER BY prioridad, id')->fetchAll();
    }

    public function promocionNombre(int $id): ?string
    {
        $q = $this->pdo->prepare('SELECT nombre FROM promociones WHERE id = ?');
        $q->execute([$id]);
        $v = $q->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    public function borrarPromocion(int $id): void
    {
        $this->pdo->prepare('DELETE FROM promociones WHERE id = ?')->execute([$id]);
    }

    public function insertarPromocion(array $v): int
    {
        $this->pdo->prepare('INSERT INTO promociones (nombre, tipo, prioridad, desde, hasta, condiciones, beneficios, activa, exclusiva) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute($v);
        return (int)$this->pdo->lastInsertId();
    }

    public function actualizarPromocion(array $v, int $id): void
    {
        $this->pdo->prepare('UPDATE promociones SET nombre=?, tipo=?, prioridad=?, desde=?, hasta=?, condiciones=?, beneficios=?, activa=?, exclusiva=? WHERE id=?')
            ->execute(array_merge($v, [$id]));
    }

    public function borrarPromocionItems(int $id): void
    {
        $this->pdo->prepare('DELETE FROM promocion_items WHERE promocion_id = ?')->execute([$id]);
    }

    public function insertarPromocionItem(int $promoId, ?int $productoId, ?string $categoriaId): void
    {
        $this->pdo->prepare('INSERT INTO promocion_items (promocion_id, producto_id, categoria_id) VALUES (?,?,?)')
            ->execute([$promoId, $productoId, $categoriaId]);
    }

    public function carritoPorToken(string $token): int
    {
        $q = $this->pdo->prepare('SELECT id FROM carritos WHERE token = ?');
        $q->execute([$token]);
        return (int)$q->fetchColumn();
    }

    public function insertarCarrito(string $token, ?int $clienteId, string $email, ?string $telefono): int
    {
        $this->pdo->prepare('INSERT INTO carritos (token, cliente_id, email, telefono) VALUES (?,?,?,?)')
            ->execute([$token, $clienteId, $email, $telefono]);
        return (int)$this->pdo->lastInsertId();
    }

    public function actualizarCarrito(int $id, string $email, ?string $telefono): void
    {
        $this->pdo->prepare('UPDATE carritos SET email = ?, telefono = ?, ultima_actividad = NOW() WHERE id = ?')
            ->execute([$email, $telefono, $id]);
    }

    public function borrarCarritoItems(int $id): void
    {
        $this->pdo->prepare('DELETE FROM carrito_items WHERE carrito_id = ?')->execute([$id]);
    }

    public function variantePrecio(int $vid): ?array
    {
        $q = $this->pdo->prepare('SELECT v.precio, p.precio AS base FROM variantes v JOIN productos p ON p.id = v.producto_id WHERE v.id = ?');
        $q->execute([$vid]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function insertarCarritoItem(int $carritoId, int $vid, int $cant, int $precio): void
    {
        $this->pdo->prepare('INSERT INTO carrito_items (carrito_id, variante_id, cantidad, precio) VALUES (?,?,?,?)')
            ->execute([$carritoId, $vid, $cant, $precio]);
    }

    public function fijarSubtotal(int $id, int $subtotal): void
    {
        $this->pdo->prepare('UPDATE carritos SET subtotal = ? WHERE id = ?')->execute([$subtotal, $id]);
    }

    public function contarCarritosAbandonados(): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM carritos WHERE estado = "abandonado"');
        $q->execute();
        return (int)$q->fetchColumn();
    }

    public function listarCarritosAbandonados(int $limite, int $offset): array
    {
        $q = $this->pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM carrito_items i WHERE i.carrito_id = c.id) AS n_items
                                   FROM carritos c WHERE c.estado = "abandonado" ORDER BY c.ultima_actividad DESC LIMIT ' . (int)$limite . ' OFFSET ' . (int)$offset);
        $q->execute();
        return $q->fetchAll();
    }

    public function carrito(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM carritos WHERE id = ?');
        $q->execute([$id]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function marcarCarritoRecuperado(int $id): void
    {
        $this->pdo->prepare('UPDATE carritos SET estado = "recuperado", recordatorios = recordatorios + 1 WHERE id = ?')->execute([$id]);
    }

    public function resumenCarritos(): array
    {
        $q = $this->pdo->query('SELECT estado, COUNT(*) AS n, COALESCE(SUM(subtotal),0) AS monto FROM carritos GROUP BY estado');
        $porEstado = [];
        foreach ($q->fetchAll() as $r) {
            $porEstado[(string)$r['estado']] = ['n' => (int)$r['n'], 'monto' => (int)$r['monto']];
        }
        return $porEstado;
    }

    public function marcarAbandonos(int $horas): int
    {
        return $this->pdo->exec("UPDATE carritos SET estado = 'abandonado' WHERE estado = 'activo' AND email <> '' AND ultima_actividad < NOW() - INTERVAL $horas HOUR");
    }

    public function carritosParaRecordatorio(int $horas): array
    {
        $q = $this->pdo->prepare("SELECT * FROM carritos WHERE estado = 'abandonado' AND recordatorios = 0 AND email <> '' AND ultima_actividad < NOW() - INTERVAL $horas HOUR");
        $q->execute();
        return $q->fetchAll();
    }

    public function marcarRecordatorio(int $id): void
    {
        $this->pdo->prepare('UPDATE carritos SET recordatorios = 1 WHERE id = ?')->execute([$id]);
    }

    public function clientePorEmail(string $email): int
    {
        $q = $this->pdo->prepare('SELECT id FROM clientes WHERE email = ?');
        $q->execute([$email]);
        return (int)$q->fetchColumn();
    }

    public function pedidosEntregadosDe(int $clienteId): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM pedidos WHERE cliente_id = ? AND estado = "entregado"');
        $q->execute([$clienteId]);
        return (int)$q->fetchColumn();
    }
}
