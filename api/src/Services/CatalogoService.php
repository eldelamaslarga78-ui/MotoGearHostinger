<?php
declare(strict_types=1);
/*
 * Catálogo (Fase 2) · Lógica de negocio: productos, variantes, categorías e
 * inventario. El servidor manda: precios enteros COP, validaciones, SKU/slug
 * únicos y libro mayor de stock. Todo cambio sensible queda en `auditoria`.
 */
namespace App\Services;

use App\Core\UserError;
use App\Repositories\Contracts\CatalogoRepositoryInterface;
use PDO;

final class CatalogoService
{
    public function __construct(private PDO $pdo, private CatalogoRepositoryInterface $repo)
    {
    }

    /* ----------------------------- utilidades ------------------------------ */

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

    private function entero($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = (int)$v;
        return $n >= 0 ? $n : null;
    }

    private function enteroSigno($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (int)$v;
    }

    private function slugUnico(string $base, int $excluirId = 0): string
    {
        $base = \App\Core\Texto::slug($base);
        if ($base === '') {
            $base = 'producto';
        }
        $cand = $base;
        $n = 2;
        while (true) {
            if (!$this->repo->slugExiste($cand, $excluirId)) {
                return $cand;
            }
            $cand = $base . '-' . $n++;
        }
    }

    private function skuUnico(string $sku, int $excluirId = 0): string
    {
        $base = trim($sku);
        if ($base === '') {
            $base = 'MG-PROD';
        }
        $cand = $base;
        $n = 2;
        while (true) {
            if (!$this->repo->skuExiste($cand, $excluirId)) {
                return $cand;
            }
            $cand = $base . '-' . $n++;
        }
    }

    private function skuVarianteUnico(string $base, int $excluirId = 0): string
    {
        $cand = $base;
        $n = 2;
        while (true) {
            if (!$this->repo->skuVarianteExiste($cand, $excluirId)) {
                return $cand;
            }
            $cand = $base . '-' . $n++;
        }
    }

    /* ------------------------------ categorías ----------------------------- */

    public function categorias(): array
    {
        return array_map(fn($x) => [
            'id'       => (string)$x['id'],
            'nombre'   => (string)$x['nombre'],
            'padre_id' => $x['padre_id'],
            'orden'    => (int)$x['orden'],
            'activo'   => (bool)$x['activo'],
        ], $this->repo->categorias());
    }

    /* ------------------------------- listado ------------------------------- */

