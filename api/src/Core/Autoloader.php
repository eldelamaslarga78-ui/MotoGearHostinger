<?php
declare(strict_types=1);
/*
 * Autoloader PSR-4 nativo (sin Composer).
 * Mapea el namespace raíz `App\` a `api/src/`.
 */
namespace App\Core;

final class Autoloader
{
    private const PREFIJO = 'App\\';
    private string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');
    }

    public function register(): void
    {
        spl_autoload_register([$this, 'cargar']);
    }

    public function cargar(string $clase): void
    {
        if (strncmp($clase, self::PREFIJO, strlen(self::PREFIJO)) !== 0) {
            return;
        }
        $rel = substr($clase, strlen(self::PREFIJO));
        $ruta = $this->baseDir . '/' . str_replace('\\', '/', $rel) . '.php';
        if (is_file($ruta)) {
            require $ruta;
        }
    }
}
