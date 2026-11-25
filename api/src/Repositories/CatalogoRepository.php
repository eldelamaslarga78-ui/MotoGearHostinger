<?php
declare(strict_types=1);
/*
 * Acceso a datos del catálogo (PDO preparado). Único lugar de este dominio
 * donde se escribe SQL.
 */
namespace App\Repositories;

use App\Repositories\Contracts\CatalogoRepositoryInterface;
use PDO;

final class CatalogoRepository implements CatalogoRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    /* ------------------------------ categorías ----------------------------- */

    public function categorias(): array
    {
        return $this->pdo->query('SELECT id, nombre, padre_id, orden, activo FROM categorias ORDER BY orden, nombre')->fetchAll();
    }

    public function categoriaExiste(string $id): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM categorias WHERE id = ?');
        $q->execute([$id]);
        return (bool)$q->fetchColumn();
    }

    public function productosDeCategoria(string $id): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM productos WHERE categoria_id = ?');
        $q->execute([$id]);
        return (int)$q->fetchColumn();
    }

    public function subcategoriasDe(string $id): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM categorias WHERE padre_id = ?');
        $q->execute([$id]);
        return (int)$q->fetchColumn();
    }

    public function insertarCategoria(string $id, string $nombre, ?string $padre, int $orden, ?string $icono, int $activo): void
    {
        $this->pdo->prepare('INSERT INTO categorias (id, nombre, padre_id, orden, icono, activo) VALUES (?,?,?,?,?,?)')
            ->execute([$id, $nombre, $padre, $orden, $icono, $activo]);
    }

    public function actualizarCategoria(string $id, string $nombre, ?string $padre, int $orden, ?string $icono, int $activo): void
    {
        $this->pdo->prepare('UPDATE categorias SET nombre = ?, padre_id = ?, orden = ?, icono = ?, activo = ? WHERE id = ?')
            ->execute([$nombre, $padre, $orden, $icono, $activo, $id]);
    }

    public function borrarCategoria(string $id): void
    {
        $this->pdo->prepare('DELETE FROM categorias WHERE id = ?')->execute([$id]);
    }

    /* ------------------------------ productos ------------------------------ */

    public function slugExiste(string $slug, int $excluirId): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM productos WHERE slug = ? AND id <> ? LIMIT 1');
        $q->execute([$slug, $excluirId]);
        return (bool)$q->fetchColumn();
    }

    public function skuExiste(string $sku, int $excluirId): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM productos WHERE sku = ? AND id <> ? LIMIT 1');
        $q->execute([$sku, $excluirId]);
        return (bool)$q->fetchColumn();
    }

    public function producto(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM productos WHERE id = ?');
        $q->execute([$id]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function insertarProducto(array $v): int
    {
        $this->pdo->prepare('INSERT INTO productos
                (sku, nombre, slug, categoria_id, marca, descr, descr_larga,
                 precio, precio_antes, costo, iva_pct, peso_g, alto_mm, ancho_mm, largo_mm,
                 badge, badge_tipo, destacado, activo, meta_titulo, meta_desc, img)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute($v);
        return (int)$this->pdo->lastInsertId();
    }

    public function actualizarProducto(array $v, int $id): void
    {
        $this->pdo->prepare('UPDATE productos SET
                sku=?, nombre=?, slug=?, categoria_id=?, marca=?, descr=?, descr_larga=?,
                precio=?, precio_antes=?, costo=?, iva_pct=?, peso_g=?, alto_mm=?, ancho_mm=?, largo_mm=?,
                badge=?, badge_tipo=?, destacado=?, activo=?, meta_titulo=?, meta_desc=?
            WHERE id=?')
            ->execute(array_merge($v, [$id]));
    }

    public function fijarSkuProducto(int $id, string $sku): void
    {
        $this->pdo->prepare('UPDATE productos SET sku = ? WHERE id = ?')->execute([$sku, $id]);
    }

    public function contarPedidos(int $productoId): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM pedido_items WHERE producto_id = ?');
        $q->execute([$productoId]);
        return (int)$q->fetchColumn();
    }

    public function contarMovimientos(int $productoId): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM movimientos_stock m JOIN variantes v ON v.id = m.variante_id WHERE v.producto_id = ?');
        $q->execute([$productoId]);
        return (int)$q->fetchColumn();
    }

    public function archivarProducto(int $id): void
    {
        $this->pdo->prepare('UPDATE productos SET activo = 0 WHERE id = ?')->execute([$id]);
        $this->pdo->prepare('UPDATE variantes SET activo = 0 WHERE producto_id = ?')->execute([$id]);
    }

    public function borrarProducto(int $id): void
    {
        $this->pdo->prepare('DELETE FROM productos WHERE id = ?')->execute([$id]);
    }

    public function contarProductos(string $where, array $args): int
    {
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM productos p LEFT JOIN categorias c ON c.id=p.categoria_id WHERE $where");
        $q->execute($args);
        return (int)$q->fetchColumn();
    }

    public function listarProductos(string $where, array $args, string $campo, string $dir, int $limite, int $offset): array
    {
        $sql = "SELECT p.id, p.sku, p.nombre, p.slug, p.categoria_id, c.nombre AS categoria,
                       p.marca, p.precio, p.precio_antes, p.destacado, p.activo, p.creado,
                       COALESCE((SELECT i.ruta FROM producto_imagenes i WHERE i.producto_id=p.id ORDER BY i.principal DESC, i.orden, i.id LIMIT 1), p.img) AS img,
                       COALESCE((SELECT SUM(v.stock) FROM variantes v WHERE v.producto_id=p.id AND v.activo=1), 0) AS stock,
                       (SELECT COUNT(*) FROM variantes v WHERE v.producto_id=p.id) AS n_variantes
                  FROM productos p
                  LEFT JOIN categorias c ON c.id = p.categoria_id
                 WHERE $where
                 ORDER BY $campo $dir, p.id DESC
                 LIMIT $limite OFFSET $offset";
        $q = $this->pdo->prepare($sql);
        $q->execute($args);
        return $q->fetchAll();
    }

    /* ------------------------------ variantes ------------------------------ */

    public function variantesDe(int $productoId): array
    {
        $q = $this->pdo->prepare('SELECT id, sku, talla, color, color_hex, precio_extra, stock, stock_minimo, activo
                                    FROM variantes WHERE producto_id = ? ORDER BY FIELD(talla,"XS","S","M","L","XL","XXL","XXXL"), color, id');
        $q->execute([$productoId]);
        return $q->fetchAll();
    }

    public function variante(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM variantes WHERE id = ?');
        $q->execute([$id]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function skuVarianteExiste(string $sku, int $excluirId): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM variantes WHERE sku = ? AND id <> ? LIMIT 1');
        $q->execute([$sku, $excluirId]);
        return (bool)$q->fetchColumn();
    }

    public function combinacionVarianteExiste(int $productoId, string $talla, string $color, int $excluirId): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM variantes WHERE producto_id = ? AND talla = ? AND color = ? AND id <> ? LIMIT 1');
        $q->execute([$productoId, $talla, $color, $excluirId]);
        return (bool)$q->fetchColumn();
    }

    public function insertarVariante(int $productoId, string $sku, string $talla, string $color, ?string $hex): void
    {
        $this->pdo->prepare('INSERT INTO variantes (producto_id, sku, talla, color, color_hex, precio_extra, stock, stock_minimo, activo)
                             VALUES (?,?,?,?,?,0,0,3,1)')
            ->execute([$productoId, $sku, $talla, $color, $hex]);
    }

    public function actualizarVariante(int $id, string $sku, string $talla, string $color, ?string $hex, int $extra, int $minimo, int $activo): void
    {
        $this->pdo->prepare('UPDATE variantes SET sku = ?, talla = ?, color = ?, color_hex = ?, precio_extra = ?, stock_minimo = ?, activo = ? WHERE id = ?')
            ->execute([$sku, $talla, $color, $hex, $extra, $minimo, $activo, $id]);
    }

    public function varianteStock(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT id, stock FROM variantes WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function fijarStock(int $id, int $stock): void
    {
        $this->pdo->prepare('UPDATE variantes SET stock = ? WHERE id = ?')->execute([$stock, $id]);
    }

    public function insertarMovimiento(int $varianteId, string $tipo, int $cantidad, int $stockDespues, string $motivo, string $refTipo, ?int $usuarioId): void
    {
        $this->pdo->prepare('INSERT INTO movimientos_stock (variante_id, tipo, cantidad, stock_despues, motivo, ref_tipo, usuario_id)
                             VALUES (?,?,?,?,?,?,?)')
            ->execute([$varianteId, $tipo, $cantidad, $stockDespues, $motivo, $refTipo, $usuarioId]);
    }

    public function contarInventario(string $where, array $args): int
    {
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM movimientos_stock m
                                   JOIN variantes v ON v.id = m.variante_id
                                   JOIN productos p ON p.id = v.producto_id
                                  WHERE $where");
        $q->execute($args);
        return (int)$q->fetchColumn();
    }

    public function listarInventario(string $where, array $args, int $limite, int $offset): array
    {
        $sql = "SELECT m.id, m.variante_id, m.tipo, m.cantidad, m.stock_despues, m.motivo, m.ref_tipo, m.creado,
                       p.nombre AS producto, v.sku, v.talla, v.color, u.nombre AS usuario
                  FROM movimientos_stock m
                  JOIN variantes v ON v.id = m.variante_id
                  JOIN productos p ON p.id = v.producto_id
                  LEFT JOIN usuarios u ON u.id = m.usuario_id
                 WHERE $where
                 ORDER BY m.id DESC
                 LIMIT $limite OFFSET $offset";
        $q = $this->pdo->prepare($sql);
        $q->execute($args);
        return $q->fetchAll();
    }

    public function variantesBajoMinimo(): array
    {
        return $this->pdo->query('SELECT v.id, v.producto_id, p.nombre AS producto, v.sku, v.talla, v.color, v.stock, v.stock_minimo
                                    FROM variantes v
                                    JOIN productos p ON p.id = v.producto_id
                                   WHERE p.activo = 1 AND v.activo = 1 AND v.stock <= v.stock_minimo
                                   ORDER BY (v.stock - CAST(v.stock_minimo AS SIGNED)), p.nombre, v.id')->fetchAll();
    }

    /* ------------------------------ imágenes ------------------------------- */

    public function contarImagenes(int $productoId): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM producto_imagenes WHERE producto_id = ?');
        $q->execute([$productoId]);
        return (int)$q->fetchColumn();
    }

    public function maxOrden(int $productoId): int
    {
        $q = $this->pdo->prepare('SELECT COALESCE(MAX(orden), -1) + 1 FROM producto_imagenes WHERE producto_id = ?');
        $q->execute([$productoId]);
        return (int)$q->fetchColumn();
    }

    public function imagenesDe(int $productoId): array
    {
        $q = $this->pdo->prepare('SELECT id, ruta, alt, orden, principal FROM producto_imagenes WHERE producto_id = ? ORDER BY orden, id');
        $q->execute([$productoId]);
        return $q->fetchAll();
    }

    public function insertarImagen(int $productoId, string $ruta, string $alt, int $orden, int $principal): int
    {
        $this->pdo->prepare('INSERT INTO producto_imagenes (producto_id, ruta, alt, orden, principal) VALUES (?,?,?,?,?)')
            ->execute([$productoId, $ruta, $alt, $orden, $principal]);
        return (int)$this->pdo->lastInsertId();
    }

    public function fijarImgProducto(int $productoId, string $ruta): void
    {
        $this->pdo->prepare('UPDATE productos SET img = ? WHERE id = ?')->execute([$ruta, $productoId]);
    }

    public function imagen(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT id, producto_id, ruta, principal FROM producto_imagenes WHERE id = ?');
        $q->execute([$id]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function borrarImagen(int $id): void
    {
        $this->pdo->prepare('DELETE FROM producto_imagenes WHERE id = ?')->execute([$id]);
    }

    public function fijarOrdenImagen(int $id, int $orden): void
    {
        $this->pdo->prepare('UPDATE producto_imagenes SET orden = ? WHERE id = ?')->execute([$orden, $id]);
    }

    public function productoDeImagen(int $id): int
    {
        $q = $this->pdo->prepare('SELECT producto_id FROM producto_imagenes WHERE id = ?');
        $q->execute([$id]);
        return (int)$q->fetchColumn();
    }

    public function rutaDeImagen(int $id): ?string
    {
        $q = $this->pdo->prepare('SELECT ruta FROM producto_imagenes WHERE id = ?');
        $q->execute([$id]);
        $v = $q->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    public function desmarcarPrincipal(int $productoId): void
    {
        $this->pdo->prepare('UPDATE producto_imagenes SET principal = 0 WHERE producto_id = ?')->execute([$productoId]);
    }

    public function marcarPrincipal(int $id): void
    {
        $this->pdo->prepare('UPDATE producto_imagenes SET principal = 1 WHERE id = ?')->execute([$id]);
    }

    public function siguienteImagen(int $productoId): int
    {
        $q = $this->pdo->prepare('SELECT id FROM producto_imagenes WHERE producto_id = ? ORDER BY orden, id LIMIT 1');
        $q->execute([$productoId]);
        return (int)$q->fetchColumn();
    }
}
