<?php
declare(strict_types=1);
/*
 * Catálogo (Fase 2) · Funciones del backend.
 *
 * El servidor manda: precios en enteros COP, validaciones, SKU/slug únicos y
 * el libro mayor de stock. Todo cambio sensible queda en `auditoria`.
 */

/* ----------------------------- utilidades ------------------------------ */

/** Entero no negativo o null. */
function cat_entero($v): ?int {
    if ($v === null || $v === '') return null;
    $n = (int)$v;
    return $n >= 0 ? $n : null;
}

/** Entero (permite negativos, para precio_extra de variantes). */
function cat_entero_signo($v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

/** Slug único a partir de un texto base (sin acentos, sin espacios). */
function cat_slug_unico(string $base, int $excluirId = 0): string {
    $base = slug($base);
    if ($base === '') $base = 'producto';
    $cand = $base;
    $n = 2;
    while (true) {
        $q = db()->prepare('SELECT 1 FROM productos WHERE slug = ? AND id <> ? LIMIT 1');
        $q->execute([$cand, $excluirId]);
        if (!$q->fetchColumn()) return $cand;
        $cand = $base . '-' . $n++;
    }
}

/** SKU único; si el dado choca, se le añade sufijo. */
function cat_sku_unico(string $sku, int $excluirId = 0): string {
    $base = trim($sku);
    if ($base === '') $base = 'MG-PROD';
    $cand = $base;
    $n = 2;
    while (true) {
        $q = db()->prepare('SELECT 1 FROM productos WHERE sku = ? AND id <> ? LIMIT 1');
        $q->execute([$cand, $excluirId]);
        if (!$q->fetchColumn()) return $cand;
        $cand = $base . '-' . $n++;
    }
}

/** Categorías (para filtros y CRUD). */
function cat_categorias(): array {
    return array_map(fn($x) => [
        'id'       => (string)$x['id'],
        'nombre'   => (string)$x['nombre'],
        'padre_id' => $x['padre_id'],
        'orden'    => (int)$x['orden'],
        'activo'   => (bool)$x['activo'],
    ], db()->query('SELECT id, nombre, padre_id, orden, activo FROM categorias ORDER BY orden, nombre')->fetchAll());
}

/* ------------------------------- listado ------------------------------- */

/**
 * Lista paginada de productos con búsqueda, filtros y orden en servidor.
 * GET ?r=productos
 */
function cat_productos(array $g): array {
    $q      = s($g['q'] ?? '', 80);
    $cat    = s($g['categoria'] ?? '', 40);
    $activo = $g['activo'] ?? '';           // '' | '1' | '0'
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

    $qTotal = db()->prepare("SELECT COUNT(*) FROM productos p LEFT JOIN categorias c ON c.id=p.categoria_id WHERE $w");
    $qTotal->execute($args);
    $total = (int)$qTotal->fetchColumn();

    $offset = ($pagina - 1) * $limite;
    $sql = "SELECT p.id, p.sku, p.nombre, p.slug, p.categoria_id, c.nombre AS categoria,
                   p.marca, p.precio, p.precio_antes, p.destacado, p.activo, p.creado,
                   COALESCE((SELECT i.ruta FROM producto_imagenes i WHERE i.producto_id=p.id ORDER BY i.principal DESC, i.orden, i.id LIMIT 1), p.img) AS img,
                   COALESCE((SELECT SUM(v.stock) FROM variantes v WHERE v.producto_id=p.id AND v.activo=1), 0) AS stock,
                   (SELECT COUNT(*) FROM variantes v WHERE v.producto_id=p.id) AS n_variantes
              FROM productos p
              LEFT JOIN categorias c ON c.id = p.categoria_id
             WHERE $w
             ORDER BY $campo $dir, p.id DESC
             LIMIT " . $limite . ' OFFSET ' . $offset;
    $qItems = db()->prepare($sql);
    $qItems->execute($args);
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
    ], $qItems->fetchAll());

    return [
        'items'   => $items,
        'total'   => $total,
        'pagina'  => $pagina,
        'limite'  => $limite,
        'paginas' => max(1, (int)ceil($total / $limite)),
    ];
}

/* ------------------------------ detalle -------------------------------- */

function cat_producto_detalle(int $id): array {
    $q = db()->prepare('SELECT * FROM productos WHERE id = ?');
    $q->execute([$id]);
    $p = $q->fetch();
    if (!$p) throw new UserError('Producto no encontrado');

    $q = db()->prepare('SELECT id, sku, talla, color, color_hex, precio_extra, stock, stock_minimo, activo
                          FROM variantes WHERE producto_id = ? ORDER BY FIELD(talla,"XS","S","M","L","XL","XXL","XXXL"), color, id');
    $q->execute([$id]);
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
    ], $q->fetchAll());

    $q = db()->prepare('SELECT id, ruta, alt, orden, principal FROM producto_imagenes WHERE producto_id = ? ORDER BY orden, id');
    $q->execute([$id]);
    $imagenes = array_map(fn($x) => [
        'id'        => (int)$x['id'],
        'ruta'      => (string)$x['ruta'],
        'alt'       => (string)$x['alt'],
        'orden'     => (int)$x['orden'],
        'principal' => (bool)$x['principal'],
    ], $q->fetchAll());

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

