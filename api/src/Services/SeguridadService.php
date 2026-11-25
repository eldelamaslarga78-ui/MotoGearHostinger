<?php
declare(strict_types=1);
/*
 * Seguridad (Fase 6) · TOTP (RFC 6238) sin dependencias externas.
 *
 * Dominio migrado a la nueva arquitectura: la implementación canónica vive aquí.
 * Los helpers globales totp_* de api/src/Seguridad.php delegan en esta clase
 * (se eliminarán cuando se complete la migración del dominio de autenticación).
 */
namespace App\Services;

final class SeguridadService
{
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Codificar(string $bytes): string
    {
        $bits = '';
        $n = strlen($bytes);
        for ($i = 0; $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        $salida = '';
        foreach (str_split($bits, 5) as $g) {
            $salida .= self::ALFABETO[bindec(str_pad($g, 5, '0', STR_PAD_RIGHT))];
        }
        return $salida;
    }

    public static function base32Decodificar(string $base32): string
    {
        $base32 = strtoupper($base32);
        $bits = '';
        $n = strlen($base32);
        for ($i = 0; $i < $n; $i++) {
            $v = strpos(self::ALFABETO, $base32[$i]);
            if ($v === false) {
                continue;
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $salida = '';
        foreach (str_split($bits, 8) as $g) {
            if (strlen($g) < 8) {
                break;
            }
            $salida .= chr(bindec($g));
        }
        return $salida;
    }

    public static function codigo(string $secreto, int $offset = 0): string
    {
        $contador = intdiv((int)time(), 30) + $offset;
        $msg = pack('N2', 0, $contador);
        $hash = hash_hmac('sha1', $msg, $secreto, true);
        $o = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binario = ((ord($hash[$o]) & 0x7F) << 24)
            | ((ord($hash[$o + 1]) & 0xFF) << 16)
            | ((ord($hash[$o + 2]) & 0xFF) << 8)
            | (ord($hash[$o + 3]) & 0xFF);
        return str_pad((string)($binario % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verificar(string $secreto, string $codigo): bool
    {
        $codigo = preg_replace('/\D+/', '', $codigo);
        if (strlen($codigo) !== 6) {
            return false;
        }
        for ($o = -1; $o <= 1; $o++) {
            if (hash_equals($codigo, self::codigo($secreto, $o))) {
                return true;
            }
        }
        return false;
    }
}
