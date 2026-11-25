<?php
declare(strict_types=1);
/*
 * Outbox · cola de mensajes salientes (correo).
 *
 * Dos modos, la misma fila:
 *   1. Envío inmediato (procesarIds) tras confirmar un pedido, con tiempo corto.
 *   2. Red de seguridad: el cron reintenta (procesar).
 *
 * encolar() se llama DENTRO de la transacción del negocio.
 */
namespace App\Services;

use App\Repositories\Contracts\OutboxRepositoryInterface;

final class OutboxService
{
    private const MAX_INTENTOS = 5;

    public function __construct(private OutboxRepositoryInterface $repo, private MailService $mail)
    {
    }

    /** Guarda un mensaje pendiente. Pensado para ejecutarse dentro de una transacción. */
    public function encolar(string $tipo, array $payload): int
    {
        return $this->repo->encolar($tipo, $payload);
    }

    /** Envía un mensaje según su tipo. */
    private function enviar(string $tipo, array $p, int $timeout = 20): bool
    {
        if ($tipo === 'correo') {
            return $this->mail->enviar((string)$p['para'], (string)$p['asunto'], (string)$p['html'], $timeout);
        }
        throw new \RuntimeException("Tipo de mensaje desconocido: $tipo");
    }

    /** Intenta enviar una fila y actualiza su estado. Devuelve 'enviado', 'pendiente' o 'fallido'. */
    private function enviarFila(array $m, int $timeout = 20): string
    {
        $id = (int)$m['id'];
        $intentos = (int)$m['intentos'] + 1;

        try {
            $ok = $this->enviar((string)$m['tipo'], (array)json_decode((string)$m['payload'], true), $timeout);
            $error = $ok ? null : 'El servidor SMTP no aceptó el mensaje';
        } catch (\Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 250);
        }

        if ($ok) {
            $this->repo->marcarEnviado($id, $intentos);
            return 'enviado';
        }
        $estado = $intentos >= self::MAX_INTENTOS ? 'fallido' : 'pendiente';
        $this->repo->marcarEstado($id, $intentos, $estado, $error);
        return $estado;
    }

    /** Procesa hasta $limite pendientes (cron). Devuelve [enviados, fallidos, reintentos]. */
    public function procesar(int $limite = 20): array
    {
        $enviados = 0;
        $fallidos = 0;
        $reintentos = 0;
        foreach ($this->repo->pendientes($limite) as $m) {
            $estado = $this->enviarFila($m);
            if ($estado === 'enviado') {
                $enviados++;
            } elseif ($estado === 'fallido') {
                $fallidos++;
            } else {
                $reintentos++;
            }
        }
        return [$enviados, $fallidos, $reintentos];
    }

    /**
     * Envío inmediato de mensajes concretos (tras crear un pedido).
     * Nunca lanza excepciones: el pedido ya está confirmado.
     */
    public function procesarIds(array $ids, int $timeout = 6): array
    {
        if (!$ids) {
            return [0, 0];
        }
        $ids = array_values(array_filter(array_map('intval', $ids), fn($n) => $n > 0));
        if (!$ids) {
            return [0, 0];
        }

        $enviados = 0;
        $pendientes = 0;
        try {
            foreach ($this->repo->porIds($ids) as $m) {
                $estado = $this->enviarFila($m, $timeout);
                if ($estado === 'enviado') {
                    $enviados++;
                } else {
                    $pendientes++;
                }
            }
        } catch (\Throwable $e) {
            error_log('[MotoGear][outbox] envío inmediato falló: ' . $e->getMessage());
        }
        return [$enviados, $pendientes];
    }

    /** Resumen para el panel y el cron. */
    public function estado(): array
    {
        return $this->repo->estado();
    }
}
