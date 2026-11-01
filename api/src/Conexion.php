<?php
declare(strict_types=1);
/*
 * Conexión PDO única del backoffice.
 * Requiere que api/config.php ya se haya cargado (constantes DB_*).
 */

defined('MG_APP') || exit;

function db(): PDO
{
    static $p = null;
    if ($p instanceof PDO) {
        return $p;
    }

    $p = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // Colombia no tiene horario de verano: offset fijo. Así NOW() y los DATETIME
    // del panel caen siempre en America/Bogota, sin importar la zona del servidor.
    $p->exec("SET time_zone = '-05:00'");

    return $p;
}
