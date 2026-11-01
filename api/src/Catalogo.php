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

    // Un producto con movimientos de stock tampoco puede borrarse (FK RESTRICT):
    // se archiva para no perder el libro mayor.
    $q = $pdo->prepare('SELECT COUNT(*) FROM movimientos_stock m JOIN variantes v ON v.id = m.variante_id WHERE v.producto_id = ?');
    $q->execute([$id]);
    $tieneMovimientos = (int)$q->fetchColumn() > 0;

    if ($tienePedidos || $tieneMovimientos) {
        $pdo->prepare('UPDATE productos SET activo = 0 WHERE id = ?')->execute([$id]);
        $pdo->prepare('UPDATE variantes SET activo = 0 WHERE producto_id = ?')->execute([$id]);
        $razon = $tienePedidos ? 'tiene pedidos' : 'tiene movimientos de stock';
        auditar('producto.archivar', 'productos', $id, ['motivo' => $razon]);
        return ['ok' => true, 'archivado' => true, 'mensaje' => 'El producto tiene historial: se archivó en lugar de borrarse'];
    }

    $pdo->prepare('DELETE FROM productos WHERE id = ?')->execute([$id]);
    auditar('producto.borrar', 'productos', $id, ['nombre' => (string)$p['nombre']]);
    return ['ok' => true, 'archivado' => false];
}

/* ------------------------------ variantes ------------------------------- */

/** SKU de variante único; si choca, sufijo numérico. */
function cat_sku_variante_unico(string $base, int $excluirId = 0): string {
    $cand = $base;
    $n = 2;
    while (true) {
        $q = db()->prepare('SELECT 1 FROM variantes WHERE sku = ? AND id <> ? LIMIT 1');
        $q->execute([$cand, $excluirId]);
        if (!$q->fetchColumn()) return $cand;
        $cand = $base . '-' . $n++;
    }
}

