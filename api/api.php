<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/src/Cache.php';
require __DIR__ . '/src/Metricas.php';
require __DIR__ . '/src/Catalogo.php';
require __DIR__ . '/src/Pedidos.php';
require __DIR__ . '/src/CRM.php';
require __DIR__ . '/src/Marketing.php';
require __DIR__ . '/src/Seguridad.php';
require __DIR__ . '/src/Checkout.php';
require __DIR__ . '/src/Outbox.php';
require __DIR__ . '/src/Admin.php';

require __DIR__ . '/src/Util.php';

/** Tallas y stock disponible de cada producto: [id => [['talla'=>'M','stock'=>3], ...]] */
function tallas_de(array $ids): array {
    if (!$ids) return [];
    $o = "FIELD(talla,'XS','S','M','L','XL','XXL','XXXL')";
    $q = db()->prepare('SELECT producto_id, talla, SUM(stock) AS stock
                          FROM variantes
                         WHERE activo = 1 AND producto_id IN (' . ph(count($ids)) . ")
                      GROUP BY producto_id, talla
                      ORDER BY producto_id, ($o=0), $o, talla");
    $q->execute(array_map('intval', $ids));
    $r = [];
    foreach ($q->fetchAll() as $x) {
        $r[(int)$x['producto_id']][] = ['talla' => (string)$x['talla'], 'stock' => (int)$x['stock']];
    }
    return $r;
}

/** SKU legible y estable para una variante. */
function sku_base(int $productoId, string $talla, string $color = ''): string {
    $lim = fn(string $v) => preg_replace('/[^A-Z0-9]+/', '', strtoupper($v)) ?: 'X';
    $sku = 'MG-' . $productoId . '-' . ($talla === '' ? 'STD' : $lim($talla));
    if ($color !== '') $sku .= '-' . $lim($color);
    return $sku;
}


$r = $_GET['r'] ?? '';
$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

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
        unset($x);
        out(['categorias' => $c, 'productos' => $p]);

    case 'cupon':
    case 'cupon_validar':
        req($m === 'POST', 'Método no permitido');
        $r = mk_cupon_validar(body());
        req($r['valido'], (string)($r['error'] ?? 'Cupón no válido'));
        out($r);

    case 'carrito':
        req($m === 'POST', 'Método no permitido');
        out(mk_carrito(body()));

    case 'pedido':
        req($m === 'POST', 'Método no permitido');
        $res = chk_crear(body(), $ip, substr((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''), 0, 36));
        $aviso = (int)($res['_aviso'] ?? 0);
        unset($res['_aviso']);

        // 1. Responder al cliente ya: el pedido está confirmado.
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($res, JSON_UNESCAPED_UNICODE);

        // 2. Enviar la confirmación inmediatamente después, sin hacer esperar al
        //    cliente. LiteSpeed libera la conexión con litespeed_finish_request;
        //    si no existe, se envía antes de cerrar (el cliente espera al SMTP).
        if ($aviso > 0) {
            ignore_user_abort(true);
            if (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
                @ob_procesar_ids([$aviso], 6);
            } elseif (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
                @ob_procesar_ids([$aviso], 6);
            } else {
                while (ob_get_level() > 0) { @ob_end_flush(); }
                @flush();
                @ob_procesar_ids([$aviso], 6);
            }
        }
        exit;

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

    /* ---------------- SESIÓN ---------------- */
    case 'login':
        req($m === 'POST', 'Método no permitido');
        $b = body();
        $q = db()->prepare('SELECT COUNT(*) FROM intentos WHERE ip=? AND creado > NOW() - INTERVAL 15 MINUTE');
        $q->execute([$ip]);
        if ((int)$q->fetchColumn() >= 8) out(['error' => 'Demasiados intentos. Espera 15 minutos.'], 429);

        $q = db()->prepare('SELECT u.*, r.nombre AS rol
                              FROM usuarios u JOIN roles r ON r.id = u.rol_id
                             WHERE u.usuario = ? AND u.activo = 1');
        $q->execute([s($b['usuario'] ?? '', 40)]);
        $a = $q->fetch();
        $hash = $a['clave_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        if (!$a || !password_verify((string)($b['clave'] ?? ''), $hash)) {
            db()->prepare('INSERT INTO intentos (ip) VALUES (?)')->execute([$ip]);
            sleep(1);
            out(['error' => 'Usuario o contraseña incorrectos'], 401);
        }
        // 2FA: si está activo, exige el código del autenticador.
        if (!empty($a['totp_activo'])) {
            $codigo = (string)($b['codigo'] ?? '');
            if ($codigo === '') {
                // Paso 1 correcto: la contraseña es válida; ahora se pide el código del autenticador.
                out(['requiere_2fa' => true]);
            }
            if (!totp_verificar((string)$a['totp_secreto'], $codigo)) {
                db()->prepare('INSERT INTO intentos (ip) VALUES (?)')->execute([$ip]);
                out(['error' => 'Código de verificación incorrecto'], 401);
            }
        }
        db()->prepare('DELETE FROM intentos WHERE ip=?')->execute([$ip]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$a['id'];
        $_SESSION['rol'] = $a['rol'];
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        unset($_SESSION['admin']);
        db()->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?')->execute([(int)$a['id']]);
        auditar('sesion.login', 'usuarios', (int)$a['id']);
        out(['ok' => true, 'csrf' => $_SESSION['csrf']]);

    case 'me':
        $u = usuario_actual();
        out([
            'admin'    => $u !== null,
            'csrf'     => $_SESSION['csrf'] ?? null,
            'usuario'  => $u['usuario'] ?? null,
            'nombre'   => $u['nombre'] ?? null,
            'rol'      => $u['rol'] ?? null,
            'permisos' => $u ? permisos() : [],
        ]);

    case 'logout':
        if (!usuario_actual()) out(['ok' => true]);
        if ($m !== 'GET') {
            $t = $_SERVER['HTTP_X_CSRF'] ?? '';
            if (!hash_equals($_SESSION['csrf'] ?? '', $t)) out(['error' => 'Token inválido, recarga la página'], 403);
        }
        auditar('sesion.logout', 'usuarios', (int)($_SESSION['uid'] ?? 0) ?: null);
        $_SESSION = [];
        session_destroy();
        out(['ok' => true]);

    /* ---------------- SEGURIDAD (2FA) ---------------- */
    case '2fa_estado':
        admin();
        out(seg_2fa_estado());

    case '2fa_generar':
        admin();
        out(seg_2fa_generar());

    case '2fa_activar':
        req($m === 'POST', 'Método no permitido');
        admin();
        out(seg_2fa_activar(body()));

    case '2fa_desactivar':
        admin();
        out(seg_2fa_desactivar());

    /* ---------------- BACKOFFICE · Fase 1 ---------------- */
    case 'kpis':
        admin('dashboard.ver');
        [$desde, $hasta] = mg_rango($_GET['desde'] ?? null, $_GET['hasta'] ?? null);
        out(mg_kpis($desde, $hasta));

    case 'alertas':
        admin('dashboard.ver');
        $a = mg_alertas();
        // La UI solo oculta botones; el recorte real ocurre aquí.
        if (!puede('productos.ver')) unset($a['stock']);
        if (!puede('pedidos.ver'))   unset($a['detenidos']);
        if (!puede('mensajes.ver'))  unset($a['mensajes']);
        out($a);

    case 'notificaciones':
        admin();
        out(mg_notificaciones((int)($_SESSION['uid'] ?? 0)));

    case 'notificacion_leer':
        admin();
        req($m === 'POST', 'Método no permitido');
        $b = body();
        $id = isset($b['id']) && (int)$b['id'] > 0 ? (int)$b['id'] : null;
        out(['ok' => true, 'marcadas' => mg_notificacion_leer((int)($_SESSION['uid'] ?? 0), $id)]);

    case 'pedidos_recientes':
        admin('pedidos.ver');
        out(mg_pedidos_recientes((int)($_GET['limite'] ?? 8)));

    case 'buscar':
        admin();
        out(mg_buscar((string)($_GET['q'] ?? '')));

    case 'visita':
        req($m === 'POST', 'Método no permitido');
        out(mg_visita(body()));

    /* ---------------- BACKOFFICE · Fase 2 (catálogo) ---------------- */
    case 'categorias':
        admin();
        out(cat_categorias());

    case 'productos':
        admin('productos.ver');
        out(cat_productos($_GET));

    case 'producto_detalle':
        admin('productos.ver');
        out(cat_producto_detalle((int)($_GET['id'] ?? 0)));

    case 'producto_guardar':
        req($m === 'POST', 'Método no permitido');
        admin('productos.editar');
        out(cat_producto_guardar(body()));

    case 'producto_archivar':
        req($m === 'POST', 'Método no permitido');
        admin('productos.borrar');
        out(cat_producto_archivar((int)(body()['id'] ?? 0)));

    case 'variantes_generar':
        req($m === 'POST', 'Método no permitido');
        admin('productos.editar');
        out(cat_variantes_generar(body()));

    case 'variante_guardar':
        req($m === 'POST', 'Método no permitido');
        admin('productos.editar');
        out(cat_variante_guardar(body()));

    case 'stock_ajustar':
        req($m === 'POST', 'Método no permitido');
        admin('inventario.ajustar');
        out(cat_stock_ajustar(body()));

    case 'inventario':
        admin('productos.ver');
        out(cat_inventario($_GET));

    case 'stock_alertas':
        admin('productos.ver');
        out(cat_stock_alertas());

    case 'imagen_subir':
        admin('productos.editar');
        out(cat_imagen_subir());

    case 'imagen_orden':
        req($m === 'POST', 'Método no permitido');
        admin('productos.editar');
        out(cat_imagen_orden(body()));

    case 'imagen_borrar':
        req($m === 'POST', 'Método no permitido');
        admin('productos.editar');
        out(cat_imagen_borrar(body()));

    case 'categoria_guardar':
        req($m === 'POST', 'Método no permitido');
        admin('categorias.editar');
        out(cat_categoria_guardar(body()));

    case 'categoria_borrar':
        req($m === 'POST', 'Método no permitido');
        admin('categorias.borrar');
        out(cat_categoria_borrar((string)(body()['id'] ?? '')));

    /* ---------------- BACKOFFICE · Fase 3 (pedidos) ---------------- */
    case 'pedidos':
        admin('pedidos.ver');
        out(ped_lista($_GET));

    case 'pedido_detalle':
        admin('pedidos.ver');
        out(ped_detalle((int)($_GET['id'] ?? 0)));

    case 'pedido_transicion':
        req($m === 'POST', 'Método no permitido');
        admin();
        out(ped_transicion(body()));

    case 'pedido_pago':
        req($m === 'POST', 'Método no permitido');
        admin('pedidos.editar');
        out(ped_pago(body()));

    case 'pedido_nota':
        req($m === 'POST', 'Método no permitido');
        admin('pedidos.editar');
        out(ped_nota(body()));

    case 'guia_guardar':
        req($m === 'POST', 'Método no permitido');
        admin('envios.gestionar');
        out(ped_guia(body()));

    case 'factura_emitir':
        req($m === 'POST', 'Método no permitido');
        admin('facturas.emitir');
        out(ped_factura_emitir((int)(body()['id'] ?? 0)));

    case 'factura_ver':
        admin('facturas.ver');
        out(ped_factura_ver((int)($_GET['id'] ?? 0)));

    case 'transportadoras':
        admin('envios.gestionar');
        out(ped_transportadoras($m === 'POST' ? body() : null));

    /* ---------------- BACKOFFICE · Fase 4 (CRM y RMA) ---------------- */
    case 'clientes':
        admin('clientes.ver');
        out(crm_clientes($_GET));

    case 'cliente_detalle':
        admin('clientes.ver');
        out(crm_cliente_detalle((int)($_GET['id'] ?? 0)));

    case 'cliente_guardar':
        req($m === 'POST', 'Método no permitido');
        admin('clientes.editar');
        out(crm_cliente_guardar(body()));

    case 'cliente_direccion':
        req($m === 'POST', 'Método no permitido');
        admin('clientes.editar');
        out(crm_cliente_direccion(body()));

    case 'cliente_fusionar':
        req($m === 'POST', 'Método no permitido');
        admin('clientes.editar');
        out(crm_cliente_fusionar(body()));

    case 'devoluciones':
        admin('devoluciones.ver');
        out(crm_devoluciones($_GET));

    case 'devolucion_crear':
        req($m === 'POST', 'Método no permitido');
        admin('devoluciones.editar');
        out(crm_devolucion_crear(body()));

    case 'devolucion_transicion':
        req($m === 'POST', 'Método no permitido');
        admin('devoluciones.editar');
        out(crm_devolucion_transicion(body()));

    /* ---------------- BACKOFFICE · Fase 5 (marketing) ---------------- */
    case 'promociones':
        admin('promociones.ver');
        out(mk_promociones($m === 'POST' ? body() : null));

    case 'promocion_borrar':
        req($m === 'POST', 'Método no permitido');
        admin('promociones.borrar');
        out(mk_promocion_borrar((int)(body()['id'] ?? 0)));

    case 'carritos':
        admin('carritos.ver');
        out(mk_carritos($_GET));

    case 'carrito_recuperar':
        req($m === 'POST', 'Método no permitido');
        admin('carritos.ver');
        out(mk_carrito_recuperar(body()));

    case 'marketing_resumen':
        admin('dashboard.ver');
        out(mk_marketing_resumen());

    /* ---------------- BACKOFFICE · Administración (migración del panel anterior) ---------------- */
    case 'mensajes':
        admin('mensajes.ver');
        out(adm_mensajes($_GET));

    case 'usuarios':
        admin('usuarios.gestionar');
        out(['items' => adm_usuarios(), 'roles' => adm_roles()]);

    case 'usuario_guardar':
        req($m === 'POST', 'Método no permitido');
        admin('usuarios.gestionar');
        out(adm_usuario_guardar(body()));

    case 'auditoria':
        admin('auditoria.ver');
        out(adm_auditoria($_GET));

    case 'configuracion':
        admin('config.editar');
        out(adm_configuracion());

    case 'configuracion_guardar':
        req($m === 'POST', 'Método no permitido');
        admin('config.editar');
        out(adm_configuracion_guardar(body()));

    /* ---------------- ADMIN ---------------- */
    case 'admin':
        admin();
        $pdo = db();
        $ped = [];
        if (puede('pedidos.ver')) {
            $ped = $pdo->query('SELECT * FROM pedidos ORDER BY id DESC LIMIT 200')->fetchAll();
            if ($ped) {
                $q = $pdo->prepare('SELECT * FROM pedido_items WHERE pedido_id IN (' . ph(count($ped)) . ')');
                $q->execute(array_column($ped, 'id'));
                $it = [];
                foreach ($q->fetchAll() as $x) $it[$x['pedido_id']][] = $x;
                foreach ($ped as &$p) $p['items'] = $it[$p['id']] ?? [];
                unset($p);
            }
        }
        $prods = [];
        if (puede('productos.ver')) {
            $prods = $pdo->query('SELECT id,nombre,categoria_id,marca,descr,precio,img,badge,badge_tipo,destacado,activo FROM productos ORDER BY id')->fetchAll();
            $T = tallas_de(array_column($prods, 'id'));
            foreach ($prods as &$x) {
                $x['tallas'] = $T[(int)$x['id']] ?? [];
                $x['stock'] = array_sum(array_column($x['tallas'], 'stock'));
            }
            unset($x);
        }
        out([
            'categorias' => $pdo->query('SELECT id, nombre FROM categorias ORDER BY nombre')->fetchAll(),
            'productos'  => $prods,
            'cupones'    => puede('cupones.ver') ? $pdo->query('SELECT * FROM cupones ORDER BY codigo')->fetchAll() : [],
            'pedidos'    => $ped,
            'mensajes'   => puede('mensajes.ver') ? $pdo->query('SELECT * FROM mensajes ORDER BY id DESC LIMIT 200')->fetchAll() : [],
            'permisos'   => permisos(),
        ]);

    case 'guardar':
        req($m === 'POST', 'Método no permitido');
        $b = body(); $d = $b['data'] ?? []; $tipo = $b['tipo'] ?? '';
        $permiso = ['producto' => 'productos.editar', 'categoria' => 'categorias.editar', 'cupon' => 'cupones.editar'][$tipo] ?? null;
        admin($permiso);
        $pdo = db();

        if ($tipo === 'producto') {
            $f = [s($d['nombre'] ?? '', 120), s($d['categoria_id'] ?? '', 40), s($d['marca'] ?? '', 60), s($d['descr'] ?? '', 255),
                  max(0, (int)($d['precio'] ?? 0)), s($d['img'] ?? '', 255), s($d['badge'] ?? '', 20),
                  (($d['badge_tipo'] ?? '') === 'oferta' ? 'oferta' : ''), !empty($d['destacado']) ? 1 : 0, !empty($d['activo']) ? 1 : 0];
            req($f[0] !== '' && $f[1] !== '' && $f[5] !== '', 'Nombre, categoría e imagen son obligatorios');
            req($f[4] > 0, 'El precio debe ser mayor a 0');
            req(preg_match('#^(\./)?img/[\w\-./]+$#', $f[5]) === 1 || strpos((string)$f[5], 'https://') === 0, 'Ruta de imagen no válida');
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
                $pid = !empty($d['id']) ? (int)$d['id'] : 0;
                if ($pid) {
                    $pdo->prepare('UPDATE productos SET nombre=?,categoria_id=?,marca=?,descr=?,precio=?,img=?,badge=?,badge_tipo=?,destacado=?,activo=? WHERE id=?')
                        ->execute(array_merge($f, [$pid]));
                } else {
                    $pdo->prepare('INSERT INTO productos (nombre,categoria_id,marca,descr,precio,img,badge,badge_tipo,destacado,activo) VALUES (?,?,?,?,?,?,?,?,?,?)')
                        ->execute($f);
                    $pid = (int)$pdo->lastInsertId();
                }

                // Variantes: se desactivan las que ya no están y se hace upsert de las enviadas.
                // Nunca se borran: conservan el historial de pedidos y movimientos.
                $pdo->prepare('UPDATE variantes SET activo=0 WHERE producto_id=?')->execute([$pid]);
                $skuOcupado = $pdo->prepare('SELECT 1 FROM variantes WHERE sku=? AND producto_id<>? LIMIT 1');
                $ups = $pdo->prepare('INSERT INTO variantes (producto_id, sku, talla, color, stock, stock_minimo, activo)
                                      VALUES (?,?,?,?,?,?,1)
                                      ON DUPLICATE KEY UPDATE stock=VALUES(stock), activo=1');
                foreach ($rows as $tl => $st) {
                    $sku = sku_base($pid, (string)$tl);
                    $skuOcupado->execute([$sku, $pid]);
                    if ($skuOcupado->fetchColumn()) $sku .= '-' . strtoupper(bin2hex(random_bytes(2)));
                    $ups->execute([$pid, $sku, (string)$tl, '', $st, 3]);
                }
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

            auditar('producto.guardar', 'productos', $pid, ['nombre' => $f[0], 'precio' => $f[4], 'tallas' => array_map(fn($k) => $k === '' ? 'STD' : $k, array_keys($rows))]);

        } elseif ($tipo === 'categoria') {
            $nombre = s($d['nombre'] ?? '', 60);
            req($nombre !== '', 'Escribe el nombre');
            if (!empty($d['id'])) {
                $pdo->prepare('UPDATE categorias SET nombre=? WHERE id=?')->execute([$nombre, s($d['id'], 40)]);
                auditar('categoria.editar', 'categorias', null, ['id' => s($d['id'], 40), 'nombre' => $nombre]);
            } else {
                $id = slug($nombre);
                req($id !== '', 'Nombre no válido');
                $q = $pdo->prepare('SELECT 1 FROM categorias WHERE id=?'); $q->execute([$id]);
                req(!$q->fetch(), 'Esa categoría ya existe');
                $pdo->prepare('INSERT INTO categorias (id,nombre) VALUES (?,?)')->execute([$id, $nombre]);
                auditar('categoria.crear', 'categorias', null, ['id' => $id, 'nombre' => $nombre]);
            }

        } elseif ($tipo === 'cupon') {
            $cod = strtoupper(s($d['codigo'] ?? '', 30));
            $tipoCupon = in_array($d['tipo'] ?? 'pct', ['pct', 'fijo', 'envio_gratis'], true) ? (string)($d['tipo'] ?? 'pct') : 'pct';
            $valor = (int)($d['valor'] ?? $d['pct'] ?? 0);
            $vence = s($d['vence'] ?? '', 10);
            $umax = ($d['usos_max'] ?? '') === '' || $d['usos_max'] === null ? null : max(1, (int)$d['usos_max']);
            $maxCli = ($d['max_por_cliente'] ?? '') === '' || $d['max_por_cliente'] === null ? null : max(1, (int)$d['max_por_cliente']);
            req(preg_match('/^[A-Z0-9_-]{3,30}$/', $cod) === 1, 'Código: 3-30 letras o números, sin espacios');
            if ($tipoCupon === 'pct') req($valor >= 1 && $valor <= 100, 'El descuento debe estar entre 1 y 100');
            if ($tipoCupon === 'fijo') req($valor >= 1, 'El monto fijo debe ser mayor a 0');
            req($vence === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence) === 1, 'Fecha no válida');
            $pdo->prepare('INSERT INTO cupones (codigo,tipo,valor,pct,activo,vence,minimo,usos_max,max_por_cliente,primera_compra) VALUES (?,?,?,?,?,?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE tipo=VALUES(tipo), valor=VALUES(valor), pct=VALUES(pct), activo=VALUES(activo), vence=VALUES(vence),
                           minimo=VALUES(minimo), usos_max=VALUES(usos_max), max_por_cliente=VALUES(max_por_cliente), primera_compra=VALUES(primera_compra)')
                ->execute([$cod, $tipoCupon, $valor, $tipoCupon === 'pct' ? $valor : 0, !empty($d['activo']) ? 1 : 0, $vence === '' ? null : $vence,
                    max(0, (int)($d['minimo'] ?? 0)), $umax, $maxCli, !empty($d['primera_compra']) ? 1 : 0]);
            auditar('cupon.guardar', 'cupones', null, ['codigo' => $cod, 'tipo' => $tipoCupon, 'valor' => $valor, 'activo' => !empty($d['activo'])]);
        } else {
            req(false, 'Tipo no válido');
        }
        out(['ok' => true]);

    case 'borrar':
        req($m === 'POST', 'Método no permitido');
        $b = body();
        $tipo = $b['tipo'] ?? '';
        $permiso = ['producto' => 'productos.borrar', 'categoria' => 'categorias.borrar', 'cupon' => 'cupones.borrar', 'mensaje' => 'mensajes.borrar'][$tipo] ?? null;
        admin($permiso);
        $id = s($b['id'] ?? '', 60);
        $pdo = db();
        $mensaje = null;

        switch ($tipo) {
            case 'producto':
                // Con historial de pedidos no se borra: se archiva (activo=0).
                $q = $pdo->prepare('SELECT COUNT(*) FROM pedido_items WHERE producto_id=?');
                $q->execute([(int)$id]);
                if ((int)$q->fetchColumn() > 0) {
                    $pdo->prepare('UPDATE productos SET activo=0 WHERE id=?')->execute([(int)$id]);
                    $pdo->prepare('UPDATE variantes SET activo=0 WHERE producto_id=?')->execute([(int)$id]);
                    $mensaje = 'El producto tiene pedidos: se archivó en lugar de borrarse';
                    auditar('producto.archivar', 'productos', (int)$id, ['motivo' => 'tiene pedidos']);
                } else {
                    $pdo->prepare('DELETE FROM productos WHERE id=?')->execute([(int)$id]);
                    auditar('producto.borrar', 'productos', (int)$id);
                }
                break;
            case 'categoria':
                $q = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE categoria_id=?'); $q->execute([$id]);
                req((int)$q->fetchColumn() === 0, 'Mueve o elimina primero sus productos');
                $pdo->prepare('DELETE FROM categorias WHERE id=?')->execute([$id]);
                auditar('categoria.borrar', 'categorias', null, ['id' => $id]);
                break;
            case 'cupon':
                $pdo->prepare('DELETE FROM cupones WHERE codigo=?')->execute([$id]);
                auditar('cupon.borrar', 'cupones', null, ['codigo' => $id]);
                break;
            case 'mensaje':
                $pdo->prepare('DELETE FROM mensajes WHERE id=?')->execute([(int)$id]);
                auditar('mensaje.borrar', 'mensajes', (int)$id);
                break;
            default:
                req(false, 'Tipo no válido');
        }
        out($mensaje ? ['ok' => true, 'mensaje' => $mensaje] : ['ok' => true]);

    case 'pedido_estado':
        req($m === 'POST', 'Método no permitido');
        $b = body(); $id = (int)($b['id'] ?? 0); $nuevo = $b['estado'] ?? '';
        req(in_array($nuevo, ['nuevo', 'confirmado', 'enviado', 'entregado', 'cancelado'], true), 'Estado no válido');
        admin($nuevo === 'cancelado' ? 'pedidos.cancelar' : 'pedidos.editar');

        $pdo = db(); $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT estado FROM pedidos WHERE id=? FOR UPDATE'); $q->execute([$id]);
            $old = $q->fetchColumn();
            req($old !== false, 'Pedido no encontrado');
            req($old !== 'cancelado', 'Un pedido cancelado no se puede reactivar');

            if ($nuevo === 'cancelado') {
                // Devuelve el stock variante por variante y deja rastro en el libro mayor.
                $q = $pdo->prepare('SELECT variante_id, producto_id, talla, cantidad FROM pedido_items WHERE pedido_id=?');
                $q->execute([$id]);
                $stock = $pdo->prepare('SELECT stock FROM variantes WHERE id=?');
                $busca = $pdo->prepare("SELECT id FROM variantes WHERE producto_id=? AND talla=? ORDER BY (color='') DESC, id LIMIT 1");
                $suma  = $pdo->prepare('UPDATE variantes SET stock = stock + ? WHERE id = ?');
                $mov   = $pdo->prepare('INSERT INTO movimientos_stock (variante_id,tipo,cantidad,stock_despues,motivo,ref_tipo,ref_id,usuario_id) VALUES (?,?,?,?,?,?,?,?)');

                foreach ($q->fetchAll() as $it) {
                    $vid = (int)($it['variante_id'] ?? 0);
                    if (!$vid) { // pedidos anteriores a la migración
                        $busca->execute([(int)$it['producto_id'], (string)$it['talla']]);
                        $vid = (int)$busca->fetchColumn();
                    }
                    if (!$vid) continue;
                    $cant = (int)$it['cantidad'];
                    $suma->execute([$cant, $vid]);
                    $stock->execute([$vid]);
                    $mov->execute([$vid, 'devolucion', $cant, (int)$stock->fetchColumn(), 'Cancelación pedido #' . $id, 'cancelacion', $id, (int)($_SESSION['uid'] ?? 0) ?: null]);
                }
            }

            $pdo->prepare('UPDATE pedidos SET estado=? WHERE id=?')->execute([$nuevo, $id]);
            $pdo->prepare('INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, usuario_id) VALUES (?,?,?,?)')
                ->execute([$id, (string)$old, $nuevo, (int)($_SESSION['uid'] ?? 0) ?: null]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

        auditar('pedido.estado', 'pedidos', $id, ['antes' => (string)$old, 'despues' => $nuevo]);
        out(['ok' => true]);

    case 'upload':
        admin('productos.editar');
        req($m === 'POST' && isset($_FILES['img']) && $_FILES['img']['error'] === UPLOAD_ERR_OK, 'No se recibió la imagen');
        $f = $_FILES['img'];
        req($f['size'] <= 4 * 1024 * 1024, 'La imagen supera 4 MB');
        $info = @getimagesize($f['tmp_name']);
        req($info !== false, 'El archivo no es una imagen');
        req(function_exists('imagewebp'), 'El servidor no tiene soporte WebP (GD)');
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
        $q = db()->prepare('SELECT * FROM usuarios WHERE id=?'); $q->execute([(int)($_SESSION['uid'] ?? 0)]);
        $a = $q->fetch();
        req($a && password_verify((string)($b['actual'] ?? ''), $a['clave_hash']), 'La contraseña actual no es correcta');
        req(strlen((string)($b['nueva'] ?? '')) >= 10, 'La nueva contraseña debe tener mínimo 10 caracteres');
        db()->prepare('UPDATE usuarios SET clave_hash=? WHERE id=?')->execute([password_hash((string)$b['nueva'], PASSWORD_DEFAULT), (int)$a['id']]);
        auditar('usuario.clave', 'usuarios', (int)$a['id']);
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