    public function productos(array $g): array
    {
        $q      = self::s($g['q'] ?? '', 80);
        $cat    = self::s($g['categoria'] ?? '', 40);
        $activo = $g['activo'] ?? '';
        $pagina = max(1, (int)($g['pagina'] ?? 1));
        $limite = min(100, max(1, (int)($g['limite'] ?? 20)));

        $ordenes = [
            'nombre'    => 'p.nombre',
            'precio'    => 'p.precio',
            'sku'       => 'p.sku',
            'marca'     => 'p.marca',
            'categoria' => 'c.nombre',
            'stock'     => 'stock',
            'creado'    => 'p.creado',
        ];
        $campo = $ordenes[$g['orden'] ?? ''] ?? 'p.creado';
        $dir   = strtolower($g['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';

        $where = ['1=1'];
        $args  = [];
        if ($q !== '') {
            $where[] = '(p.nombre LIKE ? OR p.marca LIKE ? OR p.sku LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like);
        }
        if ($cat !== '') {
            $where[] = 'p.categoria_id = ?';
            $args[] = $cat;
        }
        if ($activo === '1' || $activo === '0') {
            $where[] = 'p.activo = ?';
            $args[] = (int)$activo;
        }
        $w = implode(' AND ', $where);

        $total = $this->repo->contarProductos($w, $args);
        $offset = ($pagina - 1) * $limite;
        $items = array_map(fn($x) => [
            'id'          => (int)$x['id'],
            'sku'         => (string)$x['sku'],
            'nombre'      => (string)$x['nombre'],
            'slug'        => (string)$x['slug'],
            'categoria'   => (string)$x['categoria'],
            'categoria_id'=> (string)$x['categoria_id'],
            'marca'       => (string)$x['marca'],
            'precio'      => (int)$x['precio'],
            'precio_antes'=> $x['precio_antes'] !== null ? (int)$x['precio_antes'] : null,
            'img'         => (string)$x['img'],
            'destacado'   => (bool)$x['destacado'],
            'activo'      => (bool)$x['activo'],
            'stock'       => (int)$x['stock'],
            'n_variantes' => (int)$x['n_variantes'],
            'creado'      => (string)$x['creado'],
        ], $this->repo->listarProductos($w, $args, $campo, $dir, $limite, $offset));

        return [
            'items'   => $items,
            'total'   => $total,
            'pagina'  => $pagina,
            'limite'  => $limite,
            'paginas' => max(1, (int)ceil($total / $limite)),
        ];
    }

    /* ------------------------------ detalle -------------------------------- */

    public function productoDetalle(int $id): array
    {
        $p = $this->repo->producto($id);
        if ($p === null) {
            throw new UserError('Producto no encontrado');
        }

        $variantes = array_map(fn($x) => [
            'id'          => (int)$x['id'],
            'sku'         => (string)$x['sku'],
            'talla'       => (string)$x['talla'],
            'color'       => (string)$x['color'],
            'color_hex'   => $x['color_hex'],
            'precio_extra'=> (int)$x['precio_extra'],
            'stock'       => (int)$x['stock'],
            'stock_minimo'=> (int)$x['stock_minimo'],
            'activo'      => (bool)$x['activo'],
        ], $this->repo->variantesDe($id));

        $imagenes = array_map(fn($x) => [
            'id'        => (int)$x['id'],
            'ruta'      => (string)$x['ruta'],
            'alt'       => (string)$x['alt'],
            'orden'     => (int)$x['orden'],
            'principal' => (bool)$x['principal'],
        ], $this->repo->imagenesDe($id));

        $stockTotal = array_sum(array_column($variantes, 'stock'));
        $activas = count(array_filter($variantes, fn($v) => $v['activo']));
        $margen = null;
        if ($p['costo'] !== null && $p['costo'] > 0 && $p['precio'] > 0) {
            $margen = round(($p['precio'] - (int)$p['costo']) * 100 / (int)$p['precio'], 1);
        }

        return [
            'producto' => [
                'id'          => (int)$p['id'],
                'sku'         => (string)$p['sku'],
                'nombre'      => (string)$p['nombre'],
                'slug'        => (string)$p['slug'],
                'categoria_id'=> (string)$p['categoria_id'],
                'marca'       => (string)$p['marca'],
                'descr'       => (string)$p['descr'],
                'descr_larga' => $p['descr_larga'],
                'precio'      => (int)$p['precio'],
                'precio_antes'=> $p['precio_antes'] !== null ? (int)$p['precio_antes'] : null,
                'costo'       => $p['costo'] !== null ? (int)$p['costo'] : null,
                'iva_pct'     => (float)$p['iva_pct'],
                'peso_g'      => (int)$p['peso_g'],
                'alto_mm'     => (int)$p['alto_mm'],
                'ancho_mm'    => (int)$p['ancho_mm'],
                'largo_mm'    => (int)$p['largo_mm'],
                'badge'       => (string)$p['badge'],
                'badge_tipo'  => (string)$p['badge_tipo'],
                'destacado'   => (bool)$p['destacado'],
                'activo'      => (bool)$p['activo'],
                'meta_titulo' => $p['meta_titulo'],
                'meta_desc'   => $p['meta_desc'],
                'img'         => (string)$p['img'],
                'creado'      => (string)$p['creado'],
            ],
            'variantes' => $variantes,
            'imagenes'  => $imagenes,
            'resumen'   => [
                'stock_total'      => $stockTotal,
                'variantes_activas'=> $activas,
                'variantes_total'  => count($variantes),
                'margen'           => $margen,
            ],
        ];
    }

    /* --------------------------- alta / edición ----------------------------- */

    public function productoGuardar(array $b): array
    {
        $d = $b['data'] ?? [];
        $id = (int)($d['id'] ?? 0);

        $nombre    = self::s($d['nombre'] ?? '', 120);
        $categoria = self::s($d['categoria_id'] ?? '', 40);
        $precio    = (int)($d['precio'] ?? 0);
        $marca     = self::s($d['marca'] ?? '', 60);
        $descr     = self::s($d['descr'] ?? '', 255);
        $descrLarga= ($d['descr_larga'] ?? '') === '' ? null : mb_substr(trim((string)$d['descr_larga']), 0, 20000);
        $precioAntes = $this->entero($d['precio_antes'] ?? null);
        $costo     = $this->entero($d['costo'] ?? null);
        $iva       = (float)($d['iva_pct'] ?? 19);
        $peso      = max(0, (int)($d['peso_g'] ?? 0));
        $alto      = max(0, (int)($d['alto_mm'] ?? 0));
        $ancho     = max(0, (int)($d['ancho_mm'] ?? 0));
        $largo     = max(0, (int)($d['largo_mm'] ?? 0));
        $badge     = self::s($d['badge'] ?? '', 20);
        $badgeTipo = ($d['badge_tipo'] ?? '') === 'oferta' ? 'oferta' : '';
        $destacado = !empty($d['destacado']) ? 1 : 0;
        $activo    = array_key_exists('activo', $d) ? (!empty($d['activo']) ? 1 : 0) : 1;
        $metaTitulo= ($d['meta_titulo'] ?? null) === '' || ($d['meta_titulo'] ?? null) === null ? null : self::s($d['meta_titulo'], 70);
        $metaDesc  = ($d['meta_desc'] ?? null) === '' || ($d['meta_desc'] ?? null) === null ? null : self::s($d['meta_desc'], 160);
        $slugIn    = self::s($d['slug'] ?? '', 140);
        $skuIn     = self::s($d['sku'] ?? '', 40);

        $this->req($nombre !== '', 'El nombre es obligatorio');
        $this->req($categoria !== '', 'La categoría es obligatoria');
        $this->req($precio > 0, 'El precio debe ser mayor a 0');
        $this->req($iva >= 0 && $iva <= 100, 'El IVA debe estar entre 0 y 100');
        if ($precioAntes !== null) {
            $this->req($precioAntes > $precio, 'El precio anterior debe ser mayor que el precio');
        }
        $this->req($this->repo->categoriaExiste($categoria), 'La categoría no existe');

        $slug = $slugIn !== '' ? $this->slugUnico($slugIn, $id) : $this->slugUnico($nombre, $id);
        $sku  = $this->skuUnico($skuIn, $id);

        $valores = [$sku, $nombre, $slug, $categoria, $marca, $descr, $descrLarga,
            $precio, $precioAntes, $costo, $iva, $peso, $alto, $ancho, $largo,
            $badge, $badgeTipo, $destacado, $activo, $metaTitulo, $metaDesc];

        $this->pdo->beginTransaction();
        try {
            if ($id) {
                $this->repo->actualizarProducto($valores, $id);
                $nuevoId = $id;
            } else {
                $nuevoId = $this->repo->insertarProducto(array_merge($valores, ['']));
                if ($skuIn === '') {
                    $this->repo->fijarSkuProducto($nuevoId, "MG-PROD-$nuevoId");
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        auditar($id ? 'producto.editar' : 'producto.crear', 'productos', $nuevoId, ['nombre' => $nombre, 'sku' => $sku, 'precio' => $precio]);
        return ['ok' => true, 'id' => $nuevoId];
    }

    /* ------------------------------- archivar ------------------------------- */

    public function productoArchivar(int $id): array
    {
        $p = $this->repo->producto($id);
        $this->req($p !== null, 'Producto no encontrado');

        $tienePedidos = $this->repo->contarPedidos($id) > 0;
        $tieneMovimientos = $this->repo->contarMovimientos($id) > 0;

        if ($tienePedidos || $tieneMovimientos) {
            $this->repo->archivarProducto($id);
            $razon = $tienePedidos ? 'tiene pedidos' : 'tiene movimientos de stock';
            auditar('producto.archivar', 'productos', $id, ['motivo' => $razon]);
            return ['ok' => true, 'archivado' => true, 'mensaje' => 'El producto tiene historial: se archivó en lugar de borrarse'];
        }

        $this->repo->borrarProducto($id);
        auditar('producto.borrar', 'productos', $id, ['nombre' => (string)$p['nombre']]);
        return ['ok' => true, 'archivado' => false];
    }

    /* ------------------------------ variantes ------------------------------- */

    public function variantesGenerar(array $b): array
    {
        $productoId = (int)($b['producto_id'] ?? 0);
        $this->req($this->repo->producto($productoId) !== null, 'Producto no encontrado');

        $tallas = [];
        foreach (($b['tallas'] ?? []) as $t) {
            $tl = strtoupper(self::s(is_array($t) ? ($t['talla'] ?? '') : (string)$t, 10));
            if ($tl !== '' && !in_array($tl, $tallas, true)) {
                $tallas[] = $tl;
            }
        }
        $colores = [];
        foreach (($b['colores'] ?? []) as $c) {
            if (is_array($c)) {
                $nombre = self::s($c['nombre'] ?? '', 30);
                $hex = self::s($c['hex'] ?? '', 7);
            } else {
                $nombre = self::s((string)$c, 30);
                $hex = '';
            }
            if ($nombre === '') {
                continue;
            }
            $colores[] = ['nombre' => $nombre, 'hex' => $hex];
        }

        if (!empty($b['sin_talla'])) {
            $tallas = [''];
        }
        $this->req(count($tallas) > 0 || count($colores) > 0, 'Indica al menos tallas o colores');
        if (!$tallas) {
            $tallas = [''];
        }
        if (!$colores) {
            $colores = [['nombre' => '', 'hex' => '']];
        }

        $creadas = 0;
        $omitidas = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($tallas as $tl) {
                foreach ($colores as $co) {
                    if ($this->repo->combinacionVarianteExiste($productoId, $tl, $co['nombre'], 0)) {
                        $omitidas++;
                        continue;
                    }
                    $lim = fn(string $v) => preg_replace('/[^A-Z0-9]+/', '', strtoupper($v)) ?: 'X';
                    $base = 'MG-' . $productoId . '-' . ($tl === '' ? 'STD' : $lim($tl));
                    if ($co['nombre'] !== '') {
                        $base .= '-' . $lim($co['nombre']);
                    }
                    $sku = $this->skuVarianteUnico($base);
                    $this->repo->insertarVariante($productoId, $sku, $tl, $co['nombre'], $co['hex'] === '' ? null : $co['hex']);
                    $creadas++;
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        auditar('variantes.generar', 'productos', $productoId, ['creadas' => $creadas, 'omitidas' => $omitidas]);
        return ['ok' => true, 'creadas' => $creadas, 'omitidas' => $omitidas];
    }

    public function varianteGuardar(array $b): array
    {
        $id = (int)($b['id'] ?? 0);
        $v = $this->repo->variante($id);
        $this->req($v !== null, 'Variante no encontrada');

        $sku    = self::s($b['sku'] ?? '', 50);
        $talla  = strtoupper(self::s($b['talla'] ?? $v['talla'], 10));
        $color  = self::s($b['color'] ?? $v['color'], 30);
        $hex    = array_key_exists('color_hex', $b) ? self::s($b['color_hex'], 7) : (string)($v['color_hex'] ?? '');
        $extra  = $this->enteroSigno($b['precio_extra'] ?? $v['precio_extra']);
        $minimo = max(0, (int)($b['stock_minimo'] ?? $v['stock_minimo']));
        $activo = array_key_exists('activo', $b) ? (!empty($b['activo']) ? 1 : 0) : (int)$v['activo'];

        $this->req($extra !== null, 'Precio extra no válido');
        $prod = $this->repo->producto((int)$v['producto_id']);
        $precioProd = $prod !== null ? (int)$prod['precio'] : 0;
        $this->req($precioProd + $extra >= 0, 'El precio de la variante no puede ser negativo');

        $base = $sku !== '' ? $sku : $v['sku'];
        $sku = $this->skuVarianteUnico($base, $id);

        $this->req(!$this->repo->combinacionVarianteExiste((int)$v['producto_id'], $talla, $color, $id), 'Ya existe una variante con esa talla y color');

        $this->repo->actualizarVariante($id, $sku, $talla, $color, $hex === '' ? null : $hex, $extra, $minimo, $activo);

        auditar('variante.editar', 'variantes', $id, ['sku' => $sku, 'talla' => $talla, 'color' => $color]);
        return ['ok' => true];
    }

    /* ------------------------------ inventario ------------------------------ */

    public function stockAjustar(array $b): array
    {
        $varianteId = (int)($b['variante_id'] ?? 0);
        $delta      = (int)($b['cantidad'] ?? 0);
        $motivo     = self::s($b['motivo'] ?? '', 120);
        $this->req($varianteId > 0, 'Variante no válida');
        $this->req($delta !== 0, 'La cantidad no puede ser 0');
        $this->req(mb_strlen($motivo) >= 5, 'Escribe un motivo (mínimo 5 caracteres)');

        $nuevo = 0;
        $this->pdo->beginTransaction();
        try {
            $v = $this->repo->varianteStock($varianteId);
            $this->req($v !== null, 'Variante no encontrada');
            $nuevo = (int)$v['stock'] + $delta;
            $this->req($nuevo >= 0, 'El stock resultante no puede ser negativo');

            $this->repo->fijarStock($varianteId, $nuevo);
            $this->repo->insertarMovimiento($varianteId, 'ajuste', $delta, $nuevo, $motivo, 'manual', (int)($_SESSION['uid'] ?? 0) ?: null);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        auditar('stock.ajustar', 'variantes', $varianteId, ['delta' => $delta, 'nuevo' => $nuevo, 'motivo' => $motivo]);
        return ['ok' => true, 'stock' => $nuevo];
    }

    public function inventario(array $g): array
    {
        $varianteId = (int)($g['variante_id'] ?? 0);
        $q = self::s($g['q'] ?? '', 80);
        $pagina = max(1, (int)($g['pagina'] ?? 1));
        $limite = min(100, max(1, (int)($g['limite'] ?? 30)));

        $where = ['1=1'];
        $args = [];
        if ($varianteId > 0) {
            $where[] = 'm.variante_id = ?';
            $args[] = $varianteId;
        }
        if ($q !== '') {
            $where[] = '(p.nombre LIKE ? OR v.sku LIKE ? OR m.motivo LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like);
        }
        $w = implode(' AND ', $where);

        $total = $this->repo->contarInventario($w, $args);
        $offset = ($pagina - 1) * $limite;
        $items = array_map(fn($x) => [
            'id'           => (int)$x['id'],
            'variante_id'  => (int)$x['variante_id'],
            'producto'     => (string)$x['producto'],
            'sku'          => (string)$x['sku'],
            'talla'        => (string)$x['talla'],
            'color'        => (string)$x['color'],
            'tipo'         => (string)$x['tipo'],
            'cantidad'     => (int)$x['cantidad'],
            'stock_despues'=> (int)$x['stock_despues'],
            'motivo'       => (string)$x['motivo'],
            'usuario'      => $x['usuario'] !== null ? (string)$x['usuario'] : null,
            'creado'       => (string)$x['creado'],
        ], $this->repo->listarInventario($w, $args, $limite, $offset));

        return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'limite' => $limite, 'paginas' => max(1, (int)ceil($total / $limite))];
    }

    public function stockAlertas(): array
    {
        return array_map(fn($x) => [
            'id'           => (int)$x['id'],
            'producto_id'  => (int)$x['producto_id'],
            'producto'     => (string)$x['producto'],
            'sku'          => (string)$x['sku'],
            'talla'        => (string)$x['talla'],
            'color'        => (string)$x['color'],
            'stock'        => (int)$x['stock'],
            'stock_minimo' => (int)$x['stock_minimo'],
        ], $this->repo->variantesBajoMinimo());
    }

    /* ------------------------------ categorías ------------------------------ */

    public function categoriaGuardar(array $b): array
    {
        $d = $b['data'] ?? [];
        $id     = self::s($d['id'] ?? '', 40);
        $nombre = self::s($d['nombre'] ?? '', 60);
        $padre  = self::s($d['padre_id'] ?? '', 40);
        $orden  = (int)($d['orden'] ?? 0);
        $icono  = self::s($d['icono'] ?? '', 60);
        $activo = array_key_exists('activo', $d) ? (!empty($d['activo']) ? 1 : 0) : 1;

        $this->req($nombre !== '', 'Escribe el nombre');
        if ($padre !== '') {
            $this->req($padre !== $id, 'Una categoría no puede ser su propia padre');
            $this->req($this->repo->categoriaExiste($padre), 'La categoría padre no existe');
        }

        if ($id !== '') {
            $this->repo->actualizarCategoria($id, $nombre, $padre === '' ? null : $padre, $orden, $icono === '' ? null : $icono, $activo);
        } else {
            $id = \App\Core\Texto::slug($nombre);
            $this->req($id !== '', 'Nombre no válido');
            $this->req(!$this->repo->categoriaExiste($id), 'Esa categoría ya existe');
            $this->repo->insertarCategoria($id, $nombre, $padre === '' ? null : $padre, $orden, $icono === '' ? null : $icono, $activo);
        }
        auditar('categoria.guardar', 'categorias', null, ['id' => $id, 'nombre' => $nombre]);
        return ['ok' => true, 'id' => $id];
    }

    public function categoriaBorrar(string $id): array
    {
        $this->req($this->repo->productosDeCategoria($id) === 0, 'Mueve o elimina primero sus productos');
        $this->req($this->repo->subcategoriasDe($id) === 0, 'Elimina primero sus subcategorías');
        $this->repo->borrarCategoria($id);
        auditar('categoria.borrar', 'categorias', null, ['id' => $id]);
        return ['ok' => true];
    }
}
