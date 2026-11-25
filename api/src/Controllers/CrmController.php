<?php
declare(strict_types=1);
/*
 * Controlador delgado de CRM/RMA.
 */
namespace App\Controllers;

use App\Services\CrmService;

final class CrmController
{
    public function __construct(private CrmService $crm)
    {
    }

    public function clientes(array $g): array
    {
        return $this->crm->clientes($g);
    }

    public function clienteDetalle(int $id): array
    {
        return $this->crm->clienteDetalle($id);
    }

    public function clienteGuardar(array $b): array
    {
        return $this->crm->clienteGuardar($b);
    }

    public function clienteDireccion(array $b): array
    {
        return $this->crm->clienteDireccion($b);
    }

    public function clienteFusionar(array $b): array
    {
        return $this->crm->clienteFusionar($b);
    }

    public function devoluciones(array $g): array
    {
        return $this->crm->devoluciones($g);
    }

    public function devolucionCrear(array $b): array
    {
        return $this->crm->devolucionCrear($b);
    }

    public function devolucionTransicion(array $b): array
    {
        return $this->crm->devolucionTransicion($b);
    }
}
