<?php
declare(strict_types=1);
/*
 * Abstracción de la sesión de PHP.
 * En producción envuelve $_SESSION; en pruebas se inyecta un array en memoria.
 */
namespace App\Core;

final class Sesion
{
    /** @var array<string,mixed> */
    private array $datos;

    /** @param array<string,mixed>|null $datos */
    public function __construct(?array &$datos = null)
    {
        if ($datos === null) {
            $this->datos =& $_SESSION;
        } else {
            $this->datos =& $datos;
        }
    }

    public function get(string $clave, mixed $defecto = null): mixed
    {
        return $this->datos[$clave] ?? $defecto;
    }

    public function set(string $clave, mixed $valor): void
    {
        $this->datos[$clave] = $valor;
    }

    public function remove(string $clave): void
    {
        unset($this->datos[$clave]);
    }

    public function clear(): void
    {
        $this->datos = [];
    }

    public function regenerar(bool $destruir = true): void
    {
        session_regenerate_id($destruir);
    }

    public function destruir(): void
    {
        session_destroy();
    }
}
