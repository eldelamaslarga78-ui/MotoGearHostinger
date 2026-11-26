<?php
declare(strict_types=1);
/*
 * Controlador delgado de administración.
 */
namespace App\Controllers;

use App\Services\AdminService;

final class AdminController
{
    public function __construct(private AdminService $admin)
    {
    }

    public function mensajes(array $g): array
    {
        return $this->admin->mensajes($g);
    }

    public function usuarios(): array
    {
        return ['items' => $this->admin->usuarios(), 'roles' => $this->admin->roles()];
    }

    public function usuarioGuardar(array $b): array
    {
        return $this->admin->usuarioGuardar($b);
    }

    public function auditoria(array $g): array
    {
        return $this->admin->auditoria($g);
    }

    public function configuracion(): array
    {
        return $this->admin->configuracion();
    }

    public function configuracionGuardar(array $b): array
    {
        return $this->admin->configuracionGuardar($b);
    }
}
