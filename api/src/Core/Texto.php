<?php
declare(strict_types=1);
/*
 * Utilidades de texto: recorte seguro y slugs.
 */
namespace App\Core;

final class Texto
{
    public static function s($valor, int $maximo): string
    {
        return mb_substr(trim((string)$valor), 0, $maximo);
    }

    public static function slug(string $texto): string
    {
        $texto = @iconv('UTF-8', 'ASCII//TRANSLIT', $texto) ?: $texto;
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($texto)), '-');
    }
}
