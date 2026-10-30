<?php
declare(strict_types=1);
/*
 * Carga de credenciales de la base de datos.
 *
 * ESTE ARCHIVO SÍ SE VERSIONA: no contiene secretos.
 * Los datos reales viven en `config.local.php`, que está en .gitignore.
 *
 * Orden de búsqueda:
 *   1. Variable de entorno MG_CONFIG (ruta absoluta a un .php)
 *   2. <raíz del hosting>/config.local.php   ← un nivel ARRIBA de public_html (recomendado)
 *   3. api/config.local.php                  ← alternativa, queda dentro de public_html
 */

$mgRutas = [];
$mgEnv = getenv('MG_CONFIG');
if (is_string($mgEnv) && $mgEnv !== '') {
    $mgRutas[] = $mgEnv;
}
$mgRutas[] = dirname(__DIR__, 2) . '/config.local.php';
$mgRutas[] = __DIR__ . '/config.local.php';

$mgCargado = null;
foreach ($mgRutas as $mgRuta) {
    if (is_file($mgRuta)) {
        require $mgRuta;
        $mgCargado = $mgRuta;
        break;
    }
}
unset($mgRutas, $mgRuta, $mgEnv);

// Si el archivo está en un lugar accesible por web, avisamos en el log (no en la respuesta).
if ($mgCargado !== null && strpos(str_replace('\\', '/', $mgCargado), '/api/') !== false) {
    error_log('[MotoGear] config.local.php está dentro de public_html; muévelo un nivel arriba.');
}
unset($mgCargado);

foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $mgConst) {
    if (!defined($mgConst)) {
        error_log('[MotoGear] Falta config.local.php o le faltan constantes DB_*');
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"Configuración del servidor incompleta"}';
        exit;
    }
}
unset($mgConst);
