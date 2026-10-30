<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* ---------- Cabeceras de seguridad de la API ---------- */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store, max-age=0');

/* ---------- Sesión ---------- */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $https,
    'path'     => '/',
]);
ini_set('session.use_strict_mode', '1');
session_name('mgsid');
session_start();

/* ---------- Base de datos ---------- */
function db(): PDO {
    static $p = null;
    if (!$p) {
        $p = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $p;
}

/* ---------- Salida / entrada ---------- */
function out($d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function body(): array {
    $j = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($j) ? $j : [];
}

/* ---------- Sesión de trabajo (RBAC) ---------- */
/**
 * Usuario autenticado con su rol, o null.
 * Acepta sesiones anteriores a la migración ($_SESSION['admin']) porque los ids
 * se copian 1:1 de `admins` a `usuarios`.
 */
function usuario_actual(): ?array {
    static $u = false;
    if ($u !== false) return $u;

    $id = (int)($_SESSION['uid'] ?? 0);
    if (!$id && !empty($_SESSION['admin'])) $id = (int)$_SESSION['admin'];
    if (!$id) return $u = null;

    $q = db()->prepare('SELECT u.id, u.nombre, u.usuario, u.email, u.rol_id, u.activo, r.nombre AS rol
                          FROM usuarios u JOIN roles r ON r.id = u.rol_id
                         WHERE u.id = ? AND u.activo = 1');
    $q->execute([$id]);
    $fila = $q->fetch();
    if (!$fila) return $u = null;

    $_SESSION['uid'] = (int)$fila['id'];
    unset($_SESSION['admin']);       // sesión migrada al esquema nuevo
    $_SESSION['rol'] = $fila['rol'];
    return $u = $fila;
}

/** Lista de permisos del usuario. El rol `admin` recibe comodín '*'. */
function permisos(): array {
    static $p = null;
    if ($p !== null) return $p;
    $u = usuario_actual();
    if (!$u) return $p = [];
    if ($u['rol'] === 'admin') return $p = ['*'];
    $q = db()->prepare('SELECT p.clave FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = ?');
    $q->execute([(int)$u['rol_id']]);
    return $p = array_column($q->fetchAll(), 'clave');
}

function puede(string $permiso): bool {
    $p = permisos();
    return in_array('*', $p, true) || in_array($permiso, $p, true);
}

/** Exige sesión y, en peticiones que modifican, token CSRF. Opcionalmente, un permiso. */
function admin(?string $permiso = null): void {
    if (!usuario_actual()) out(['error' => 'No autorizado'], 401);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        $t = $_SERVER['HTTP_X_CSRF'] ?? '';
        if (!hash_equals($_SESSION['csrf'] ?? '', $t)) out(['error' => 'Token inválido, recarga la página'], 403);
    }
    if ($permiso !== null && !puede($permiso)) {
        out(['error' => 'No tienes permiso para esta acción'], 403);
    }
}

/** Registro de auditoría. Nunca interrumpe la operación principal. */
function auditar(string $accion, string $entidad, ?int $entidadId = null, array $cambios = []): void {
    try {
        db()->prepare('INSERT INTO auditoria (usuario_id, accion, entidad, entidad_id, cambios, ip) VALUES (?,?,?,?,?,?)')
            ->execute([
                (int)($_SESSION['uid'] ?? 0) ?: null,
                $accion,
                $entidad,
                $entidadId,
                $cambios ? json_encode($cambios, JSON_UNESCAPED_UNICODE) : null,
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]);
    } catch (Throwable $e) {
        error_log('[MotoGear][auditoria] ' . $e->getMessage());
    }
}
