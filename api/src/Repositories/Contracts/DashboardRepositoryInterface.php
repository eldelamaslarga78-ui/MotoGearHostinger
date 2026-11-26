<?php
declare(strict_types=1);
/*
 * Contrato de acceso a datos del dashboard (métricas, alertas, notificaciones,
 * búsqueda y visitas).
 */
namespace App\Repositories\Contracts;

interface DashboardRepositoryInterface
{
    public function pedidosPorDia(string $desde, string $hasta, string $ventaSql, string $anuladoSql): array;
    public function visitasPorDia(string $desde, string $hasta): array;
    public function snapshotsRango(string $desde, string $hasta, string $hoy): array;
    public function versionDatos(): string;
    public function porDespachar(string $inSql): int;
    public function estadosActuales(): array;
    public function columnaExiste(string $tabla, string $columna): bool;
    public function stockBajoTotal(): int;
    public function stockBajoItems(int $limite): array;
    public function detenidosTotal(): int;
    public function detenidosItems(int $limite): array;
    public function mensajesFiltro(string $filtro): int;
    public function ultimoMensaje(): ?string;
    public function notificaciones(int $usuarioId): array;
    public function noLeidas(int $usuarioId): int;
    public function marcarNotificacion(int $usuarioId, ?int $id): int;
    public function pedidosRecientes(int $limite): array;
    public function buscarPedidos(string $like, ?int $id): array;
    public function buscarProductos(string $like): array;
    public function visitasPorIpHash(string $ipHash, string $fecha): int;
    public function visitaExiste(string $fecha, string $sesion, string $ruta): bool;
    public function insertarVisita(array $v): void;
    public function snapshotExiste(string $fecha): bool;
    public function guardarSnapshot(string $fecha, array $p): void;
}
