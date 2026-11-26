<?php
declare(strict_types=1);
/*
 * Caché ligera sobre `cache_kv` (60 s por defecto).
 * Si algo falla, el panel sigue funcionando sin caché: nunca lanza excepciones.
 */
namespace App\Core;

use PDO;

final class Cache
{
    public function __construct(private PDO $pdo)
    {
    }

    public function leer(string $clave): mixed
    {
        try {
            $q = $this->pdo->prepare('SELECT valor FROM cache_kv WHERE clave = ? AND expira > NOW()');
            $q->execute([substr($clave, 0, 80)]);
            $v = $q->fetchColumn();
            if ($v === false) {
                return null;
            }
            $d = json_decode((string)$v, true);
            return json_last_error() === JSON_ERROR_NONE ? $d : null;
        } catch (\Throwable $e) {
            error_log('[MotoGear][cache] ' . $e->getMessage());
            return null;
        }
    }

    public function guardar(string $clave, mixed $valor, int $ttl = 60): void
    {
        try {
            $json = json_encode($valor, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return;
            }
            $ttl = max(5, min(3600, $ttl));
            $this->pdo->prepare(
                'INSERT INTO cache_kv (clave, valor, expira)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor), expira = VALUES(expira)'
            )->execute([substr($clave, 0, 80), $json, $ttl]);
        } catch (\Throwable $e) {
            error_log('[MotoGear][cache] ' . $e->getMessage());
        }
    }

    public function limpiar(): int
    {
        try {
            $q = $this->pdo->prepare('DELETE FROM cache_kv WHERE expira < NOW()');
            $q->execute();
            return $q->rowCount();
        } catch (\Throwable $e) {
            error_log('[MotoGear][cache] ' . $e->getMessage());
            return 0;
        }
    }
}
