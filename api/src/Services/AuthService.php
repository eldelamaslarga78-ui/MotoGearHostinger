<?php
declare(strict_types=1);
/*
 * Autenticación: login, logout y la sesión de trabajo.
 * Nota de migración: `auditar()` sigue siendo el helper global (se moverá a
 * un AuditoriaRepository cuando se migre Core\Guard).
 */
namespace App\Services;

use App\Core\HttpError;
use App\Core\Sesion;
use App\Repositories\Contracts\UsuarioRepositoryInterface;

final class AuthService
{
    private const HASH_NULO = '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';

    public function __construct(
        private UsuarioRepositoryInterface $usuarios,
        private Sesion $sesion,
    ) {
    }

    public function login(string $usuario, string $clave, string $codigo, string $ip): array
    {
        $usuario = trim($usuario);
        if (mb_strlen($usuario) > 40) {
            $usuario = mb_substr($usuario, 0, 40);
        }

        if ($this->usuarios->intentosRecientes($ip) >= 8) {
            throw new HttpError('Demasiados intentos. Espera 15 minutos.', 429);
        }

        $a = $this->usuarios->porUsuario($usuario);
        $hash = is_array($a) ? ($a['clave_hash'] ?? self::HASH_NULO) : self::HASH_NULO;
        if ($a === null || !password_verify($clave, (string)$hash)) {
            $this->usuarios->registrarIntento($ip);
            sleep(1);
            throw new HttpError('Usuario o contraseña incorrectos', 401);
        }

        // 2FA: si está activo, exige el código del autenticador.
        if (!empty($a['totp_activo'])) {
            if ($codigo === '') {
                return ['requiere_2fa' => true];
            }
            if (!SeguridadService::verificar((string)$a['totp_secreto'], $codigo)) {
                $this->usuarios->registrarIntento($ip);
                throw new HttpError('Código de verificación incorrecto', 401);
            }
        }

        $this->usuarios->limpiarIntentos($ip);
        $this->sesion->regenerar(true);
        $this->sesion->set('uid', (int)$a['id']);
        $this->sesion->set('rol', (string)$a['rol']);
        $this->sesion->set('csrf', bin2hex(random_bytes(16)));
        $this->sesion->remove('admin');
        $this->usuarios->tocarUltimoAcceso((int)$a['id']);
        auditar('sesion.login', 'usuarios', (int)$a['id']);
        return ['ok' => true, 'csrf' => $this->sesion->get('csrf')];
    }

    public function logout(): array
    {
        auditar('sesion.logout', 'usuarios', (int)$this->sesion->get('uid', 0) ?: null);
        $this->sesion->clear();
        $this->sesion->destruir();
        return ['ok' => true];
    }
}
