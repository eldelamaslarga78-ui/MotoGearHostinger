<?php
declare(strict_types=1);
/*
 * Conexión PDO única (reemplaza al helper global db() en la nueva arquitectura).
 */
namespace App\Core;

use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct(string $host, string $nombre, string $usuario, string $clave)
    {
        $this->pdo = new PDO(
            'mysql:host=' . $host . ';dbname=' . $nombre . ';charset=utf8mb4',
            $usuario,
            $clave,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        // Colombia no tiene horario de verano: offset fijo.
        $this->pdo->exec("SET time_zone = '-05:00'");
    }

    /** Construye la conexión con las constantes DB_* de config.local.php. */
    public static function desdeConstantes(): self
    {
        return new self((string)DB_HOST, (string)DB_NAME, (string)DB_USER, (string)DB_PASS);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
