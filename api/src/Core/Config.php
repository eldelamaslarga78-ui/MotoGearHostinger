<?php
declare(strict_types=1);
/*
 * Lectura de configuración (tabla `configuracion`) con caché por petición.
 */
namespace App\Core;

use PDO;

final class Config
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function get(string $clave, string $def = ''): string
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->pdo->query('SELECT clave, valor FROM configuracion WHERE grupo IN ("correo","marketing")')->fetchAll() as $r) {
                $this->cache[(string)$r['clave']] = (string)$r['valor'];
            }
        }
        return $this->cache[$clave] ?? $def;
    }
}
