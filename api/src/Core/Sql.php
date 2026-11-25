<?php
declare(strict_types=1);
/*
 * Helpers de SQL preparado.
 */
namespace App\Core;

final class Sql
{
    /** Genera '?,?,?' para N parámetros. */
    public static function ph(int $n): string
    {
        return implode(',', array_fill(0, max(0, $n), '?'));
    }
}
