<?php
declare(strict_types=1);
/*
 * Contrato de acceso a datos del catálogo (productos, variantes, categorías,
 * inventario e imágenes). Permite probar los servicios sin base de datos.
 */
namespace App\Repositories\Contracts;

interface CatalogoRepositoryInterface
{
    /* categorías */
    public function categorias(): array;
    public function categoriaExiste(string $id): bool;
    public function productosDeCategoria(string $id): int;
    public function subcategoriasDe(string $id): int;
    public function insertarCategoria(string $id, string $nombre, ?string $padre, int $orden, ?string $icono, int $activo): void;
    public function actualizarCategoria(string $id, string $nombre, ?string $padre, int $orden, ?string $icono, int $activo): void;
    public function borrarCategoria(string $id): void;

    /* productos */
    public function slugExiste(string $slug, int $excluirId): bool;
    public function skuExiste(string $sku, int $excluirId): bool;
    public function producto(int $id): ?array;
    public function insertarProducto(array $v): int;
    public function actualizarProducto(array $v, int $id): void;
    public function fijarSkuProducto(int $id, string $sku): void;
    public function contarPedidos(int $productoId): int;
    public function contarMovimientos(int $productoId): int;
    public function archivarProducto(int $id): void;
    public function borrarProducto(int $id): void;
    public function contarProductos(string $where, array $args): int;
    public function listarProductos(string $where, array $args, string $campo, string $dir, int $limite, int $offset): array;

    /* variantes */
    public function variantesDe(int $productoId): array;
    public function variante(int $id): ?array;
    public function skuVarianteExiste(string $sku, int $excluirId): bool;
    public function combinacionVarianteExiste(int $productoId, string $talla, string $color, int $excluirId): bool;
    public function insertarVariante(int $productoId, string $sku, string $talla, string $color, ?string $hex): void;
    public function actualizarVariante(int $id, string $sku, string $talla, string $color, ?string $hex, int $extra, int $minimo, int $activo): void;
    public function varianteStock(int $id): ?array;
    public function fijarStock(int $id, int $stock): void;
    public function insertarMovimiento(int $varianteId, string $tipo, int $cantidad, int $stockDespues, string $motivo, string $refTipo, ?int $usuarioId): void;
    public function contarInventario(string $where, array $args): int;
    public function listarInventario(string $where, array $args, int $limite, int $offset): array;
    public function variantesBajoMinimo(): array;

    /* imágenes */
    public function contarImagenes(int $productoId): int;
    public function maxOrden(int $productoId): int;
    public function imagenesDe(int $productoId): array;
    public function insertarImagen(int $productoId, string $ruta, string $alt, int $orden, int $principal): int;
    public function fijarImgProducto(int $productoId, string $ruta): void;
    public function imagen(int $id): ?array;
    public function borrarImagen(int $id): void;
    public function fijarOrdenImagen(int $id, int $orden): void;
    public function productoDeImagen(int $id): int;
    public function rutaDeImagen(int $id): ?string;
    public function desmarcarPrincipal(int $productoId): void;
    public function marcarPrincipal(int $id): void;
    public function siguienteImagen(int $productoId): int;
}
