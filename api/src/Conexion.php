<?php
declare(strict_types=1);
/*
 * Conexión PDO única del backoffice.
 * Requiere que api/config.php ya se haya cargado (constantes DB_*).
 */

defined('MG_APP') || exit;

/*
 * Conexión PDO única. Delega en App\Core\Database (nueva arquitectura): así el
 * código legacy y el nuevo comparten exactamente la misma conexión.
 */
function db(): PDO
{
    return \App\Core\Database::singleton()->pdo();
}
