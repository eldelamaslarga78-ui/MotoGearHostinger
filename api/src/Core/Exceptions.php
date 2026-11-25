<?php
declare(strict_types=1);
/*
 * Errores de dominio y HTTP.
 */
namespace App\Core;

/** Error esperado del usuario (el mensaje se muestra tal cual). */
class UserError extends \Exception
{
}

/** Error HTTP con estado propio (401/403/404/422...). */
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
