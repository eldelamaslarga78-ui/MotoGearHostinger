<?php
declare(strict_types=1);
/*
 * Administración (migración del panel anterior y gaps del backoffice):
 * mensajes de contacto, usuarios y roles, auditoría y configuración.
 */

/* -------------------------------- mensajes ------------------------------ */

function adm_mensajes(array $g): array {
    $pagina = max(1, (int)($g['pagina'] ?? 1));
    $limite = min(100, max(1, (int)($g['limite'] ?? 20)));
    $total = (int)db()->query('SELECT COUNT(*) FROM mensajes')->fetchColumn();
    $offset = ($pagina - 1) * $limite;
    $q = db()->query("SELECT id, nombre, email, telefono, asunto, mensaje, creado FROM mensajes ORDER BY id DESC LIMIT $limite OFFSET $offset");
    return ['items' => $q->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'limite' => $limite, 'paginas' => max(1, (int)ceil($total / $limite))];
}

/* ---------------------------- usuarios y roles -------------------------- */

function adm_roles(): array {
    return db()->query('SELECT id, nombre, descripcion FROM roles ORDER BY id')->fetchAll();
}

function adm_usuarios(): array {
    $q = db()->query('SELECT u.id, u.nombre, u.usuario, u.email, u.activo, u.rol_id, r.nombre AS rol, u.ultimo_acceso, u.totp_activo
                        FROM usuarios u JOIN roles r ON r.id = u.rol_id ORDER BY u.id');
    return array_map(fn($x) => [
        'id' => (int)$x['id'], 'nombre' => (string)$x['nombre'], 'usuario' => (string)$x['usuario'],
        'email' => (string)$x['email'], 'activo' => (bool)$x['activo'], 'rol_id' => (int)$x['rol_id'],
        'rol' => (string)$x['rol'], 'ultimo_acceso' => $x['ultimo_acceso'], 'totp_activo' => (bool)$x['totp_activo'],
    ], $q->fetchAll());
}

/** Crea o edita un usuario. La clave es obligatoria al crear y opcional al editar. */
function adm_usuario_guardar(array $b): array {
    $d = $b['data'] ?? [];
    $id = (int)($d['id'] ?? 0);
    $nombre = s($d['nombre'] ?? '', 80);
    $usuario = s($d['usuario'] ?? '', 40);
    $email = strtolower(s($d['email'] ?? '', 120));
    $rolId = (int)($d['rol_id'] ?? 0);
    $activo = array_key_exists('activo', $d) ? (!empty($d['activo']) ? 1 : 0) : 1;
    $clave = (string)($d['clave'] ?? '');
    $yo = (int)($_SESSION['uid'] ?? 0);

    req($nombre !== '', 'Escribe el nombre');
    req(preg_match('/^[A-Za-z0-9._-]{3,40}$/', $usuario) === 1, 'Usuario: 3 a 40 letras, números, punto, guion o guion bajo');
    req(filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'Correo no válido');
    req($rolId > 0, 'Elige un rol');
    if ($id === 0) req(strlen($clave) >= 10, 'La contraseña debe tener mínimo 10 caracteres');
    if ($clave !== '') req(strlen($clave) >= 10, 'La contraseña debe tener mínimo 10 caracteres');

    $q = db()->prepare('SELECT id FROM roles WHERE id = ?');
    $q->execute([$rolId]);
    req((bool)$q->fetchColumn(), 'El rol no existe');

    if ($id && $id === $yo) {
        req($activo === 1, 'No puedes desactivar tu propia cuenta');
        $q = db()->prepare('SELECT rol_id FROM usuarios WHERE id = ?');
        $q->execute([$id]);
        $rolActual = (int)$q->fetchColumn();
        if ($rolActual !== $rolId) throw new UserError('No puedes cambiar tu propio rol');
    }

    try {
        if ($id) {
            db()->prepare('UPDATE usuarios SET nombre=?, usuario=?, email=?, rol_id=?, activo=? WHERE id=?')
                ->execute([$nombre, $usuario, $email, $rolId, $activo, $id]);
            if ($clave !== '') {
                db()->prepare('UPDATE usuarios SET clave_hash=? WHERE id=?')->execute([password_hash($clave, PASSWORD_DEFAULT), $id]);
            }
            auditar('usuario.editar', 'usuarios', $id, ['usuario' => $usuario, 'rol_id' => $rolId, 'activo' => $activo]);
        } else {
            db()->prepare('INSERT INTO usuarios (nombre, usuario, email, clave_hash, rol_id, activo) VALUES (?,?,?,?,?,?)')
                ->execute([$nombre, $usuario, $email, password_hash($clave, PASSWORD_DEFAULT), $rolId, $activo]);
            $id = (int)db()->lastInsertId();
            auditar('usuario.crear', 'usuarios', $id, ['usuario' => $usuario, 'rol_id' => $rolId]);
        }
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') throw new UserError('Ese usuario o correo ya existe');
        throw $e;
    }
    return ['ok' => true, 'id' => $id];
}

/* -------------------------------- auditoría ----------------------------- */

function adm_auditoria(array $g): array {
    $entidad = s($g['entidad'] ?? '', 30);
    $pagina = max(1, (int)($g['pagina'] ?? 1));
    $limite = min(100, max(1, (int)($g['limite'] ?? 30)));
    $where = '1=1'; $args = [];
    if ($entidad !== '') { $where = 'a.entidad = ?'; $args[] = $entidad; }

    $q = db()->prepare("SELECT COUNT(*) FROM auditoria a WHERE $where");
    $q->execute($args);
    $total = (int)$q->fetchColumn();

    $offset = ($pagina - 1) * $limite;
    $q = db()->prepare("SELECT a.id, a.accion, a.entidad, a.entidad_id, a.cambios, a.ip, a.creado, u.nombre AS usuario
                          FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id
                         WHERE $where ORDER BY a.id DESC LIMIT $limite OFFSET $offset");
    $q->execute($args);
    $items = array_map(fn($x) => [
        'id' => (int)$x['id'], 'accion' => (string)$x['accion'], 'entidad' => (string)$x['entidad'],
        'entidad_id' => $x['entidad_id'] !== null ? (int)$x['entidad_id'] : null,
        'cambios' => $x['cambios'] ? json_decode((string)$x['cambios'], true) : null,
        'ip' => (string)$x['ip'], 'creado' => (string)$x['creado'], 'usuario' => $x['usuario'] ?? 'Sistema',
    ], $q->fetchAll());

    $entidades = db()->query('SELECT DISTINCT entidad FROM auditoria ORDER BY entidad')->fetchAll(PDO::FETCH_COLUMN);
    return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'limite' => $limite,
        'paginas' => max(1, (int)ceil($total / $limite)), 'entidades' => $entidades];
}

/* ------------------------------- configuración -------------------------- */

/** Claves que el panel puede editar. Nunca se listan credenciales. */
const ADM_CONFIG_EDITABLE = [
    'carrito_abandono_horas' => ['etiqueta' => 'Horas para marcar un carrito como abandonado', 'tipo' => 'numero', 'min' => 1, 'max' => 72],
    'carrito_recordatorio_horas' => ['etiqueta' => 'Horas para enviar el recordatorio', 'tipo' => 'numero', 'min' => 1, 'max' => 336],
];

/** Solo lectura: datos del correo sin la clave. */
const ADM_CONFIG_LECTURA = ['smtp_host', 'smtp_puerto', 'smtp_usuario', 'smtp_remitente'];

function adm_configuracion(): array {
    $editables = [];
    foreach (ADM_CONFIG_EDITABLE as $clave => $meta) {
        $q = db()->prepare('SELECT valor FROM configuracion WHERE clave = ?');
        $q->execute([$clave]);
        $editables[] = ['clave' => $clave, 'valor' => (string)$q->fetchColumn(), 'etiqueta' => $meta['etiqueta'], 'min' => $meta['min'], 'max' => $meta['max']];
    }
    $lectura = [];
    foreach (ADM_CONFIG_LECTURA as $clave) {
        $q = db()->prepare('SELECT valor FROM configuracion WHERE clave = ?');
        $q->execute([$clave]);
        $lectura[] = ['clave' => $clave, 'valor' => (string)$q->fetchColumn()];
    }
    return ['editables' => $editables, 'lectura' => $lectura];
}

function adm_configuracion_guardar(array $b): array {
    $d = $b['data'] ?? [];
    $cambios = [];
    foreach (ADM_CONFIG_EDITABLE as $clave => $meta) {
        if (!array_key_exists($clave, $d)) continue;
        $valor = (int)$d[$clave];
        req($valor >= $meta['min'] && $valor <= $meta['max'], $meta['etiqueta'] . ': entre ' . $meta['min'] . ' y ' . $meta['max']);
        db()->prepare('UPDATE configuracion SET valor = ? WHERE clave = ?')->execute([(string)$valor, $clave]);
        $cambios[$clave] = $valor;
    }
    if ($cambios) auditar('configuracion.editar', 'configuracion', null, $cambios);
    return ['ok' => true];
}
