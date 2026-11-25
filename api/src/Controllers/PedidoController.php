<?php
declare(strict_types=1);
/*
 * Controlador delgado de pedidos: parsea la entrada y delega en el servicio.
 */
namespace App\Controllers;

use App\Services\PedidoService;

final class PedidoController
{
    public function __construct(private PedidoService $pedidos)
    {
    }

    public function pedidos(array $g): array
    {
        return $this->pedidos->lista($g);
    }

    public function pedidoDetalle(int $id): array
    {
        return $this->pedidos->detalle($id);
    }

    public function pedidoTransicion(array $b): array
    {
        return $this->pedidos->transicion($b);
    }

    public function pedidoPago(array $b): array
    {
        return $this->pedidos->pago($b);
    }

    public function pedidoNota(array $b): array
    {
        return $this->pedidos->nota($b);
    }

    public function guiaGuardar(array $b): array
    {
        return $this->pedidos->guia($b);
    }

    public function facturaEmitir(int $id): array
    {
        return $this->pedidos->facturaEmitir($id);
    }

    public function facturaVer(int $id): array
    {
        return $this->pedidos->facturaVer($id);
    }

    public function transportadoras(?array $b): array
    {
        return $this->pedidos->transportadoras($b);
    }
}
