<?php
declare(strict_types=1);
/*
 * Controlador delgado del checkout (creación del pedido).
 */
namespace App\Controllers;

use App\Services\CheckoutService;

final class CheckoutController
{
    public function __construct(private CheckoutService $checkout)
    {
    }

    public function crear(array $b, string $ip, string $idem): array
    {
        return $this->checkout->crear($b, $ip, $idem);
    }
}
