<?php
declare(strict_types=1);
/*
 * Contrato de acceso a datos de la cola de mensajes (outbox).
 */
namespace App\Repositories\Contracts;

interface OutboxRepositoryInterface
{
    public function encolar(string $tipo, array $payload): int;
    public function pendientes(int $limite): array;
    public function porIds(array $ids): array;
    public function marcarEnviado(int $id, int $intentos): void;
    public function marcarEstado(int $id, int $intentos, string $estado, ?string $error): void;
    public function estado(): array;
}