/** Genera la matriz talla × color. Crea las combinaciones que falten. */
function cat_variantes_generar(array $b): array {
    $productoId = (int)($b['producto_id'] ?? 0);
    $q = db()->prepare('SELECT id FROM productos WHERE id = ?');
    $q->execute([$productoId]);
    req((bool)$q->fetchColumn(), 'Producto no encontrado');

    $tallas = [];
    foreach (($b['tallas'] ?? []) as $t) {
        $tl = strtoupper(s(is_array($t) ? ($t['talla'] ?? '') : (string)$t, 10));
        if ($tl !== '' && !in_array($tl, $tallas, true)) $tallas[] = $tl;
    }
    $colores = [];
    foreach (($b['colores'] ?? []) as $c) {
        if (is_array($c)) {
            $nombre = s($c['nombre'] ?? '', 30);
            $hex = s($c['hex'] ?? '', 7);
        } else {
            $nombre = s((string)$c, 30);
            $hex = '';
        }
        if ($nombre === '') continue;
        $colores[] = ['nombre' => $nombre, 'hex' => $hex];
    }

    req(count($tallas) > 0 || count($colores) > 0, 'Indica al menos tallas o colores');
    if (!$tallas) $tallas = [''];
    if (!$colores) $colores = [['nombre' => '', 'hex' => '']];

    $pdo = db();
    $creadas = 0;
    $omitidas = 0;
    $pdo->beginTransaction();
    try {
        $existe = $pdo->prepare('SELECT 1 FROM variantes WHERE producto_id = ? AND talla = ? AND color = ? LIMIT 1');
        $insert = $pdo->prepare('INSERT INTO variantes (producto_id, sku, talla, color, color_hex, precio_extra, stock, stock_minimo, activo)
                                 VALUES (?,?,?,?,?,0,0,3,1)');
        foreach ($tallas as $tl) {
            foreach ($colores as $co) {
                $existe->execute([$productoId, $tl, $co['nombre']]);
                if ($existe->fetchColumn()) { $omitidas++; continue; }
                $lim = fn(string $v) => preg_replace('/[^A-Z0-9]+/', '', strtoupper($v)) ?: 'X';
                $base = 'MG-' . $productoId . '-' . ($tl === '' ? 'STD' : $lim($tl));
                if ($co['nombre'] !== '') $base .= '-' . $lim($co['nombre']);
                $sku = cat_sku_variante_unico($base);
                $insert->execute([$productoId, $sku, $tl, $co['nombre'], $co['hex'] === '' ? null : $co['hex']]);
                $creadas++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    auditar('variantes.generar', 'productos', $productoId, ['creadas' => $creadas, 'omitidas' => $omitidas]);
    return ['ok' => true, 'creadas' => $creadas, 'omitidas' => $omitidas];
}

/** Edita una variante (sin tocar stock). */
function cat_variante_guardar(array $b): array {
    $id = (int)($b['id'] ?? 0);
    $q = db()->prepare('SELECT * FROM variantes WHERE id = ?');
    $q->execute([$id]);
    $v = $q->fetch();
    req((bool)$v, 'Variante no encontrada');

    $sku       = s($b['sku'] ?? '', 50);
    $talla     = strtoupper(s($b['talla'] ?? $v['talla'], 10));
    $color     = s($b['color'] ?? $v['color'], 30);
    $hex       = array_key_exists('color_hex', $b) ? s($b['color_hex'], 7) : (string)($v['color_hex'] ?? '');
    $extra     = cat_entero_signo($b['precio_extra'] ?? $v['precio_extra']);
    $minimo    = max(0, (int)($b['stock_minimo'] ?? $v['stock_minimo']));
    $activo    = array_key_exists('activo', $b) ? (!empty($b['activo']) ? 1 : 0) : (int)$v['activo'];

    req($extra !== null, 'Precio extra no válido');
    $q = db()->prepare('SELECT precio FROM productos WHERE id = ?');
    $q->execute([(int)$v['producto_id']]);
    $precioProd = (int)$q->fetchColumn();
    req($precioProd + $extra >= 0, 'El precio de la variante no puede ser negativo');

    // sku único (sin contar esta variante)
    $base = $sku !== '' ? $sku : $v['sku'];
    $sku = cat_sku_variante_unico($base, $id);

    // combinación única (uq_var)
    $q = db()->prepare('SELECT 1 FROM variantes WHERE producto_id = ? AND talla = ? AND color = ? AND id <> ? LIMIT 1');
    $q->execute([(int)$v['producto_id'], $talla, $color, $id]);
    req(!$q->fetchColumn(), 'Ya existe una variante con esa talla y color');

    db()->prepare('UPDATE variantes SET sku = ?, talla = ?, color = ?, color_hex = ?, precio_extra = ?, stock_minimo = ?, activo = ? WHERE id = ?')
        ->execute([$sku, $talla, $color, $hex === '' ? null : $hex, $extra, $minimo, $activo, $id]);

    auditar('variante.editar', 'variantes', $id, ['sku' => $sku, 'talla' => $talla, 'color' => $color]);
    return ['ok' => true];
}

/* ------------------------------ inventario ------------------------------ */

/** Ajuste de stock con motivo obligatorio y libro mayor. */
function cat_stock_ajustar(array $b): array {
    $varianteId = (int)($b['variante_id'] ?? 0);
    $delta      = (int)($b['cantidad'] ?? 0);
    $motivo     = s($b['motivo'] ?? '', 120);
    req($varianteId > 0, 'Variante no válida');
    req($delta !== 0, 'La cantidad no puede ser 0');
    req(mb_strlen($motivo) >= 5, 'Escribe un motivo (mínimo 5 caracteres)');

    $pdo = db();
    $pdo->beginTransaction();
    $nuevo = 0;
    try {
        $q = $pdo->prepare('SELECT id, stock FROM variantes WHERE id = ? FOR UPDATE');
        $q->execute([$varianteId]);
        $v = $q->fetch();
        req((bool)$v, 'Variante no encontrada');
        $nuevo = (int)$v['stock'] + $delta;
        req($nuevo >= 0, 'El stock resultante no puede ser negativo');

        $pdo->prepare('UPDATE variantes SET stock = ? WHERE id = ?')->execute([$nuevo, $varianteId]);
        $pdo->prepare('INSERT INTO movimientos_stock (variante_id, tipo, cantidad, stock_despues, motivo, ref_tipo, usuario_id)
                       VALUES (?,?,?,?,?,?,?)')
            ->execute([$varianteId, 'ajuste', $delta, $nuevo, $motivo, 'manual', (int)($_SESSION['uid'] ?? 0) ?: null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    auditar('stock.ajustar', 'variantes', $varianteId, ['delta' => $delta, 'nuevo' => $nuevo, 'motivo' => $motivo]);
    return ['ok' => true, 'stock' => $nuevo];
}

/** Libro mayor filtrable. */
function cat_inventario(array $g): array {
    $varianteId = (int)($g['variante_id'] ?? 0);
    $pagina = max(1, (int)($g['pagina'] ?? 1));
    $limite = min(100, max(1, (int)($g['limite'] ?? 30)));

    $where = ['1=1'];
    $args = [];
    if ($varianteId > 0) { $where[] = 'm.variante_id = ?'; $args[] = $varianteId; }
    $w = implode(' AND ', $where);

    $qTotal = db()->prepare("SELECT COUNT(*) FROM movimientos_stock m WHERE $w");
    $qTotal->execute($args);
    $total = (int)$qTotal->fetchColumn();

    $offset = ($pagina - 1) * $limite;
    $sql = "SELECT m.id, m.variante_id, m.tipo, m.cantidad, m.stock_despues, m.motivo, m.ref_tipo, m.creado,
                   p.nombre AS producto, v.sku, v.talla, v.color, u.nombre AS usuario
              FROM movimientos_stock m
              JOIN variantes v ON v.id = m.variante_id
              JOIN productos p ON p.id = v.producto_id
              LEFT JOIN usuarios u ON u.id = m.usuario_id
             WHERE $w
             ORDER BY m.id DESC
             LIMIT $limite OFFSET $offset";
    $q = db()->prepare($sql);
    $q->execute($args);
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
    ], $q->fetchAll());

    return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'limite' => $limite, 'paginas' => max(1, (int)ceil($total / $limite))];
}

/** Variantes por debajo del mínimo. */
function cat_stock_alertas(): array {
    $rows = db()->query('SELECT v.id, v.producto_id, p.nombre AS producto, v.sku, v.talla, v.color, v.stock, v.stock_minimo
                           FROM variantes v
                           JOIN productos p ON p.id = v.producto_id
                          WHERE p.activo = 1 AND v.activo = 1 AND v.stock <= v.stock_minimo
                          ORDER BY (v.stock - CAST(v.stock_minimo AS SIGNED)), p.nombre, v.id')->fetchAll();
    return array_map(fn($x) => [
        'id'           => (int)$x['id'],
        'producto_id'  => (int)$x['producto_id'],
        'producto'     => (string)$x['producto'],
        'sku'          => (string)$x['sku'],
        'talla'        => (string)$x['talla'],
        'color'        => (string)$x['color'],
        'stock'        => (int)$x['stock'],
        'stock_minimo' => (int)$x['stock_minimo'],
    ], $rows);
}

/* ------------------------------ multimedia ------------------------------ */

/** Sube una imagen y la guarda en WebP en 3 tamaños. */
function cat_imagen_subir(): array {
    $productoId = (int)($_POST['producto_id'] ?? 0);
    $alt = s($_POST['alt'] ?? '', 160);
    req($productoId > 0, 'Producto no válido');
    req($alt !== '', 'El texto alternativo (alt) es obligatorio');
    req(isset($_FILES['img']) && $_FILES['img']['error'] === UPLOAD_ERR_OK, 'No se recibió la imagen');
    $f = $_FILES['img'];
    req($f['size'] <= 4 * 1024 * 1024, 'La imagen supera 4 MB');
    $info = @getimagesize($f['tmp_name']);
    req($info !== false, 'El archivo no es una imagen');
    req(function_exists('imagewebp'), 'El servidor no tiene soporte WebP (GD)');
    $mk = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp', IMAGETYPE_GIF => 'imagecreatefromgif'];
    req(isset($mk[$info[2]]), 'Formato no permitido (usa JPG, PNG, WebP o GIF)');

    $q = db()->prepare('SELECT COUNT(*) FROM producto_imagenes WHERE producto_id = ?');
    $q->execute([$productoId]);
    req((int)$q->fetchColumn() < 6, 'Máximo 6 imágenes por producto');

    $im = $mk[$info[2]]($f['tmp_name']);
    req($im !== false, 'No se pudo leer la imagen');
    imagepalettetotruecolor($im);

    $base = bin2hex(random_bytes(8));
    $dir = dirname(__DIR__) . '/img/productos/' . $productoId;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    foreach ([1600, 800, 400] as $ancho) {
        $re = (imagesx($im) > $ancho) ? imagescale($im, $ancho) : $im;
        imagepalettetotruecolor($re);
        req(imagewebp($re, "$dir/$base-$ancho.webp", 82), 'No se pudo guardar la imagen');
        if ($re !== $im) imagedestroy($re);
    }
    imagedestroy($im);

    $ruta = "./img/productos/$productoId/$base-800.webp";
    $q = db()->prepare('SELECT COALESCE(MAX(orden), -1) + 1 FROM producto_imagenes WHERE producto_id = ?');
    $q->execute([$productoId]);
    $orden = (int)$q->fetchColumn();
    $q = db()->prepare('SELECT COUNT(*) FROM producto_imagenes WHERE producto_id = ?');
    $q->execute([$productoId]);
    $principal = (int)$q->fetchColumn() === 0 ? 1 : 0;

    db()->prepare('INSERT INTO producto_imagenes (producto_id, ruta, alt, orden, principal) VALUES (?,?,?,?,?)')
        ->execute([$productoId, $ruta, $alt, $orden, $principal]);
    $id = (int)db()->lastInsertId();

    if ($principal) {
        db()->prepare('UPDATE productos SET img = ? WHERE id = ?')->execute([$ruta, $productoId]);
    }
    auditar('imagen.subir', 'productos', $productoId, ['ruta' => $ruta]);
    return ['ok' => true, 'id' => $id, 'ruta' => $ruta, 'orden' => $orden, 'principal' => (bool)$principal];
}

/** Reordena y define la imagen principal. */
function cat_imagen_orden(array $b): array {
    $orden = $b['orden'] ?? [];
    $principalId = (int)($b['principal'] ?? 0);
    req(is_array($orden), 'Orden no válido');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($orden as $i => $id) {
            $pdo->prepare('UPDATE producto_imagenes SET orden = ? WHERE id = ?')->execute([(int)$i, (int)$id]);
        }
        if ($principalId > 0) {
            $q = $pdo->prepare('SELECT producto_id FROM producto_imagenes WHERE id = ?');
            $q->execute([$principalId]);
            $productoId = (int)$q->fetchColumn();
            if ($productoId > 0) {
                $pdo->prepare('UPDATE producto_imagenes SET principal = 0 WHERE producto_id = ?')->execute([$productoId]);
                $pdo->prepare('UPDATE producto_imagenes SET principal = 1 WHERE id = ?')->execute([$principalId]);
                $q = $pdo->prepare('SELECT ruta FROM producto_imagenes WHERE id = ?');
                $q->execute([$principalId]);
                $pdo->prepare('UPDATE productos SET img = ? WHERE id = ?')->execute([(string)$q->fetchColumn(), $productoId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true];
}

/** Borra la imagen (archivos + fila). */
function cat_imagen_borrar(array $b): array {
    $id = (int)($b['id'] ?? 0);
    $q = db()->prepare('SELECT id, producto_id, ruta FROM producto_imagenes WHERE id = ?');
    $q->execute([$id]);
    $img = $q->fetch();
    req((bool)$img, 'Imagen no encontrada');

    $q = db()->prepare('SELECT principal FROM producto_imagenes WHERE id = ?');
    $q->execute([$id]);
    $fuePrincipal = (int)$q->fetchColumn() === 1;

    db()->prepare('DELETE FROM producto_imagenes WHERE id = ?')->execute([$id]);

    $prefijo = preg_replace('/-800\.webp$/', '', (string)$img['ruta']);
    $abs = dirname(__DIR__) . '/' . str_replace('./', '', $prefijo);
    foreach ([400, 800, 1600] as $w) { @unlink("$abs-$w.webp"); }

    if ($fuePrincipal) {
        $q = db()->prepare('SELECT id FROM producto_imagenes WHERE producto_id = ? ORDER BY orden, id LIMIT 1');
        $q->execute([(int)$img['producto_id']]);
        $nueva = (int)$q->fetchColumn();
        if ($nueva) {
            db()->prepare('UPDATE producto_imagenes SET principal = 1 WHERE id = ?')->execute([$nueva]);
            $q = db()->prepare('SELECT ruta FROM producto_imagenes WHERE id = ?');
            $q->execute([$nueva]);
            db()->prepare('UPDATE productos SET img = ? WHERE id = ?')->execute([(string)$q->fetchColumn(), (int)$img['producto_id']]);
        } else {
            db()->prepare('UPDATE productos SET img = ? WHERE id = ?')->execute(['', (int)$img['producto_id']]);
        }
    }
    auditar('imagen.borrar', 'productos', (int)$img['producto_id'], ['ruta' => (string)$img['ruta']]);
    return ['ok' => true];
}

/* ------------------------------ categorías ------------------------------ */

function cat_categoria_guardar(array $b): array {
    $d = $b['data'] ?? [];
    $id     = s($d['id'] ?? '', 40);
    $nombre = s($d['nombre'] ?? '', 60);
    $padre  = s($d['padre_id'] ?? '', 40);
    $orden  = (int)($d['orden'] ?? 0);
    $icono  = s($d['icono'] ?? '', 60);
    $activo = array_key_exists('activo', $d) ? (!empty($d['activo']) ? 1 : 0) : 1;

    req($nombre !== '', 'Escribe el nombre');
    if ($padre !== '') {
        req($padre !== $id, 'Una categoría no puede ser su propia padre');
        $q = db()->prepare('SELECT 1 FROM categorias WHERE id = ?');
        $q->execute([$padre]);
        req((bool)$q->fetchColumn(), 'La categoría padre no existe');
    }

    if ($id !== '') {
        db()->prepare('UPDATE categorias SET nombre = ?, padre_id = ?, orden = ?, icono = ?, activo = ? WHERE id = ?')
            ->execute([$nombre, $padre === '' ? null : $padre, $orden, $icono === '' ? null : $icono, $activo, $id]);
    } else {
        $id = slug($nombre);
        req($id !== '', 'Nombre no válido');
        $q = db()->prepare('SELECT 1 FROM categorias WHERE id = ?');
        $q->execute([$id]);
        req(!$q->fetchColumn(), 'Esa categoría ya existe');
        db()->prepare('INSERT INTO categorias (id, nombre, padre_id, orden, icono, activo) VALUES (?,?,?,?,?,?)')
            ->execute([$id, $nombre, $padre === '' ? null : $padre, $orden, $icono === '' ? null : $icono, $activo]);
    }
    auditar('categoria.guardar', 'categorias', null, ['id' => $id, 'nombre' => $nombre]);
    return ['ok' => true, 'id' => $id];
}

function cat_categoria_borrar(string $id): array {
    $q = db()->prepare('SELECT COUNT(*) FROM productos WHERE categoria_id = ?');
    $q->execute([$id]);
    req((int)$q->fetchColumn() === 0, 'Mueve o elimina primero sus productos');
    $q = db()->prepare('SELECT COUNT(*) FROM categorias WHERE padre_id = ?');
    $q->execute([$id]);
    req((int)$q->fetchColumn() === 0, 'Elimina primero sus subcategorías');
    db()->prepare('DELETE FROM categorias WHERE id = ?')->execute([$id]);
    auditar('categoria.borrar', 'categorias', null, ['id' => $id]);
    return ['ok' => true];
}
