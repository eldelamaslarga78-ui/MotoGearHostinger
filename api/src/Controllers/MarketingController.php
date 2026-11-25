<?php
declare(strict_types=1);
/*
 * Controlador delgado de marketing.
 */
namespace App\Controllers;

use App\Services\MarketingService;

final class MarketingController
{
    public function __construct(private MarketingService $marketing)
    {
    }

    public function cuponValidar(array $b): array
    {
        return $this->marketing->cuponValidar($b);
    }

    public function carrito(array $b): array
    {
        return $this->marketing->carrito($b);
    }

    public function promociones(?array $b): array
    {
        return $this->marketing->promociones($b);
    }

    public function promocionBorrar(int $id): array
    {
        return $this->marketing->promocionBorrar($id);
    }

    public function carritos(array $g): array
    {
        return $this->marketing->carritos($g);
    }

    public function carritoRecuperar(array $b): array
    {
        return $this->marketing->carritoRecuperar($b);
    }

    public function marketingResumen(): array
    {
        return $this->marketing->marketingResumen();
    }
}
