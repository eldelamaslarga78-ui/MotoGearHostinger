<?php
declare(strict_types=1);
/*
 * Controlador delgado del dashboard.
 */
namespace App\Controllers;

use App\Services\DashboardService;

final class DashboardController
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    private function uid(): int
    {
        return (int)($_SESSION['uid'] ?? 0);
    }

    public function kpis(array $g): array
    {
        [$desde, $hasta] = DashboardService::rango($g['desde'] ?? null, $g['hasta'] ?? null);
        return $this->dashboard->kpis($desde, $hasta);
    }

    public function alertas(): array
    {
        return $this->dashboard->alertas();
    }

    public function notificaciones(): array
    {
        return $this->dashboard->notificaciones($this->uid());
    }

    public function notificacionLeer(?int $id): array
    {
        return ['ok' => true, 'marcadas' => $this->dashboard->notificacionLeer($this->uid(), $id)];
    }

    public function pedidosRecientes(int $limite): array
    {
        return $this->dashboard->pedidosRecientes($limite);
    }

    public function buscar(string $q): array
    {
        return $this->dashboard->buscar($q);
    }

    public function visita(array $b): array
    {
        return $this->dashboard->visita($b);
    }
}
