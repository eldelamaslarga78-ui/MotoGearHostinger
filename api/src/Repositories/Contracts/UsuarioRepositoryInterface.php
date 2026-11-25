<?php
declare(strict_types=1);
/*
 * Contrato de acceso a usuarios y a la tabla de intentos de login.
 * Permite probar los servicios sin conexión a base de datos (fakes en memoria).
 */
namespace App\Repositories\Contracts;

interface UsuarioRepositoryInterface
{
    /** @return array<string,mixed>|null  Fila de `usuarios` con la columna `rol` ya unida. */
    public function porUsuario(string $usuario): ?array;

    /** @return array<string,mixed>|null */
    public function porId(int $id): ?array;

    public function guardarTotp(int $id, ?string $secreto, bool $activo): void;

    public function tocarUltimoAcceso(int $id): void;

    public function intentosRecientes(string $ip): int;

    public function registrarIntento(string $ip): void;

    public function limpiarIntentos(string $ip): void;
}
