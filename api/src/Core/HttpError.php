<?php
declare(strict_types=1);
/*
 * Error HTTP con estado propio (401/403/404/422...).
 */
namespace App\Core;

class HttpError extends \Exception
{
    private int $estado;

    public function __construct(string $mensaje, int $estado = 422)
    {
        parent::__construct($mensaje);
        $this->estado = $estado;
    }

    public function estado(): int
    {
        return $this->estado;
    }
}
