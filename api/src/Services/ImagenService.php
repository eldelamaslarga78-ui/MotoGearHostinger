<?php
declare(strict_types=1);
/*
 * Multimedia del catálogo: subida (GD → WebP en 3 tamaños), orden y borrado.
 */
namespace App\Services;

use App\Core\UserError;
use App\Repositories\Contracts\CatalogoRepositoryInterface;
use PDO;

final class ImagenService
{
    private const TAMANOS = [1600, 800, 400];

    public function __construct(private PDO $pdo, private CatalogoRepositoryInterface $repo)
    {
    }

    /** Sube una imagen y la guarda como WebP en 3 tamaños. */
    public function subir(int $productoId, string $alt, array $archivo): array
    {
        if ($productoId <= 0) {
            throw new UserError('Producto no válido');
        }
        if ($alt === '') {
            throw new UserError('El texto alternativo (alt) es obligatorio');
        }
        if (!isset($archivo['error']) || $archivo['error'] !== UPLOAD_ERR_OK) {
            throw new UserError('No se recibió la imagen');
        }
        if ((int)$archivo['size'] > 4 * 1024 * 1024) {
            throw new UserError('La imagen supera 4 MB');
        }
        $info = @getimagesize((string)$archivo['tmp_name']);
        if ($info === false) {
            throw new UserError('El archivo no es una imagen');
        }
        if (!function_exists('imagewebp')) {
            throw new UserError('El servidor no tiene soporte WebP (GD)');
        }
        $mk = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp', IMAGETYPE_GIF => 'imagecreatefromgif'];
        if (!isset($mk[$info[2]])) {
            throw new UserError('Formato no permitido (usa JPG, PNG, WebP o GIF)');
        }

        if ($this->repo->contarImagenes($productoId) >= 6) {
            throw new UserError('Máximo 6 imágenes por producto');
        }

        $im = $mk[$info[2]]((string)$archivo['tmp_name']);
        if ($im === false) {
            throw new UserError('No se pudo leer la imagen');
        }
        imagepalettetotruecolor($im);

        $base = bin2hex(random_bytes(8));
        // __DIR__ es api/src: la raíz del sitio está dos niveles arriba.
        $dir = dirname(__DIR__, 2) . '/img/productos/' . $productoId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach (self::TAMANOS as $ancho) {
            $re = (imagesx($im) > $ancho) ? imagescale($im, $ancho) : $im;
            imagepalettetotruecolor($re);
            if (!imagewebp($re, "$dir/$base-$ancho.webp", 82)) {
                throw new UserError('No se pudo guardar la imagen');
            }
            if ($re !== $im) {
                imagedestroy($re);
            }
        }
        imagedestroy($im);

        $ruta = "./img/productos/$productoId/$base-800.webp";
        $orden = $this->repo->maxOrden($productoId);
        $principal = $this->repo->contarImagenes($productoId) === 0 ? 1 : 0;
        $id = $this->repo->insertarImagen($productoId, $ruta, $alt, $orden, $principal);

        if ($principal) {
            $this->repo->fijarImgProducto($productoId, $ruta);
        }
        auditar('imagen.subir', 'productos', $productoId, ['ruta' => $ruta]);
        return ['ok' => true, 'id' => $id, 'ruta' => $ruta, 'orden' => $orden, 'principal' => (bool)$principal];
    }

    /** Reordena y define la imagen principal. */
    public function orden(array $orden, int $principalId): array
    {
        $this->pdo->beginTransaction();
        try {
            foreach ($orden as $i => $id) {
                $this->repo->fijarOrdenImagen((int)$id, (int)$i);
            }
            if ($principalId > 0) {
                $productoId = $this->repo->productoDeImagen($principalId);
                if ($productoId > 0) {
                    $this->repo->desmarcarPrincipal($productoId);
                    $this->repo->marcarPrincipal($principalId);
                    $ruta = $this->repo->rutaDeImagen($principalId);
                    $this->repo->fijarImgProducto($productoId, (string)$ruta);
                }
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

    /** Borra la imagen (archivos + fila). */
    public function borrar(int $id): array
    {
        $img = $this->repo->imagen($id);
        if ($img === null) {
            throw new UserError('Imagen no encontrada');
        }

        $this->repo->borrarImagen($id);

        $prefijo = preg_replace('/-800\.webp$/', '', (string)$img['ruta']);
        $rel = str_replace('./', '', $prefijo);
        // __DIR__ es api/src: los archivos viven en la raíz del sitio. Se prueba
        // también la ruta antigua (api/img) para limpiar subidas viejas.
        $base = dirname(__DIR__, 2) . '/' . $rel;
        $baseVieja = dirname(__DIR__) . '/' . $rel;
        foreach ([400, 800, 1600] as $w) {
            @unlink("$base-$w.webp");
            @unlink("$baseVieja-$w.webp");
        }

        $productoId = (int)$img['producto_id'];
        if ((int)$img['principal'] === 1) {
            $nueva = $this->repo->siguienteImagen($productoId);
            if ($nueva) {
                $this->repo->marcarPrincipal($nueva);
                $ruta = $this->repo->rutaDeImagen($nueva);
                $this->repo->fijarImgProducto($productoId, (string)$ruta);
            } else {
                $this->repo->fijarImgProducto($productoId, '');
            }
        }
        auditar('imagen.borrar', 'productos', $productoId, ['ruta' => (string)$img['ruta']]);
        return ['ok' => true];
    }
}
