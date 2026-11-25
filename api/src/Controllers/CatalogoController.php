<?php
declare(strict_types=1);
/*
 * Controlador delgado de catálogo: parsea la entrada y delega en los servicios.
 */
namespace App\Controllers;

use App\Core\UserError;
use App\Services\CatalogoService;
use App\Services\ImagenService;

final class CatalogoController
{
    public function __construct(
        private CatalogoService $catalogo,
        private ImagenService $imagenes,
    ) {
    }

    public function categorias(): array
    {
        return $this->catalogo->categorias();
    }

    public function productos(array $g): array
    {
        return $this->catalogo->productos($g);
    }

    public function productoDetalle(int $id): array
    {
        return $this->catalogo->productoDetalle($id);
    }

    public function productoGuardar(array $b): array
    {
        return $this->catalogo->productoGuardar($b);
    }

    public function productoArchivar(int $id): array
    {
        return $this->catalogo->productoArchivar($id);
    }

    public function variantesGenerar(array $b): array
    {
        return $this->catalogo->variantesGenerar($b);
    }

    public function varianteGuardar(array $b): array
    {
        return $this->catalogo->varianteGuardar($b);
    }

    public function stockAjustar(array $b): array
    {
        return $this->catalogo->stockAjustar($b);
    }

    public function inventario(array $g): array
    {
        return $this->catalogo->inventario($g);
    }

    public function stockAlertas(): array
    {
        return $this->catalogo->stockAlertas();
    }

    public function imagenSubir(array $post, array $files): array
    {
        return $this->imagenes->subir(
            (int)($post['producto_id'] ?? 0),
            (string)($post['alt'] ?? ''),
            $files['img'] ?? []
        );
    }

    public function imagenOrden(array $b): array
    {
        $orden = $b['orden'] ?? [];
        if (!is_array($orden)) {
            throw new UserError('Orden no válido');
        }
        return $this->imagenes->orden($orden, (int)($b['principal'] ?? 0));
    }

    public function imagenBorrar(array $b): array
    {
        return $this->imagenes->borrar((int)($b['id'] ?? 0));
    }

    public function categoriaGuardar(array $b): array
    {
        return $this->catalogo->categoriaGuardar($b);
    }

    public function categoriaBorrar(string $id): array
    {
        return $this->catalogo->categoriaBorrar($id);
    }
}
