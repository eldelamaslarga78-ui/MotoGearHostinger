<?php
declare(strict_types=1);
/*
 * Outbox · cola de mensajes salientes (correo).
 *
 * Dos modos, la misma fila:
 *   1. Envío inmediato: tras confirmar el pedido se intenta enviar en el acto
 *      (ob_procesar_ids) con un tiempo límite corto. El cliente recibe el correo
 *      al instante en el caso normal.
 *   2. Red de seguridad: si el envío inmediato falla o expira, la fila queda
 *      pendiente y el cron la reintenta (ob_procesar).
 *
 * ob_encolar() se llama DENTRO de la transacción del negocio: el mensaje existe
 * si y solo si existe el pedido.
 */

const OB_MAX_INTENTOS = 5;

/** Guarda un mensaje pendiente. Pensado para ejecutarse dentro de una transacción. */
function ob_encolar(string $tipo, array $payload): int {
    db()->prepare('INSERT INTO outbox (tipo, payload) VALUES (?, ?)')
        ->execute([$tipo, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    return (int)db()->lastInsertId();
}

/** Envía un mensaje según su tipo. Devuelve true si el destino lo aceptó. */
function ob_enviar(string $tipo, array $p, int $timeout = 20): bool {
    if ($tipo === 'correo') {
        return mk_enviar_correo((string)$p['para'], (string)$p['asunto'], (string)$p['html'], $timeout);
    }
    throw new RuntimeException("Tipo de mensaje desconocido: $tipo");
}

/**
 * Intenta enviar una fila y actualiza su estado.
 * Devuelve 'enviado', 'pendiente' o 'fallido'.
 */
function ob_enviar_fila(array $m, int $timeout = 20): string {
    $pdo = db();
    $id = (int)$m['id'];
    $intentos = (int)$m['intentos'] + 1;

    try {
        $ok = ob_enviar((string)$m['tipo'], (array)json_decode((string)$m['payload'], true), $timeout);
        $error = $ok ? null : 'El servidor SMTP no aceptó el mensaje';
    } catch (Throwable $e) {
        $ok = false;
        $error = mb_substr($e->getMessage(), 0, 250);
    }

    if ($ok) {
        $pdo->prepare("UPDATE outbox SET estado = 'enviado', intentos = ?, enviado_en = NOW(), ultimo_error = NULL WHERE id = ?")
            ->execute([$intentos, $id]);
        return 'enviado';
    }

    $estado = $intentos >= OB_MAX_INTENTOS ? 'fallido' : 'pendiente';
    $pdo->prepare('UPDATE outbox SET estado = ?, intentos = ?, ultimo_error = ? WHERE id = ?')
        ->execute([$estado, $intentos, $error, $id]);
    return $estado;
}

/** Procesa hasta $limite pendientes (cron). Devuelve [enviados, fallidos, reintentos]. */
function ob_procesar(int $limite = 20): array {
    $q = db()->prepare("SELECT id, tipo, payload, intentos FROM outbox
                         WHERE estado = 'pendiente' ORDER BY id LIMIT " . (int)$limite);
    $q->execute();
    $enviados = 0; $fallidos = 0; $reintentos = 0;

    foreach ($q->fetchAll() as $m) {
        $estado = ob_enviar_fila($m);
        if ($estado === 'enviado') $enviados++;
        elseif ($estado === 'fallido') $fallidos++;
        else $reintentos++;
    }
    return [$enviados, $fallidos, $reintentos];
}

/**
 * Envío inmediato de mensajes concretos (tras crear un pedido).
 * Usa un tiempo límite corto: si no alcanza, la fila queda pendiente para el cron.
 * Nunca lanza excepciones: el pedido ya está confirmado.
 */
function ob_procesar_ids(array $ids, int $timeout = 6): array {
    if (!$ids) return [0, 0];
    $ids = array_values(array_filter(array_map('intval', $ids), fn($n) => $n > 0));
    if (!$ids) return [0, 0];

    $enviados = 0; $pendientes = 0;
    try {
        $q = db()->prepare("SELECT id, tipo, payload, intentos FROM outbox
                             WHERE id IN (" . implode(',', $ids) . ") AND estado = 'pendiente'");
        $q->execute();
        foreach ($q->fetchAll() as $m) {
            $estado = ob_enviar_fila($m, $timeout);
            if ($estado === 'enviado') $enviados++; else $pendientes++;
        }
    } catch (Throwable $e) {
        // El cron lo reintentará; no se interrumpe la respuesta al cliente.
        error_log('[MotoGear][outbox] envío inmediato falló: ' . $e->getMessage());
    }
    return [$enviados, $pendientes];
}

/** Resumen para el panel y el cron. */
function ob_estado(): array {
    $r = db()->query("SELECT estado, COUNT(*) AS n FROM outbox GROUP BY estado")->fetchAll();
    $o = ['pendiente' => 0, 'enviado' => 0, 'fallido' => 0];
    foreach ($r as $x) $o[(string)$x['estado']] = (int)$x['n'];
    return $o;
}
