<?php
declare(strict_types=1);
/*
 * Administración: mensajes de contacto, usuarios y roles, auditoría y
 * configuración (claves editables por el panel).
 */
namespace App\Services;

use App\Core\UserError;
use App\Repositories\Contracts\AdminRepositoryInterface;

final class AdminService
{
    /** Claves que el panel puede editar. Nunca se listan credenciales. */
    private const CONFIG_EDITABLE = [
        'carrito_abandono_horas' => ['etiqueta' => 'Horas para marcar un carrito como abandonado', 'tipo' => 'numero', 'min' => 1, 'max' => 72],
        'carrito_recordatorio_horas' => ['etiqueta' => 'Horas para enviar el recordatorio', 'tipo' => 'numero', 'min' => 1, 'max' => 336],
    ];

    /** Solo lectura: datos del correo sin la clave. */
    private const CONFIG_LECTURA = ['smtp_host', 'smtp_puerto', 'smtp_usuario', 'smtp_remitente'];

    public function __construct(private AdminRepositoryInterface $repo)
    {
    }

    private static function req(bool $ok, string $msg): void
    {
        if (!$ok) {
            throw new UserError($msg);
        }
    }

    private static function s($v, int $max): string
    {
        return \App\Core\Texto::s($v, $max);
    }

    private function uid(): int
    {
        return (int)($_SESSION['uid'] ?? 0);
    }

    /* -------------------------------- mensajes ------------------------------ */

    public function mensajes(array $g): array
    {
        $pagina = max(1, (int)($g['pagina'] ?? 1));
        $limite = min(100, max(1, (int)($g['limite'] ?? 20)));
        $total = $this->repo->contarMensajes();
        $offset = ($pagina - 1) * $limite;
        return ['items' => $this->repo->mensajes($limite, $offset), 'total' => $total, 'pagina' => $pagina, 'limite' => $limite, 'paginas' => max(1, (int)ceil($total / $limite))];
    }

    /* ---------------------------- usuarios y roles -------------------------- */

    public function roles(): array
    {
        return $this->repo->roles();
    }

    public function usuarios(): array
    {
        return array_map(fn($x) => [
            'id' => (int)$x['id'], 'nombre' => (string)$x['nombre'], 'usuario' => (string)$x['usuario'],
            'email' => (string)$x['email'], 'activo' => (bool)$x['activo'], 'rol_id' => (int)$x['rol_id'],
            'rol' => (string)$x['rol'], 'ultimo_acceso' => $x['ultimo_acceso'], 'totp_activo' => (bool)$x['totp_activo'],
        ], $this->repo->usuarios());
    }