function cat_producto_guardar(array $b): array {
    $d = $b['data'] ?? [];
    $id = (int)($d['id'] ?? 0);

    $nombre    = s($d['nombre'] ?? '', 120);
    $categoria = s($d['categoria_id'] ?? '', 40);
    $precio    = (int)($d['precio'] ?? 0);
    $marca     = s($d['marca'] ?? '', 60);
    $descr     = s($d['descr'] ?? '', 255);
    $descrLarga= ($d['descr_larga'] ?? '') === '' ? null : mb_substr(trim((string)$d['descr_larga']), 0, 20000);
    $precioAntes = cat_entero($d['precio_antes'] ?? null);
    $costo     = cat_entero($d['costo'] ?? null);
    $iva       = (float)($d['iva_pct'] ?? 19);
    $peso      = max(0, (int)($d['peso_g'] ?? 0));
    $alto      = max(0, (int)($d['alto_mm'] ?? 0));
    $ancho     = max(0, (int)($d['ancho_mm'] ?? 0));
    $largo     = max(0, (int)($d['largo_mm'] ?? 0));
    $badge     = s($d['badge'] ?? '', 20);
    $badgeTipo = ($d['badge_tipo'] ?? '') === 'oferta' ? 'oferta' : '';
    $destacado = !empty($d['destacado']) ? 1 : 0;
    $activo    = array_key_exists('activo', $d) ? (!empty($d['activo']) ? 1 : 0) : 1;
    $metaTitulo= $d['meta_titulo'] === '' || $d['meta_titulo'] === null ? null : s($d['meta_titulo'], 70);
    $metaDesc  = $d['meta_desc'] === '' || $d['meta_desc'] === null ? null : s($d['meta_desc'], 160);
    $slugIn    = s($d['slug'] ?? '', 140);
    $skuIn     = s($d['sku'] ?? '', 40);

    req($nombre !== '', 'El nombre es obligatorio');
    req($categoria !== '', 'La categoría es obligatoria');
    req($precio > 0, 'El precio debe ser mayor a 0');
    req($iva >= 0 && $iva <= 100, 'El IVA debe estar entre 0 y 100');
    if ($precioAntes !== null) req($precioAntes > $precio, 'El precio anterior debe ser mayor que el precio');

    $q = db()->prepare('SELECT 1 FROM categorias WHERE id = ?');
    $q->execute([$categoria]);
    req((bool)$q->fetchColumn(), 'La categoría no existe');

    // slug y sku únicos
    $slug = $slugIn !== '' ? cat_slug_unico($slugIn, $id) : cat_slug_unico($nombre, $id);
    $sku  = cat_sku_unico($skuIn, $id);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id) {
            $pdo->prepare('UPDATE productos SET
                    sku=?, nombre=?, slug=?, categoria_id=?, marca=?, descr=?, descr_larga=?,
                    precio=?, precio_antes=?, costo=?, iva_pct=?, peso_g=?, alto_mm=?, ancho_mm=?, largo_mm=?,
                    badge=?, badge_tipo=?, destacado=?, activo=?, meta_titulo=?, meta_desc=?
                WHERE id=?')
                ->execute([$sku, $nombre, $slug, $categoria, $marca, $descr, $descrLarga,
                    $precio, $precioAntes, $costo, $iva, $peso, $alto, $ancho, $largo,
                    $badge, $badgeTipo, $destacado, $activo, $metaTitulo, $metaDesc, $id]);
            $nuevoId = $id;
        } else {
            $pdo->prepare('INSERT INTO productos
                    (sku, nombre, slug, categoria_id, marca, descr, descr_larga,
                     precio, precio_antes, costo, iva_pct, peso_g, alto_mm, ancho_mm, largo_mm,
                     badge, badge_tipo, destacado, activo, meta_titulo, meta_desc, img)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$sku, $nombre, $slug, $categoria, $marca, $descr, $descrLarga,
                    $precio, $precioAntes, $costo, $iva, $peso, $alto, $ancho, $largo,
                    $badge, $badgeTipo, $destacado, $activo, $metaTitulo, $metaDesc, '']);
            $nuevoId = (int)$pdo->lastInsertId();
            // SKU estable con el id, si no se indicó uno.
            if ($skuIn === '') {
                $pdo->prepare('UPDATE productos SET sku = ? WHERE id = ?')->execute(["MG-PROD-$nuevoId", $nuevoId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    auditar($id ? 'producto.editar' : 'producto.crear', 'productos', $nuevoId, ['nombre' => $nombre, 'sku' => $sku, 'precio' => $precio]);
    return ['ok' => true, 'id' => $nuevoId];
}

/* ------------------------------- archivar ------------------------------- */

function cat_producto_archivar(int $id): array {
    $pdo = db();
    $q = $pdo->prepare('SELECT id, nombre FROM productos WHERE id = ?');
    $q->execute([$id]);
    $p = $q->fetch();
    req((bool)$p, 'Producto no encontrado');

    $q = $pdo->prepare('SELECT COUNT(*) FROM pedido_items WHERE producto_id = ?');
    $q->execute([$id]);
    $tienePedidos = (int)$q->fetchColumn() > 0;

    if ($tienePedidos) {
        $pdo->prepare('UPDATE productos SET activo = 0 WHERE id = ?')->execute([$id]);
        $pdo->prepare('UPDATE variantes SET activo = 0 WHERE producto_id = ?')->execute([$id]);
        auditar('producto.archivar', 'productos', $id, ['motivo' => 'tiene pedidos']);
        return ['ok' => true, 'archivado' => true, 'mensaje' => 'El producto tiene pedidos: se archivó en lugar de borrarse'];
    }

    $pdo->prepare('DELETE FROM productos WHERE id = ?')->execute([$id]);
    auditar('producto.borrar', 'productos', $id, ['nombre' => (string)$p['nombre']]);
    return ['ok' => true, 'archivado' => false];
}
