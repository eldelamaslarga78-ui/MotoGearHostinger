<?php
declare(strict_types=1);
/*
 * Contrato de acceso a datos de administración (mensajes, usuarios, roles,
 * auditoría y configuración).
 */
namespace App\Repositories\Contracts;

interface AdminRepositoryInterface
{
    public function contarMensajes(): int;
    public function mensajes(int $limite, int $offset): array;
    public function roles(): array;
    public function usuarios(): array;
    public function rolExiste(int $id): bool;
    public function rolActual(int $usuarioId): int;
    public function actualizarUsuario(int $id, string $nombre, string $usuario, string $email, int $rolId, int $activo): void;
    public function actualizarClave(int $id, string $hash): void;
    public function insertarUsuario(string $nombre, string $usuario, string $email, string $hash, int $rolId, int $activo): int;
    public function contarAuditoria(string $where, array $args): int;
    public function listarAuditoria(string $where, array $args, int $limite, int $offset): array;
    public function entidadesAuditoria(): array;
    public function configValor(string $clave): string;
    public function fijarConfig(string $clave, string $valor): void;
}