    public function usuarioGuardar(array $b): array
    {
        $d = $b['data'] ?? [];
        $id = (int)($d['id'] ?? 0);
        $nombre = self::s($d['nombre'] ?? '', 80);
        $usuario = self::s($d['usuario'] ?? '', 40);
        $email = strtolower(self::s($d['email'] ?? '', 120));
        $rolId = (int)($d['rol_id'] ?? 0);
        $activo = array_key_exists('activo', $d) ? (!empty($d['activo']) ? 1 : 0) : 1;
        $clave = (string)($d['clave'] ?? '');
        $yo = $this->uid();

        self::req($nombre !== '', 'Escribe el nombre');
        self::req(preg_match('/^[A-Za-z0-9._-]{3,40}$/', $usuario) === 1, 'Usuario: 3 a 40 letras, números, punto, guion o guion bajo');
        self::req(filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'Correo no válido');
        self::req($rolId > 0, 'Elige un rol');
        if ($id === 0) {
            self::req(strlen($clave) >= 10, 'La contraseña debe tener mínimo 10 caracteres');
        }
        if ($clave !== '') {
            self::req(strlen($clave) >= 10, 'La contraseña debe tener mínimo 10 caracteres');
        }

        self::req($this->repo->rolExiste($rolId), 'El rol no existe');

        if ($id && $id === $yo) {
            self::req($activo === 1, 'No puedes desactivar tu propia cuenta');
            if ($this->repo->rolActual($id) !== $rolId) {
                throw new UserError('No puedes cambiar tu propio rol');
            }
        }

        try {
            if ($id) {
                $this->repo->actualizarUsuario($id, $nombre, $usuario, $email, $rolId, $activo);
                if ($clave !== '') {
                    $this->repo->actualizarClave($id, password_hash($clave, PASSWORD_DEFAULT));
                }
                auditar('usuario.editar', 'usuarios', $id, ['usuario' => $usuario, 'rol_id' => $rolId, 'activo' => $activo]);
            } else {
                $id = $this->repo->insertarUsuario($nombre, $usuario, $email, password_hash($clave, PASSWORD_DEFAULT), $rolId, $activo);
                auditar('usuario.crear', 'usuarios', $id, ['usuario' => $usuario, 'rol_id' => $rolId]);
            }
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new UserError('Ese usuario o correo ya existe');
            }
            throw $e;
        }
        return ['ok' => true, 'id' => $id];
    }

    /* -------------------------------- auditoría ----------------------------- */

    public function auditoria(array $g): array
    {
        $entidad = self::s($g['entidad'] ?? '', 30);
        $pagina = max(1, (int)($g['pagina'] ?? 1));
        $limite = min(100, max(1, (int)($g['limite'] ?? 30)));
        $where = '1=1';
        $args = [];
        if ($entidad !== '') {
            $where = 'a.entidad = ?';
            $args[] = $entidad;
        }

        $total = $this->repo->contarAuditoria($where, $args);
        $offset = ($pagina - 1) * $limite;
        $items = array_map(fn($x) => [
            'id' => (int)$x['id'], 'accion' => (string)$x['accion'], 'entidad' => (string)$x['entidad'],
            'entidad_id' => $x['entidad_id'] !== null ? (int)$x['entidad_id'] : null,
            'cambios' => $x['cambios'] ? json_decode((string)$x['cambios'], true) : null,
            'ip' => (string)$x['ip'], 'creado' => (string)$x['creado'], 'usuario' => $x['usuario'] ?? 'Sistema',
        ], $this->repo->listarAuditoria($where, $args, $limite, $offset));

        return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'limite' => $limite,
            'paginas' => max(1, (int)ceil($total / $limite)), 'entidades' => $this->repo->entidadesAuditoria()];
    }

    /* ------------------------------- configuración -------------------------- */

    public function configuracion(): array
    {
        $editables = [];
        foreach (self::CONFIG_EDITABLE as $clave => $meta) {
            $editables[] = ['clave' => $clave, 'valor' => $this->repo->configValor($clave), 'etiqueta' => $meta['etiqueta'], 'min' => $meta['min'], 'max' => $meta['max']];
        }
        $lectura = [];
        foreach (self::CONFIG_LECTURA as $clave) {
            $lectura[] = ['clave' => $clave, 'valor' => $this->repo->configValor($clave)];
        }
        return ['editables' => $editables, 'lectura' => $lectura];
    }

    public function configuracionGuardar(array $b): array
    {
        $d = $b['data'] ?? [];
        $cambios = [];
        foreach (self::CONFIG_EDITABLE as $clave => $meta) {
            if (!array_key_exists($clave, $d)) {
                continue;
            }
            $valor = (int)$d[$clave];
            self::req($valor >= $meta['min'] && $valor <= $meta['max'], $meta['etiqueta'] . ': entre ' . $meta['min'] . ' y ' . $meta['max']);
            $this->repo->fijarConfig($clave, (string)$valor);
            $cambios[$clave] = $valor;
        }
        if ($cambios) {
            auditar('configuracion.editar', 'configuracion', null, $cambios);
        }
        return ['ok' => true];
    }
}
