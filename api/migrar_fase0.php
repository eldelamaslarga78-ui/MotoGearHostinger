<?php
declare(strict_types=1);
/*
 * MotoGear · Migración Fase 0  (ejecutar UNA vez y borrar)
 *
 * Crea las tablas nuevas del backoffice y copia los datos existentes:
 *   · roles, permisos, rol_permisos, usuarios   (RBAC, reemplaza a `admins`)
 *   · variantes                                 (reemplaza a `producto_tallas`)
 *   · movimientos_stock                         (libro mayor de inventario)
 *   · pedido_eventos, auditoria, configuracion
 *   · columnas variante_id / color / sku en pedido_items
 *
 * Es IDEMPOTENTE: se puede ejecutar dos veces sin duplicar ni perder datos.
 * No borra ni renombra ninguna tabla existente.
 *
 * Requisitos: estar logueado en admin.html con la sesión actual.
 * Al terminar: BORRA este archivo del servidor.
 */

require __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');
header_remove('Content-Security-Policy');
set_time_limit(180);

/* ---------------- Autorización (admite sesión vieja y nueva) ---------------- */
function autorizado(): bool {
    $intentos = [];
    if (!empty($_SESSION['uid']))  $intentos[] = ['usuarios', (int)$_SESSION['uid']];
    if (!empty($_SESSION['admin'])) $intentos[] = ['admins', (int)$_SESSION['admin']];
    foreach ($intentos as [$tabla, $id]) {
        try {
            $q = db()->prepare("SELECT 1 FROM `$tabla` WHERE id = ?");
            $q->execute([$id]);
            if ($q->fetchColumn()) return true;
        } catch (Throwable $e) { /* la tabla aún no existe */ }
    }
    return false;
}
function csrf_ok(): bool {
    $t = $_POST['csrf'] ?? '';
    if (!empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$t)) return true;
    // Sesión antigua sin token: se genera uno para este formulario.
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return hash_equals($_SESSION['csrf'], (string)$t);
}

/* ---------------- Helpers de esquema ---------------- */
function hayTabla(string $t): bool {
    $q = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute([$t]);
    return (bool)$q->fetchColumn();
}
function hayColumna(string $t, string $c): bool {
    $q = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $q->execute([$t, $c]);
    return (bool)$q->fetchColumn();
}
function hayIndice(string $t, string $i): bool {
    $q = db()->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $q->execute([$t, $i]);
    return (bool)$q->fetchColumn();
}
/**
 * Firma exacta de una columna entera (p. ej. "INT" o "INT UNSIGNED").
 *
 * Necesario porque las tiendas creadas con el código anterior usan INT con signo.
 * Si la columna nueva no coincide byte a byte con la referenciada, MySQL rechaza
 * el FOREIGN KEY con "errno: 150".
 */
function firmaColumna(string $tabla, string $columna, string $porDefecto = 'INT UNSIGNED'): string {
    $q = db()->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $q->execute([$tabla, $columna]);
    $t = strtolower((string)$q->fetchColumn());
    if ($t === '' || !preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $t, $m)) return $porDefecto;
    return strtoupper($m[1]) . (strpos($t, 'unsigned') !== false ? ' UNSIGNED' : '');
}
function versionActual(): int {
    if (!hayTabla('configuracion')) return 0;
    $q = db()->query("SELECT valor FROM configuracion WHERE clave = 'schema_version'");
    return (int)($q->fetchColumn() ?: 0);
}

/**
 * Colación con la que deben nacer las tablas nuevas.
 *
 * Se toma de `productos` (la tabla con la que se hacen los JOIN por `talla`).
 * La colación por defecto de la base no sirve: puede no coincidir con las
 * tablas antiguas y MySQL rechaza la comparación con "Illegal mix of collations".
 */
