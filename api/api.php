<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

class UserError extends Exception {}
function req(bool $ok, string $msg): void { if (!$ok) throw new UserError($msg); }
function s($v, int $max): string { return mb_substr(trim((string)$v), 0, $max); }
function slug(string $t): string {
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $t) ?: $t;
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-');
}
function ph(int $n): string { return implode(',', array_fill(0, $n, '?')); }
/** Tallas y stock de cada producto: [id => [['talla'=>'M','stock'=>3], ...]] */
function tallas_de(array $ids): array {
    if (!$ids) return [];
    $o = "FIELD(talla,'XS','S','M','L','XL','XXL','XXXL')";
    $q = db()->prepare('SELECT producto_id, talla, stock FROM producto_tallas WHERE producto_id IN (' . ph(count($ids)) . ") ORDER BY producto_id, ($o=0), $o, talla");
    $q->execute(array_map('intval', $ids));
    $r = [];
    foreach ($q->fetchAll() as $x) $r[(int)$x['producto_id']][] = ['talla' => (string)$x['talla'], 'stock' => (int)$x['stock']];
    return $r;
}
function cupon(string $code, int $sub, bool $lock = false): array {
    $q = db()->prepare('SELECT * FROM cupones WHERE codigo=? AND activo=1' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([strtoupper(trim($code))]);
    $c = $q->fetch();
    if (!$c) return [null, 'Cupón no válido'];
    if ($c['vence'] && $c['vence'] < date('Y-m-d')) return [null, 'Cupón vencido'];
    if ($sub < (int)$c['minimo']) return [null, 'Compra mínima de $' . number_format((int)$c['minimo'], 0, ',', '.')];
    if ($c['usos_max'] !== null && (int)$c['usos'] >= (int)$c['usos_max']) return [null, 'Cupón agotado'];
    return [$c, null];
}

$r = $_GET['r'] ?? '';
$m = $_SERVER['REQUEST_METHOD'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    switch ($r) {

    /* ---------------- PÚBLICO ---------------- */
    case 'catalogo':
        $c = db()->query('SELECT id, nombre FROM categorias ORDER BY nombre')->fetchAll();
        $p = db()->query('SELECT id, nombre, categoria_id AS categoria, marca, descr AS `desc`, precio, img, badge, badge_tipo AS badgeTipo, destacado FROM productos WHERE activo=1 ORDER BY id')->fetchAll();
        $T = tallas_de(array_column($p, 'id'));
        foreach ($p as &$x) {
            $x['id'] = (int)$x['id']; $x['precio'] = (int)$x['precio']; $x['destacado'] = (bool)$x['destacado'];
            $x['tallas'] = $T[$x['id']] ?? [];
            $x['stock'] = array_sum(array_column($x['tallas'], 'stock'));
        }
        out(['categorias' => $c, 'productos' => $p]);

    case 'cupon':
        req($m === 'POST', 'Método no permitido');
        $b = body();
        [$c, $err] = cupon(s($b['codigo'] ?? '', 30), (int)($b['subtotal'] ?? 0));
        req($c !== null, (string)$err);
        out(['codigo' => $c['codigo'], 'pct' => (int)$c['pct']]);

    case 'pedido':
        req($m === 'POST', 'Método no permitido');
        $b = body();
        req(($b['web'] ?? '') === '', 'Solicitud rechazada');
        $nombre = s($b['nombre'] ?? '', 100);
        $tel = preg_replace('/[^\d+]/', '', s($b['telefono'] ?? '', 20));
        $ciudad = s($b['ciudad'] ?? '', 60);
        $dir = s($b['direccion'] ?? '', 200);
        $mail = s($b['email'] ?? '', 120);
        $notas = s($b['notas'] ?? '', 300);
        req(mb_strlen($nombre) >= 3, 'Escribe tu nombre completo');
        req(preg_match('/^\+?\d{7,15}$/', $tel) === 1, 'Teléfono no válido');
        req($ciudad !== '', 'Indica tu ciudad');
        req(mb_strlen($dir) >= 5, 'Indica tu dirección');
        req($mail === '' || filter_var($mail, FILTER_VALIDATE_EMAIL) !== false, 'Correo no válido');
        $items = $b['items'] ?? [];
        req(is_array($items) && count($items) >= 1 && count($items) <= 50, 'Tu carrito está vacío');

        $pdo = db();
        $q = $pdo->prepare('SELECT COUNT(*) FROM pedidos WHERE ip=? AND creado > NOW() - INTERVAL 1 HOUR');
        $q->execute([$ip]);
        req((int)$q->fetchColumn() < 10, 'Demasiados pedidos seguidos. Intenta más tarde');

        $pdo->beginTransaction();
        try {
            $ids = array_values(array_unique(array_map(fn($i) => (int)($i['id'] ?? 0), $items)));
            $ph = ph(count($ids));
            $q = $pdo->prepare("SELECT * FROM productos WHERE activo=1 AND id IN ($ph) FOR UPDATE"); $q->execute($ids);
            $P = [];
            foreach ($q->fetchAll() as $row) $P[(int)$row['id']] = $row;
            $q = $pdo->prepare("SELECT producto_id, talla, stock FROM producto_tallas WHERE producto_id IN ($ph) FOR UPDATE"); $q->execute($ids);
            $S = [];
            foreach ($q->fetchAll() as $row) $S[(int)$row['producto_id']][(string)$row['talla']] = (int)$row['stock'];

            $sub = 0; $need = []; $lines = [];
            foreach ($items as $i) {
                $id = (int)($i['id'] ?? 0); $qty = (int)($i['qty'] ?? 0); $talla = s($i['talla'] ?? '', 10);
                req(isset($P[$id]), 'Un producto de tu carrito ya no está disponible');
                req($qty >= 1 && $qty <= 20, 'Cantidad no válida');
                req(isset($S[$id][$talla]), 'Elige una talla válida para ' . $P[$id]['nombre']);
                $k = $id . '|' . $talla;
                $need[$k] = ($need[$k] ?? 0) + $qty;
                req($need[$k] <= $S[$id][$talla], 'Stock insuficiente de ' . $P[$id]['nombre'] . ($talla !== '' ? " (talla $talla)" : ''));
                $sub += (int)$P[$id]['precio'] * $qty;
                $lines[] = [$id, $P[$id]['nombre'], $talla, (int)$P[$id]['precio'], $qty];
            }
            $cup = null; $pct = 0; $code = s($b['cupon'] ?? '', 30);
            if ($code !== '') {
                [$cup, $err] = cupon($code, $sub, true);
                req($cup !== null, (string)$err);
                $pct = (int)$cup['pct'];
            }
            $descuento = intdiv($sub * $pct, 100);
            $total = $sub - $descuento;

            $pdo->prepare('INSERT INTO pedidos (cliente,telefono,email,ciudad,direccion,notas,subtotal,descuento,cupon,total,ip) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$nombre, $tel, $mail, $ciudad, $dir, $notas, $sub, $descuento, $cup ? $cup['codigo'] : null, $total, $ip]);
            $pid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO pedido_items (pedido_id,producto_id,nombre,talla,precio,cantidad) VALUES (?,?,?,?,?,?)');
            foreach ($lines as $l) $ins->execute(array_merge([$pid], $l));
            $upd = $pdo->prepare('UPDATE producto_tallas SET stock = stock - ? WHERE producto_id = ? AND talla = ?');
            foreach ($need as $k => $n) { [$pp, $tt] = explode('|', (string)$k, 2); $upd->execute([$n, (int)$pp, $tt]); }
            if ($cup) $pdo->prepare('UPDATE cupones SET usos = usos + 1 WHERE codigo = ?')->execute([$cup['codigo']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        out(['id' => $pid, 'total' => $total, 'descuento' => $descuento]);

    case 'mensaje':
        req($m === 'POST', 'Método no permitido');
        $b = body();
        req(($b['web'] ?? '') === '', 'Solicitud rechazada');
        $n = s($b['nombre'] ?? '', 100); $mail = s($b['email'] ?? '', 120);
        $msg = s($b['mensaje'] ?? '', 2000);
        req(mb_strlen($n) >= 2, 'Escribe tu nombre');
        req(filter_var($mail, FILTER_VALIDATE_EMAIL) !== false, 'Correo no válido');
        req(mb_strlen($msg) >= 5, 'Escribe tu mensaje');
        $q = db()->prepare('SELECT COUNT(*) FROM mensajes WHERE ip=? AND creado > NOW() - INTERVAL 1 HOUR');
        $q->execute([$ip]);
        req((int)$q->fetchColumn() < 5, 'Demasiados mensajes seguidos. Intenta más tarde');
        db()->prepare('INSERT INTO mensajes (nombre,email,telefono,asunto,mensaje,ip) VALUES (?,?,?,?,?,?)')
            ->execute([$n, $mail, s($b['telefono'] ?? '', 20), s($b['asunto'] ?? '', 80), $msg, $ip]);
        out(['ok' => true]);

    /* ---------------- SESIÓN ADMIN ---------------- */
    case 'login':
        req($m === 'POST', 'Método no permitido');
        $b = body();
        $q = db()->prepare('SELECT COUNT(*) FROM intentos WHERE ip=? AND creado > NOW() - INTERVAL 15 MINUTE');
        $q->execute([$ip]);
        if ((int)$q->fetchColumn() >= 8) out(['error' => 'Demasiados intentos. Espera 15 minutos.'], 429);
        $q = db()->prepare('SELECT * FROM admins WHERE usuario=?');
        $q->execute([s($b['usuario'] ?? '', 40)]);
        $a = $q->fetch();
        if (!$a || !password_verify((string)($b['clave'] ?? ''), $a['clave_hash'])) {
            db()->prepare('INSERT INTO intentos (ip) VALUES (?)')->execute([$ip]);
            sleep(1);
            out(['error' => 'Usuario o contraseña incorrectos'], 401);
        }
        db()->prepare('DELETE FROM intentos WHERE ip=?')->execute([$ip]);
        session_regenerate_id(true);
        $_SESSION['admin'] = (int)$a['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        out(['ok' => true, 'csrf' => $_SESSION['csrf']]);

    case 'me':
        out(['admin' => !empty($_SESSION['admin']), 'csrf' => $_SESSION['csrf'] ?? null]);

    case 'logout':
        admin();
        session_destroy();
        out(['ok' => true]);

    /* ---------------- ADMIN ---------------- */
    case 'admin':
        admin();
        $pdo = db();
        $ped = $pdo->query('SELECT * FROM pedidos ORDER BY id DESC LIMIT 200')->fetchAll();
        if ($ped) {
            $q = $pdo->prepare('SELECT * FROM pedido_items WHERE pedido_id IN (' . ph(count($ped)) . ')');
            $q->execute(array_column($ped, 'id'));
            $it = [];
            foreach ($q->fetchAll() as $x) $it[$x['pedido_id']][] = $x;
            foreach ($ped as &$p) $p['items'] = $it[$p['id']] ?? [];
            unset($p);
        }
        $prods = $pdo->query('SELECT id,nombre,categoria_id,marca,descr,precio,img,badge,badge_tipo,destacado,activo FROM productos ORDER BY id')->fetchAll();
        $T = tallas_de(array_column($prods, 'id'));
        foreach ($prods as &$x) { $x['tallas'] = $T[(int)$x['id']] ?? []; $x['stock'] = array_sum(array_column($x['tallas'], 'stock')); }
        unset($x);
        out([
            'categorias' => $pdo->query('SELECT id, nombre FROM categorias ORDER BY nombre')->fetchAll(),
            'productos'  => $prods,
            'cupones'    => $pdo->query('SELECT * FROM cupones ORDER BY codigo')->fetchAll(),
            'pedidos'    => $ped,
            'mensajes'   => $pdo->query('SELECT * FROM mensajes ORDER BY id DESC LIMIT 200')->fetchAll(),
        ]);

    case 'guardar':
        admin();
        req($m === 'POST', 'Método no permitido');
        $b = body(); $d = $b['data'] ?? []; $pdo = db();
        $tipo = $b['tipo'] ?? '';
        if ($tipo === 'producto') {
            $f = [s($d['nombre'] ?? '', 120), s($d['categoria_id'] ?? '', 40), s($d['marca'] ?? '', 60), s($d['descr'] ?? '', 255),
                  max(0, (int)($d['precio'] ?? 0)), s($d['img'] ?? '', 255), s($d['badge'] ?? '', 20),
                  (($d['badge_tipo'] ?? '') === 'oferta' ? 'oferta' : ''), !empty($d['destacado']) ? 1 : 0, !empty($d['activo']) ? 1 : 0];
            req($f[0] !== '' && $f[1] !== '' && $f[5] !== '', 'Nombre, categoría e imagen son obligatorios');
            req($f[4] > 0, 'El precio debe ser mayor a 0');
            req(preg_match('#^(\./)?img/[\w\-./]+$#', $f[5]) === 1 || strpos($f[5], 'https://') === 0, 'Ruta de imagen no válida');
            $q = $pdo->prepare('SELECT 1 FROM categorias WHERE id=?'); $q->execute([$f[1]]);
            req((bool)$q->fetch(), 'La categoría no existe');
            $rows = [];
            foreach (($d['tallas'] ?? []) as $t) {
                $tl = strtoupper(s($t['talla'] ?? '', 10));
                req(!isset($rows[$tl]), 'Talla repetida: ' . $tl);
                $rows[$tl] = max(0, (int)($t['stock'] ?? 0));
            }
            req(count($rows) >= 1, 'Agrega al menos una talla, o marca el producto como "sin tallas"');
            req(count($rows) === 1 || !isset($rows['']), 'Todas las tallas deben tener nombre');
            $pdo->beginTransaction();
            try {
                if (!empty($d['id'])) {
                    $pid = (int)$d['id'];
                    $pdo->prepare('UPDATE productos SET nombre=?,categoria_id=?,marca=?,descr=?,precio=?,img=?,badge=?,badge_tipo=?,destacado=?,activo=? WHERE id=?')->execute(array_merge($f, [$pid]));
                } else {
                    $pdo->prepare('INSERT INTO productos (nombre,categoria_id,marca,descr,precio,img,badge,badge_tipo,destacado,activo) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($f);
                    $pid = (int)$pdo->lastInsertId();
                }
                $pdo->prepare('DELETE FROM producto_tallas WHERE producto_id=?')->execute([$pid]);
                $ins = $pdo->prepare('INSERT INTO producto_tallas (producto_id,talla,stock) VALUES (?,?,?)');
                foreach ($rows as $tl => $st) $ins->execute([$pid, (string)$tl, $st]);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        } elseif ($tipo === 'categoria') {
            $nombre = s($d['nombre'] ?? '', 60);
            req($nombre !== '', 'Escribe el nombre');
            if (!empty($d['id'])) {
                $pdo->prepare('UPDATE categorias SET nombre=? WHERE id=?')->execute([$nombre, s($d['id'], 40)]);
            } else {
                $id = slug($nombre);
                req($id !== '', 'Nombre no válido');
                $q = $pdo->prepare('SELECT 1 FROM categorias WHERE id=?'); $q->execute([$id]);
                req(!$q->fetch(), 'Esa categoría ya existe');
                $pdo->prepare('INSERT INTO categorias (id,nombre) VALUES (?,?)')->execute([$id, $nombre]);
            }
        } elseif ($tipo === 'cupon') {
            $cod = strtoupper(s($d['codigo'] ?? '', 30)); $pct = (int)($d['pct'] ?? 0);
            $vence = s($d['vence'] ?? '', 10); $umax = ($d['usos_max'] ?? '') === '' || $d['usos_max'] === null ? null : max(1, (int)$d['usos_max']);
            req(preg_match('/^[A-Z0-9_-]{3,30}$/', $cod) === 1, 'Código: 3-30 letras o números, sin espacios');
            req($pct >= 1 && $pct <= 100, 'El descuento debe estar entre 1 y 100');
            req($vence === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence) === 1, 'Fecha no válida');
            $pdo->prepare('INSERT INTO cupones (codigo,pct,activo,vence,minimo,usos_max) VALUES (?,?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE pct=VALUES(pct), activo=VALUES(activo), vence=VALUES(vence), minimo=VALUES(minimo), usos_max=VALUES(usos_max)')
                ->execute([$cod, $pct, !empty($d['activo']) ? 1 : 0, $vence === '' ? null : $vence, max(0, (int)($d['minimo'] ?? 0)), $umax]);
        } else {
            req(false, 'Tipo no válido');
        }
        out(['ok' => true]);

    case 'borrar':
        admin();
        req($m === 'POST', 'Método no permitido');
        $b = body(); $id = s($b['id'] ?? '', 60); $pdo = db();
        switch ($b['tipo'] ?? '') {
            case 'producto': $pdo->prepare('DELETE FROM productos WHERE id=?')->execute([(int)$id]); break;
            case 'categoria':
                $q = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE categoria_id=?'); $q->execute([$id]);
                req((int)$q->fetchColumn() === 0, 'Mueve o elimina primero sus productos');
                $pdo->prepare('DELETE FROM categorias WHERE id=?')->execute([$id]); break;
            case 'cupon':   $pdo->prepare('DELETE FROM cupones WHERE codigo=?')->execute([$id]); break;
            case 'mensaje': $pdo->prepare('DELETE FROM mensajes WHERE id=?')->execute([(int)$id]); break;
            default: req(false, 'Tipo no válido');
        }
        out(['ok' => true]);

    case 'pedido_estado':
        admin();
        req($m === 'POST', 'Método no permitido');
        $b = body(); $id = (int)($b['id'] ?? 0); $nuevo = $b['estado'] ?? '';
        req(in_array($nuevo, ['nuevo', 'confirmado', 'enviado', 'entregado', 'cancelado'], true), 'Estado no válido');
        $pdo = db(); $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT estado FROM pedidos WHERE id=? FOR UPDATE'); $q->execute([$id]);
            $old = $q->fetchColumn();
            req($old !== false, 'Pedido no encontrado');
            req($old !== 'cancelado', 'Un pedido cancelado no se puede reactivar');
            if ($nuevo === 'cancelado') { // devuelve el stock
                $pdo->prepare('UPDATE producto_tallas t JOIN pedido_items i ON i.producto_id = t.producto_id AND i.talla = t.talla SET t.stock = t.stock + i.cantidad WHERE i.pedido_id = ?')->execute([$id]);
            }
            $pdo->prepare('UPDATE pedidos SET estado=? WHERE id=?')->execute([$nuevo, $id]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        out(['ok' => true]);

    case 'upload':
        admin();
        req($m === 'POST' && isset($_FILES['img']) && $_FILES['img']['error'] === UPLOAD_ERR_OK, 'No se recibió la imagen');
        $f = $_FILES['img'];
        req($f['size'] <= 4 * 1024 * 1024, 'La imagen supera 4 MB');
        req(function_exists('imagewebp'), 'El servidor no tiene soporte WebP (GD)');
        $info = @getimagesize($f['tmp_name']);
        req($info !== false, 'El archivo no es una imagen');
        $mk = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp', IMAGETYPE_GIF => 'imagecreatefromgif'];
        req(isset($mk[$info[2]]), 'Formato no permitido (usa JPG, PNG, WebP o GIF)');
        $im = $mk[$info[2]]($f['tmp_name']);
        req($im !== false, 'No se pudo leer la imagen');
        if ($info[0] > 1200) { $im = imagescale($im, 1200); }
        imagepalettetotruecolor($im);
        $name = bin2hex(random_bytes(8)) . '.webp';
        $dir = dirname(__DIR__) . '/img/productos';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        req(imagewebp($im, "$dir/$name", 82), 'No se pudo guardar la imagen');
        out(['ruta' => "./img/productos/$name"]);

    case 'clave':
        admin();
        req($m === 'POST', 'Método no permitido');
        $b = body();
        $q = db()->prepare('SELECT * FROM admins WHERE id=?'); $q->execute([$_SESSION['admin']]);
        $a = $q->fetch();
        req($a && password_verify((string)($b['actual'] ?? ''), $a['clave_hash']), 'La contraseña actual no es correcta');
        req(strlen((string)($b['nueva'] ?? '')) >= 10, 'La nueva contraseña debe tener mínimo 10 caracteres');
        db()->prepare('UPDATE admins SET clave_hash=? WHERE id=?')->execute([password_hash((string)$b['nueva'], PASSWORD_DEFAULT), $a['id']]);
        out(['ok' => true]);

    default:
        out(['error' => 'Ruta no encontrada'], 404);
    }
} catch (UserError $e) {
    out(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[MotoGear] ' . $e->getMessage());
    out(['error' => 'Error del servidor. Intenta de nuevo.'], 500);
}
