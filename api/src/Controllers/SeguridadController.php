<?php
declare(strict_types=1);
/*
 * Controlador delgado de seguridad/autenticación.
 * Parsea la entrada y delega en los servicios; devuelve datos para JsonResponse.
 */
namespace App\Controllers;

use App\Core\Sesion;
use App\Services\AuthService;
use App\Services\SeguridadService;

final class SeguridadController
{
    public function __construct(
        private AuthService $auth,
        private SeguridadService $seguridad,
        private Sesion $sesion,
    ) {
    }

    public function login(array $b, string $ip): array
    {
        return $this->auth->login(
            (string)($b['usuario'] ?? ''),
            (string)($b['clave'] ?? ''),
            (string)($b['codigo'] ?? ''),
            $ip
        );
    }

    public function logout(): array
    {
        return $this->auth->logout();
    }

    public function estado2fa(): array
    {
        return $this->seguridad->estado($this->uid());
    }

    public function generar2fa(): array
    {
        return $this->seguridad->generar($this->uid());
    }

    public function activar2fa(array $b): array
    {
        $this->seguridad->activar($this->uid(), (string)($b['codigo'] ?? ''));
        return ['ok' => true];
    }

    public function desactivar2fa(): array
    {
        $this->seguridad->desactivar($this->uid());
        return ['ok' => true];
    }

    private function uid(): int
    {
        return (int)$this->sesion->get('uid', 0);
    }
}
