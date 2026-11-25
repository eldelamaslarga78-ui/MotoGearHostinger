<?php
declare(strict_types=1);
/*
 * Router por ?r=: registra manejadores y despacha la petición.
 * (El switch actual de api.php se reemplazará por este router dominio a dominio.)
 */
namespace App\Core;

final class Router
{
    /** @var array<string, callable(Request):mixed> */
    private array $rutas = [];

    public function on(string $r, callable $manejador): self
    {
        $this->rutas[$r] = $manejador;
        return $this;
    }

    public function dispatch(Request $peticion): void
    {
        $r = $peticion->ruta();
        $manejador = $this->rutas[$r] ?? null;
        if ($manejador === null) {
            JsonResponse::send(['error' => 'Ruta no encontrada'], 404);
        }
        JsonResponse::send($manejador($peticion));
    }
}
