<?php
declare(strict_types=1);
/*
 * Helpers comunes de la API: errores de usuario, validacion y utilidades de texto.
 * Separados de api.php para poder probarlos sin ejecutar el router.
 */

class UserError extends Exception {}
function req(bool $ok, string $msg): void { if (!$ok) throw new UserError($msg); }
function s($v, int $max): string { return mb_substr(trim((string)$v), 0, $max); }
function slug(string $t): string {
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $t) ?: $t;
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-');
}
function ph(int $n): string { return implode(',', array_fill(0, $n, '?')); }
