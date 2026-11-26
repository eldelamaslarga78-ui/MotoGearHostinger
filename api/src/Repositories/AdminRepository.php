<?php
declare(strict_types=1);
/*
 * Acceso a datos de administración (PDO preparado).
 */
namespace App\Repositories;

use App\Repositories\Contracts\AdminRepositoryInterface;
use PDO;

final class AdminRepository implements AdminRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function contarMensajes(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM mensajes')->fetchColumn();
    }

    public function mensajes(int $limite, int $offset): array
    {
        return $this->pdo->query("SELECT id, nombre, email, telefono, asunto, mensaje, creado FROM mensajes ORDER BY id DESC LIMIT $limite OFFSET $offset")->fetchAll();
    }

    public function roles(): array
    {
        return $this->pdo->query('SELECT id, nombre, descripcion FROM roles ORDER BY id')->fetchAll();
    }

    public function usuarios(): array
    {
        return $this->pdo->query('SELECT u.id, u.nombre, u.usuario, u.email, u.activo, u.rol_id, r.nombre AS rol, u.ultimo_acceso, u.totp_activo
                                   FROM usuarios u JOIN roles r ON r.id = u.rol_id ORDER BY u.id')->fetchAll();
    }

    public function rolExiste(int $id): bool
    {
        $q = $this->pdo->prepare('SELECT id FROM roles WHERE id = ?');
        $q->execute([$id]);
        return (bool)$q->fetchColumn();
    }

    public function rolActual(int $usuarioId): int
    {
        $q = $this->pdo->prepare('SELECT rol_id FROM usuarios WHERE id = ?');
        $q->execute([$usuarioId]);
        return (int)$q->fetchColumn();
    }

    public function actualizarUsuario(int $id, string $nombre, string $usuario, string $email, int $rolId, int $activo): void
    {
        $this->pdo->prepare('UPDATE usuarios SET nombre=?, usuario=?, email=?, rol_id=?, activo=? WHERE id=?')
            ->execute([$nombre, $usuario, $email, $rolId, $activo, $id]);
    }

    public function actualizarClave(int $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE usuarios SET clave_hash=? WHERE id=?')->execute([$hash, $id]);
    }

    public function insertarUsuario(string $nombre, string $usuario, string $email, string $hash, int $rolId, int $activo): int
    {
        $this->pdo->prepare('INSERT INTO usuarios (nombre, usuario, email, clave_hash, rol_id, activo) VALUES (?,?,?,?,?,?)')
            ->execute([$nombre, $usuario, $email, $hash, $rolId, $activo]);
        return (int)$this->pdo->lastInsertId();
    }

    public function contarAuditoria(string $where, array $args): int
    {
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM auditoria a WHERE $where");
        $q->execute($args);
        return (int)$q->fetchColumn();
    }

    public function listarAuditoria(string $where, array $args, int $limite, int $offset): array
    {
        $q = $this->pdo->prepare("SELECT a.id, a.accion, a.entidad, a.entidad_id, a.cambios, a.ip, a.creado, u.nombre AS usuario
                                    FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id
                                   WHERE $where ORDER BY a.id DESC LIMIT $limite OFFSET $offset");
        $q->execute($args);
        return $q->fetchAll();
    }

    public function entidadesAuditoria(): array
    {
        return $this->pdo->query('SELECT DISTINCT entidad FROM auditoria ORDER BY entidad')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function configValor(string $clave): string
    {
        $q = $this->pdo->prepare('SELECT valor FROM configuracion WHERE clave = ?');
        $q->execute([$clave]);
        return (string)$q->fetchColumn();
    }

    public function fijarConfig(string $clave, string $valor): void
    {
        $this->pdo->prepare('UPDATE configuracion SET valor = ? WHERE clave = ?')->execute([$valor, $clave]);
    }
}
