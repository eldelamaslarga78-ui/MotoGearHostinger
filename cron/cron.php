<?php
declare(strict_types=1);
/*
 * Cron del backoffice · Fase 1
 *
 * Solo se ejecuta desde la línea de comandos y exige token:
 *   php /home/uXXXXXX/cron/cron.php TOKEN
 *
 * Programa esta tarea cada 10 minutos en hPanel. Hace tres cosas:
 *   1. Rellena el snapshot de los últimos 7 días que falte (base de los KPIs).
 *   2. Borra la caché vencida de `cache_kv`.
 *   3. Poda visitas viejas y notificaciones leídas antiguas.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Solo CLI\n");
}

$token = (string)($argv[1] ?? (getenv('MG_CRON_TOKEN') ?: ''));

require __DIR__ . '/../api/config.php';

if (!defined('CRON_TOKEN') || (string)CRON_TOKEN === '') {
    fwrite(STDERR, "[cron] Falta CRON_TOKEN en config.local.php\n");
    exit(1);
}
if (!hash_equals((string)CRON_TOKEN, $token)) {
    fwrite(STDERR, "[cron] Token inválido\n");
    exit(1);
}

define('MG_APP', true);
date_default_timezone_set('America/Bogota');

require __DIR__ . '/../api/src/Conexion.php';
require __DIR__ . '/../api/src/Cache.php';
require __DIR__ . '/../api/src/Metricas.php';
require __DIR__ . '/../api/src/Pedidos.php';
require __DIR__ . '/../api/src/CRM.php';
require __DIR__ . '/../api/src/Marketing.php';

$inicio = microtime(true);

try {
    $snapshots = mg_snapshots_reparar(7);
    $cache     = cache_limpiar();
    $visitas   = db()->exec('DELETE FROM visitas WHERE fecha < CURDATE() - INTERVAL 180 DAY');
    $notifs    = db()->exec('DELETE FROM notificaciones WHERE leida = 1 AND creado < NOW() - INTERVAL 90 DAY');
    $expirados = ped_expirar_pendientes();
    $abandonos = mk_marcar_abandonos();
    $recordatorios = mk_enviar_recordatorios();

    printf(
        "[cron] ok snapshots=%d cache=%d visitas=%d notificaciones=%d expirados=%d abandonos=%d recordatorios=%d %.0fms\n",
        $snapshots,
        $cache,
        (int)$visitas,
        (int)$notifs,
        $expirados,
        $abandonos,
        $recordatorios,
        (microtime(true) - $inicio) * 1000
    );
} catch (Throwable $e) {
    fwrite(STDERR, '[cron] error: ' . $e->getMessage() . "\n");
    exit(1);
}
