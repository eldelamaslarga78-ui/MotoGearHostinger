<?php
declare(strict_types=1);
/*
 * Acceso a datos de usuarios e intentos de login (PDO preparado).
 * Único lugar de este dominio donde se escribe SQL.
 */
namespace App\Repositories;

use App\Repositories\Contracts\UsuarioRepositoryInterface;
use PDO;

final class UsuarioRepository implements UsuarioRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function porUsuario(string $usuario): ?array
    {
        $q = $this->pdo->prepare(
            'SELECT u.*, r.nombre AS rol
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE u.usuario = ? AND u.activo = 1'
        );
        $q->execute([$usuario]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function porId(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
        $q->execute([$id]);
        $f = $q->fetch();
        return $f === false ? null : $f;
    }

    public function guardarTotp(int $id, ?string $secreto, bool $activo): void
    {
        $this->pdo->prepare('UPDATE usuarios SET totp_secreto = ?, totp_activo = ? WHERE id = ?')
            ->execute([$secreto, $activo ? 1 : 0, $id]);
    }

    public function tocarUltimoAcceso(int $id): void
    {
        $this->pdo->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?')->execute([$id]);
    }

    public function intentosRecientes(string $ip): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM intentos WHERE ip = ? AND creado > NOW() - INTERVAL 15 MINUTE');
        $q->execute([$ip]);
        return (int)$q->fetchColumn();
    }

    public function registrarIntento(string $ip): void
    {
        $this->pdo->prepare('INSERT INTO intentos (ip) VALUES (?)')->execute([$ip]);
    }

    public function limpiarIntentos(string $ip): void
    {
        $this->pdo->prepare('DELETE FROM intentos WHERE ip = ?')->execute([$ip]);
    }
}