function colacionDb(): string {
    static $c = null;
    if ($c !== null) return $c;
    $q = db()->query("SELECT TABLE_COLLATION FROM information_schema.TABLES
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'");
    $c = (string)$q->fetchColumn();
    if ($c === '') {
        $c = (string)db()->query('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA
                                   WHERE SCHEMA_NAME = DATABASE()')->fetchColumn();
    }
    if (!preg_match('/^utf8mb4_/', $c)) $c = 'utf8mb4_unicode_ci';
    return $c;
}

/** Alinea la colación de una tabla nueva con la de la base (solo si hace falta). */
function alinearColacion(string $tabla): void {
    $q = db()->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute([$tabla]);
    $actual = (string)$q->fetchColumn();
    if ($actual !== '' && $actual !== colacionDb()) {
        db()->exec("ALTER TABLE `$tabla` CONVERT TO CHARACTER SET utf8mb4 COLLATE " . colacionDb());
    }
}

/**
 * Inserta una variante evitando choques de SKU.
 * Devuelve true si la creó, false si ya existía.
 */
function crearVariante(int $productoId, string $talla, int $stock, int $stockMinimo = 3): bool {
    $pdo = db();
    $q = $pdo->prepare("SELECT 1 FROM variantes WHERE producto_id = ? AND talla = ? AND color = ''");
    $q->execute([$productoId, $talla]);
    if ($q->fetchColumn()) return false;

    $lim = fn(string $v) => preg_replace('/[^A-Z0-9]+/', '', strtoupper($v)) ?: 'X';
    $sku = 'MG-' . $productoId . '-' . ($talla === '' ? 'STD' : $lim($talla));
    $ocupado = $pdo->prepare('SELECT 1 FROM variantes WHERE sku = ? LIMIT 1');
    $ocupado->execute([$sku]);
    if ($ocupado->fetchColumn()) $sku .= '-' . strtoupper(bin2hex(random_bytes(2)));

    $pdo->prepare("INSERT INTO variantes (producto_id, sku, talla, color, stock, stock_minimo, activo) VALUES (?,?,?,'',?,?,1)")
        ->execute([$productoId, $sku, $talla, $stock, $stockMinimo]);
    return true;
}

/* ---------------- Pasos ---------------- */
function pasos(): array {
    $pdo = db();
    $log = [];
    $ok = function (string $texto) use (&$log) { $log[] = ['ok', $texto]; };
    $coll = colacionDb();

    // Tablas nuevas creadas en un intento anterior: si su colación no coincide con
    // la de la base, se alinean antes de comparar textos con las tablas antiguas.
    foreach (['configuracion', 'roles', 'permisos', 'rol_permisos', 'usuarios', 'auditoria'] as $previa) {
        alinearColacion($previa);
    }

    /* 1. configuracion */
    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracion (
        clave VARCHAR(60) NOT NULL PRIMARY KEY,
        valor TEXT NULL,
        tipo ENUM('texto','numero','bool','json') NOT NULL DEFAULT 'texto',
        grupo VARCHAR(30) NOT NULL DEFAULT 'general',
        es_publico TINYINT(1) NOT NULL DEFAULT 0,
        actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$coll");
    $ok('Tabla configuracion');

    /* 2. RBAC */
    $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
        id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(40) NOT NULL UNIQUE,
        descripcion VARCHAR(120) NOT NULL DEFAULT '',
        es_sistema TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$coll");

    $pdo->exec("CREATE TABLE IF NOT EXISTS permisos (
        id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        clave VARCHAR(40) NOT NULL UNIQUE,
        grupo VARCHAR(30) NOT NULL,
        descripcion VARCHAR(120) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$coll");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rol_permisos (
        rol_id SMALLINT UNSIGNED NOT NULL,
        permiso_id SMALLINT UNSIGNED NOT NULL,
        PRIMARY KEY (rol_id, permiso_id),
        CONSTRAINT fk_rp_rol FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE,
        CONSTRAINT fk_rp_perm FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$coll");

    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(80) NOT NULL,
        usuario VARCHAR(40) NOT NULL UNIQUE,
        email VARCHAR(120) NOT NULL UNIQUE,
        clave_hash VARCHAR(255) NOT NULL,
        rol_id SMALLINT UNSIGNED NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        ultimo_acceso DATETIME NULL,
        creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_usr_rol FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$coll");

    $pdo->exec("CREATE TABLE IF NOT EXISTS auditoria (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT UNSIGNED NULL,
        accion VARCHAR(40) NOT NULL,
        entidad VARCHAR(30) NOT NULL,
        entidad_id BIGINT UNSIGNED NULL,
        cambios JSON NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_aud_usr (usuario_id, creado),
        KEY idx_aud_ent (entidad, entidad_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$coll");
    $ok('Tablas de seguridad: roles, permisos, rol_permisos, usuarios, auditoria');

    /* 3. Semillas de roles y permisos */
    $pdo->exec("INSERT INTO roles (nombre, descripcion, es_sistema) VALUES
        ('admin','Acceso total al backoffice',1),
        ('ventas','Pedidos, clientes y cupones',0),
        ('bodega','Inventario y despachos',0),
        ('soporte','Mensajes, clientes y devoluciones',0)
        ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion)");

    $permisos = [
        ['dashboard.ver',      'panel',     'Ver el dashboard y sus KPIs'],
        ['pedidos.ver',        'pedidos',   'Ver pedidos'],
        ['pedidos.editar',     'pedidos',   'Cambiar el estado de un pedido'],
        ['pedidos.cancelar',   'pedidos',   'Cancelar un pedido y devolver stock'],
        ['productos.ver',      'catalogo',  'Ver productos e inventario'],
        ['productos.editar',   'catalogo',  'Crear y editar productos, variantes e imágenes'],
        ['productos.borrar',   'catalogo',  'Eliminar o archivar productos'],
        ['categorias.editar',  'catalogo',  'Crear y renombrar categorías'],
        ['categorias.borrar',  'catalogo',  'Eliminar categorías'],
        ['cupones.ver',        'marketing', 'Ver cupones'],
        ['cupones.editar',     'marketing', 'Crear y editar cupones'],
        ['cupones.borrar',     'marketing', 'Eliminar cupones'],
        ['mensajes.ver',       'atencion',  'Ver mensajes de contacto'],
        ['mensajes.borrar',    'atencion',  'Eliminar mensajes'],
        ['clientes.ver',       'atencion',  'Ver clientes e historial'],
        ['devoluciones.ver',   'atencion',  'Ver devoluciones'],
        ['usuarios.gestionar', 'seguridad', 'Gestionar usuarios y roles'],
        ['auditoria.ver',      'seguridad', 'Ver el registro de auditoría'],
        ['config.editar',      'sistema',   'Cambiar la configuración de la tienda'],
    ];
    $ins = $pdo->prepare('INSERT INTO permisos (clave, grupo, descripcion) VALUES (?,?,?) ON DUPLICATE KEY UPDATE grupo=VALUES(grupo), descripcion=VALUES(descripcion)');
    foreach ($permisos as $p) $ins->execute($p);
    $ok('Permisos sembrados: ' . count($permisos));

    $roles = [];
    foreach ($pdo->query('SELECT id, nombre FROM roles')->fetchAll() as $r) $roles[$r['nombre']] = (int)$r['id'];

    // El rol admin recibe todos los permisos, incluidos los futuros.
    $pdo->prepare('INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT ?, id FROM permisos')->execute([$roles['admin']]);

    $mapa = [
        'ventas'  => ['dashboard.ver','pedidos.ver','pedidos.editar','productos.ver','cupones.ver','cupones.editar','clientes.ver','mensajes.ver','devoluciones.ver'],
        'bodega'  => ['dashboard.ver','pedidos.ver','pedidos.editar','productos.ver','productos.editar'],
        'soporte' => ['dashboard.ver','pedidos.ver','mensajes.ver','mensajes.borrar','clientes.ver','devoluciones.ver'],
    ];
    $asignar = $pdo->prepare('INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT ?, id FROM permisos WHERE clave = ?');
    foreach ($mapa as $rol => $claves) {
        foreach ($claves as $clave) $asignar->execute([$roles[$rol], $clave]);
    }
    $ok('Permisos por rol asignados (admin, ventas, bodega, soporte)');

    /* 4. Copia de admins -> usuarios */
    $copiados = 0;
    if (hayTabla('admins')) {
        $pdo->beginTransaction();
        try {
            foreach ($pdo->query('SELECT * FROM admins')->fetchAll() as $a) {
                $usuario = mb_substr((string)$a['usuario'], 0, 40);
                if ($usuario === '') continue;
                $email = mb_substr($usuario, 0, 90) . '@motogear.local';
                $q = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ?');
                $q->execute([$usuario]);
                $existe = (int)$q->fetchColumn();
                if ($existe) {
                    $pdo->prepare('UPDATE usuarios SET clave_hash = ?, rol_id = ?, activo = 1 WHERE id = ?')
                        ->execute([$a['clave_hash'], $roles['admin'], $existe]);
                } else {
                    $pdo->prepare('INSERT INTO usuarios (id, nombre, usuario, email, clave_hash, rol_id, activo) VALUES (?,?,?,?,?,?,1)')
                        ->execute([(int)$a['id'], $usuario, $usuario, $email, $a['clave_hash'], $roles['admin']]);
                    $copiados++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    $ok("Usuarios migrados desde admins: $copiados nuevos");

    /* 5. variantes */
    $tipoProducto = firmaColumna('productos', 'id');
    $pdo->exec("CREATE TABLE IF NOT EXISTS variantes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        producto_id $tipoProducto NOT NULL,
        sku VARCHAR(50) NOT NULL,
        talla VARCHAR(10) NOT NULL DEFAULT '',
        color VARCHAR(30) NOT NULL DEFAULT '',
        color_hex CHAR(7) NULL,
        precio_extra INT NOT NULL DEFAULT 0,
        stock INT NOT NULL DEFAULT 0,
        stock_minimo INT UNSIGNED NOT NULL DEFAULT 3,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_var (producto_id, talla, color),
        UNIQUE KEY uq_var_sku (sku),
        CONSTRAINT fk_var_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$coll");
    $ok('Tabla variantes (producto_id ' . $tipoProducto . ', igual que productos.id)');
    alinearColacion('variantes');
    $nuevas = 0; $revisadas = 0;
    if (hayTabla('producto_tallas')) {
        $filas = $pdo->query("SELECT pt.producto_id, pt.talla, pt.stock
                                FROM producto_tallas pt
                           LEFT JOIN variantes v ON v.producto_id = pt.producto_id AND v.talla = pt.talla AND v.color = ''
                               WHERE v.id IS NULL")->fetchAll();
        foreach ($filas as $f) {
            $revisadas++;
            if (crearVariante((int)$f['producto_id'], (string)$f['talla'], (int)$f['stock'])) $nuevas++;
        }
    }
    $ok("Variantes copiadas desde producto_tallas: $nuevas nuevas de $revisadas revisadas");

    /* 5b. Respaldo: tiendas viejas con columnas productos.tallas / productos.stock */
    $legado = 0;
    if (hayColumna('productos', 'tallas') && hayColumna('productos', 'stock')) {
        $filas = $pdo->query('SELECT id, tallas, stock FROM productos
                               WHERE id NOT IN (SELECT producto_id FROM variantes)')->fetchAll();
        foreach ($filas as $p) {
            $tl = array_values(array_unique(array_filter(array_map(fn($t) => strtoupper(trim($t)), explode(',', (string)$p['tallas'])))));
            $st = max(0, (int)$p['stock']);
            if (!$tl) {
                if (crearVariante((int)$p['id'], '', $st)) $legado++;
                continue;
            }
            $per = intdiv($st, count($tl));
            foreach ($tl as $k => $t) {
                if (crearVariante((int)$p['id'], $t, $per + ($k === 0 ? $st - $per * count($tl) : 0))) $legado++;
            }
        }
    }
    if ($legado) $ok("Variantes creadas desde productos.tallas (formato antiguo): $legado");

    /* 6. Libro mayor de inventario */
    $pdo->exec("CREATE TABLE IF NOT EXISTS movimientos_stock (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        variante_id INT UNSIGNED NOT NULL,
        tipo ENUM('entrada','salida','ajuste','devolucion','perdida') NOT NULL,
        cantidad INT NOT NULL,
        stock_despues INT NOT NULL,
        motivo VARCHAR(120) NOT NULL DEFAULT '',
        ref_tipo ENUM('pedido','devolucion','cancelacion','manual','importacion') NOT NULL,
        ref_id BIGINT UNSIGNED NULL,
        usuario_id INT UNSIGNED NULL,
        creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_mov_var FOREIGN KEY (variante_id) REFERENCES variantes(id) ON DELETE RESTRICT,
        KEY idx_mov_var (variante_id, creado),
        KEY idx_mov_ref (ref_tipo, ref_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$coll");
    alinearColacion('movimientos_stock');

    // La carga inicial se escribe UNA sola vez. Si el libro ya tiene movimientos
    // (porque la migración ya corrió, o porque hay ventas), no se toca nada:
    // así una re-ejecución no inventa "Carga inicial" para variantes nuevas.
    $movimientos = (int)$pdo->query('SELECT COUNT(*) FROM movimientos_stock')->fetchColumn();
    if ($movimientos === 0) {
        $pdo->exec("INSERT INTO movimientos_stock (variante_id, tipo, cantidad, stock_despues, motivo, ref_tipo)
                    SELECT v.id, 'entrada', v.stock, v.stock, 'Carga inicial (migración Fase 0)', 'importacion'
                      FROM variantes v
                     WHERE v.stock <> 0");
        $movimientos = (int)$pdo->query('SELECT COUNT(*) FROM movimientos_stock')->fetchColumn();
        $ok("Libro mayor de inventario creado con el stock actual ($movimientos movimientos)");
    } else {
        $ok("Libro mayor ya tenía $movimientos movimientos: no se duplica la carga inicial");
    }

    /* 7. pedido_eventos */
    $tipoPedido = firmaColumna('pedidos', 'id');
    $pdo->exec("CREATE TABLE IF NOT EXISTS pedido_eventos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pedido_id $tipoPedido NOT NULL,
        estado_anterior VARCHAR(20) NULL,
        estado_nuevo VARCHAR(20) NOT NULL,
        nota VARCHAR(300) NOT NULL DEFAULT '',
        usuario_id INT UNSIGNED NULL,
        creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_ev_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
        KEY idx_ev_ped (pedido_id, creado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$coll");
    $ok('Tabla pedido_eventos (pedido_id ' . $tipoPedido . ', igual que pedidos.id)');

    /* 8. pedido_items: variante_id, color, sku */
    $agregadas = [];
    if (!hayColumna('pedido_items', 'variante_id')) {
        $pdo->exec('ALTER TABLE pedido_items ADD COLUMN variante_id INT UNSIGNED NULL AFTER pedido_id');
        $agregadas[] = 'variante_id';
    }
    if (!hayColumna('pedido_items', 'color')) {
        $pdo->exec("ALTER TABLE pedido_items ADD COLUMN color VARCHAR(30) NOT NULL DEFAULT '' AFTER talla");
        $agregadas[] = 'color';
    }
    if (!hayColumna('pedido_items', 'sku')) {
        $pdo->exec("ALTER TABLE pedido_items ADD COLUMN sku VARCHAR(50) NOT NULL DEFAULT '' AFTER nombre");
        $agregadas[] = 'sku';
    }
    if (!hayIndice('pedido_items', 'idx_item_var')) {
        $pdo->exec('ALTER TABLE pedido_items ADD INDEX idx_item_var (variante_id)');
        $agregadas[] = 'índice idx_item_var';
    }
    $ok('pedido_items: ' . ($agregadas ? implode(', ', $agregadas) : 'sin cambios'));

    /* 8b. Relleno de variante_id en pedidos históricos */
    if (hayColumna('pedido_items', 'variante_id') && hayColumna('pedido_items', 'producto_id') && hayColumna('pedido_items', 'talla')) {
        $n = $pdo->exec("UPDATE pedido_items i
                           JOIN variantes v ON v.producto_id = i.producto_id AND v.talla = i.talla AND v.color = ''
                            SET i.variante_id = v.id, i.color = v.color, i.sku = v.sku
                          WHERE i.variante_id IS NULL");
        $ok('Líneas de pedidos históricos enlazadas a su variante: ' . (int)$n);
    }

    /* 9. Versión del esquema */
    $pdo->exec("INSERT INTO configuracion (clave, valor, tipo, grupo) VALUES ('schema_version','1','numero','sistema')
                ON DUPLICATE KEY UPDATE valor = '1'");
    $pdo->exec("INSERT INTO configuracion (clave, valor, tipo, grupo) VALUES ('fase0_fecha', NOW(), 'texto', 'sistema')
                ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    $ok('Versión de esquema registrada: 1');

    return $log;
}

/* ---------------- Vista ---------------- */
$autorizado = autorizado();
$hecho = isset($_GET['hecho']);
$log = [];
$error = null;

if ($autorizado && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.';
    } else {
        try {
            $log = pasos();
            $hecho = true;
        } catch (Throwable $e) {
            $error = 'La migración se detuvo: ' . $e->getMessage() . ' (no se perdió nada: cada paso es idempotente, puedes volver a intentarlo)';
            error_log('[MotoGear][fase0] ' . $e->getMessage());
        }
    }
}
$version = ($autorizado && hayTabla('configuracion')) ? versionActual() : 0;
$csrf = $_SESSION['csrf'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Migración Fase 0 · MotoGear</title>
<style>
  :root { --rojo:#880000; --ok:#1e7e34; --error:#c62828; --borde:#e3e3e6; }
  * { box-sizing:border-box; }
  body { margin:0; padding:32px 20px; font:15px/1.55 "Ubuntu", system-ui, sans-serif; color:#161618; background:#f6f6f7; }
  main { max-width:760px; margin:0 auto; background:#fff; border:1px solid var(--borde); border-radius:12px; padding:28px 32px; }
  h1 { margin:0 0 6px; font-size:22px; }
  p.sub { margin:0 0 22px; color:#5c5c63; }
  h2 { font-size:15px; text-transform:uppercase; letter-spacing:.04em; color:#5c5c63; margin:24px 0 10px; }
  ul { margin:0; padding-left:20px; }
  li { margin:3px 0; }
  .btn { display:inline-block; background:var(--rojo); color:#fff; border:0; border-radius:8px; padding:12px 24px; font-size:15px; font-weight:600; cursor:pointer; text-decoration:none; }
  .btn:hover { background:#aa0000; }
  .log { list-style:none; padding:0; }
  .log li { padding:8px 12px; border-left:4px solid var(--ok); background:#f1f8f2; border-radius:0 6px 6px 0; margin:6px 0; }
  .log li.error { border-color:var(--error); background:#fdf1f1; }
  .aviso { padding:14px 16px; border-radius:8px; margin:18px 0; }
  .aviso.ok { background:#f1f8f2; border:1px solid #bfe0c6; }
  .aviso.error { background:#fdf1f1; border:1px solid #f0c2c2; }
  .aviso.warn { background:#fff8e6; border:1px solid #f0e0b0; }
  code { background:#f1f1f3; padding:1px 6px; border-radius:4px; font-size:13px; }
  .paso { display:flex; gap:12px; align-items:center; margin:14px 0; }
  .muted { color:#8a8a92; font-size:13px; }
</style>
</head>
<body>
<main>
  <h1>Migración Fase 0</h1>
  <p class="sub">Cimientos del backoffice: RBAC, variantes e inventario. Ejecuta una sola vez y borra este archivo.</p>

<?php if (!$autorizado): ?>
  <div class="aviso error">
    <strong>No autorizado.</strong> Entra primero a <a href="../admin.html">admin.html</a> con tu usuario y contraseña, y vuelve a abrir esta página.
  </div>

<?php elseif ($error): ?>
  <div class="aviso error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
  <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <button class="btn" type="submit">Reintentar</button></form>

<?php elseif ($hecho): ?>
  <div class="aviso ok"><strong>Migración completada.</strong> Los datos existentes siguen intactos.</div>
  <ul class="log">
    <?php foreach ($log as [$tipo, $texto]): ?>
      <li class="<?= $tipo === 'ok' ? '' : 'error' ?>"><?= htmlspecialchars($texto, ENT_QUOTES) ?></li>
    <?php endforeach; ?>
  </ul>

  <h2>Ahora haz esto</h2>
  <ol>
    <li>Sube el resto de archivos de la Fase 0 (<code>api/db.php</code>, <code>api/api.php</code>, <code>admin.js</code>, <code>.htaccess</code>).</li>
    <li>Entra a <a href="../admin.html">admin.html</a> y comprueba que puedes iniciar sesión.</li>
    <li>Haz un pedido de prueba desde la tienda y cancélalo para verificar la devolución de stock.</li>
    <li><strong>Borra este archivo del servidor</strong> (<code>api/migrar_fase0.php</code>).</li>
  </ol>

<?php elseif ($version >= 1): ?>
  <div class="aviso warn">
    <strong>Ya está migrado</strong> (versión <?= (int)$version ?>). No hace falta volver a ejecutarlo.
  </div>
  <p class="muted">Si de todas formas necesitas re-ejecutarlo, añade <code>?forzar=1</code> a la URL. Es idempotente: no duplica datos.</p>
  <?php if (isset($_GET['forzar'])): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <button class="btn" type="submit">Ejecutar de nuevo</button></form>
  <?php endif; ?>

<?php else: ?>
  <h2>Qué va a hacer</h2>
  <ul>
    <li>Crear las tablas de seguridad: <code>roles</code>, <code>permisos</code>, <code>rol_permisos</code>, <code>usuarios</code>, <code>auditoria</code>.</li>
    <li>Copiar tus usuarios de <code>admins</code> a <code>usuarios</code> (misma contraseña, sin cambios).</li>
    <li>Crear <code>variantes</code> y copiar el stock de <code>producto_tallas</code>.</li>
    <li>Crear el libro mayor de inventario <code>movimientos_stock</code> con el stock actual.</li>
    <li>Añadir <code>variante_id</code>, <code>color</code> y <code>sku</code> a <code>pedido_items</code>.</li>
    <li>Crear <code>pedido_eventos</code> y <code>configuracion</code>.</li>
  </ul>
  <p class="muted">No borra ni renombra nada. Se puede ejecutar dos veces sin duplicar datos.</p>

  <div class="paso">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <button class="btn" type="submit">Ejecutar migración</button>
    </form>
  </div>
<?php endif; ?>
</main>
</body>
</html>
