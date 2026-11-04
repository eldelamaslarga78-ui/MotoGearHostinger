<?php
declare(strict_types=1);
/*
 * Seguridad (Fase 6) · Autenticación en dos pasos (TOTP, RFC 6238).
 * Sin dependencias: HMAC-SHA1 + Base32 propios.
 */

function totp_base32_encode(string $bytes): string {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0; $i < strlen($bytes); $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }
    $salida = '';
    foreach (str_split($bits, 5) as $g) {
        $salida .= $alfabeto[bindec(str_pad($g, 5, '0', STR_PAD_RIGHT))];
    }
    return $salida;
}

function totp_base32_decode(string $base32): string {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32 = strtoupper($base32);
    $bits = '';
    for ($i = 0; $i < strlen($base32); $i++) {
        $v = strpos($alfabeto, $base32[$i]);
        if ($v === false) continue;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $salida = '';
    foreach (str_split($bits, 8) as $g) {
        if (strlen($g) < 8) break;
        $salida .= chr(bindec($g));
    }
    return $salida;
}

function totp_codigo(string $secreto, int $offset = 0): string {
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

function totp_verificar(string $secreto, string $codigo): bool {
    $codigo = preg_replace('/\D+/', '', $codigo);
    if (strlen($codigo) !== 6) return false;
    for ($o = -1; $o <= 1; $o++) {
        if (hash_equals($codigo, totp_codigo($secreto, $o))) return true;
    }
    return false;
}

/** Genera el secreto y lo guarda (pendiente de activación). Devuelve el Base32. */
function seg_2fa_generar(): array {
    $uid = (int)($_SESSION['uid'] ?? 0);
    $secreto = random_bytes(20);
    db()->prepare('UPDATE usuarios SET totp_secreto = ? WHERE id = ?')->execute([$secreto, $uid]);
    $base32 = totp_base32_encode($secreto);
    $q = db()->prepare('SELECT usuario FROM usuarios WHERE id = ?');
    $q->execute([$uid]);
    $usuario = (string)$q->fetchColumn();
    return [
        'secreto' => $base32,
        'url' => 'otpauth://totp/MotoGear:' . rawurlencode($usuario) . '?secret=' . $base32 . '&issuer=MotoGear',
    ];
}

/** Activa el 2FA verificando un código del autenticador. */
function seg_2fa_activar(array $b): array {
    $uid = (int)($_SESSION['uid'] ?? 0);
    $q = db()->prepare('SELECT totp_secreto FROM usuarios WHERE id = ?');
    $q->execute([$uid]);
    $secreto = $q->fetchColumn();
    if (!$secreto) throw new UserError('Genera primero el código 2FA');
    $codigo = (string)($b['codigo'] ?? '');
    if (!totp_verificar((string)$secreto, $codigo)) throw new UserError('Código incorrecto');
    db()->prepare('UPDATE usuarios SET totp_activo = 1 WHERE id = ?')->execute([$uid]);
    auditar('usuario.2fa', 'usuarios', $uid, ['estado' => 'activado']);
    return ['ok' => true];
}

/** Desactiva el 2FA. */
function seg_2fa_desactivar(): array {
    $uid = (int)($_SESSION['uid'] ?? 0);
    db()->prepare('UPDATE usuarios SET totp_secreto = NULL, totp_activo = 0 WHERE id = ?')->execute([$uid]);
    auditar('usuario.2fa', 'usuarios', $uid, ['estado' => 'desactivado']);
    return ['ok' => true];
}

/** Estado actual del 2FA del usuario. */
function seg_2fa_estado(): array {
    $uid = (int)($_SESSION['uid'] ?? 0);
    $q = db()->prepare('SELECT totp_activo FROM usuarios WHERE id = ?');
    $q->execute([$uid]);
    return ['activo' => (bool)$q->fetchColumn()];
}
