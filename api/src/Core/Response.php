<?php
declare(strict_types=1);
/*
 * Respuesta JSON estándar del backoffice.
 */
namespace App\Core;

final class JsonResponse
{
    public static function send($datos, int $estado = 200): void
    {
        http_response_code($estado);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
