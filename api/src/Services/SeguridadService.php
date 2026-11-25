<?php
declare(strict_types=1);
/*
 * Seguridad (Fase 6) · TOTP (RFC 6238) y ciclo de vida del 2FA.
 *
 * Implementación canónica del dominio. Los métodos estáticos son matemática
 * pura (sin dependencias); los de instancia necesitan el repositorio de
 * usuarios. El helper global totp_* de api/src/Seguridad.php delega en esta
 * clase y se eliminará al terminar la migración.
 */
namespace App\Services;

use App\Core\UserError;
use App\Repositories\Contracts\UsuarioRepositoryInterface;

final class SeguridadService
{
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private UsuarioRepositoryInterface $usuarios)
    {
    }

    /* ----------------------------- TOTP puro ------------------------------ */

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

    /* ------------------------ ciclo de vida del 2FA ------------------------ */

    /** Genera un secreto nuevo (pendiente de activación) y devuelve el Base32 + URI otpauth. */
    public function generar(int $uid): array
    {
        $u = $this->usuarios->porId($uid);
        if ($u === null) {
            throw new UserError('Usuario no encontrado');
        }
        $secreto = random_bytes(20);
        $this->usuarios->guardarTotp($uid, $secreto, false);
        $base32 = self::base32Codificar($secreto);
        return [
            'secreto' => $base32,
            'url' => 'otpauth://totp/MotoGear:' . rawurlencode((string)$u['usuario']) . '?secret=' . $base32 . '&issuer=MotoGear',
        ];
    }

    /** Activa el 2FA verificando un código del autenticador. */
    public function activar(int $uid, string $codigo): void
    {
        $u = $this->usuarios->porId($uid);
        $secreto = $u['totp_secreto'] ?? null;
        if (!$secreto) {
            throw new UserError('Genera primero el código 2FA');
        }
        if (!self::verificar((string)$secreto, $codigo)) {
            throw new UserError('Código incorrecto');
        }
        $this->usuarios->guardarTotp($uid, (string)$secreto, true);
        auditar('usuario.2fa', 'usuarios', $uid, ['estado' => 'activado']);
    }

    /** Desactiva el 2FA y borra el secreto. */
    public function desactivar(int $uid): void
    {
        $this->usuarios->guardarTotp($uid, null, false);
        auditar('usuario.2fa', 'usuarios', $uid, ['estado' => 'desactivado']);
    }

    /** Estado actual del 2FA del usuario. */
    public function estado(int $uid): array
    {
        $u = $this->usuarios->porId($uid);
        return ['activo' => (bool)($u['totp_activo'] ?? false)];
    }
}
