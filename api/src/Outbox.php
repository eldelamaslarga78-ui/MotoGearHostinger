<?php
declare(strict_types=1);
/*
 * Outbox · cola de mensajes salientes (correo).
 *
 * ob_encolar() se llama DENTRO de la transacción del negocio: el mensaje existe
 * si y solo si existe el pedido. ob_procesar() lo envía fuera de la petición
 * del cliente (cron) y reintenta hasta MAX_INTENTOS veces.
 */

const OB_MAX_INTENTOS = 5;

/** Guarda un mensaje pendiente. Pensado para ejecutarse dentro de una transacción. */
function ob_encolar(string $tipo, array $payload): int {
    db()->prepare('INSERT INTO outbox (tipo, payload) VALUES (?, ?)')
        ->execute([$tipo, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    return (int)db()->lastInsertId();
}

/** Envía un mensaje según su tipo. Devuelve true si el destino lo aceptó. */
function ob_enviar(string $tipo, array $p): bool {
    if ($tipo === 'correo') {
        return mk_enviar_correo((string)$p['para'], (string)$p['asunto'], (string)$p['html']);
    }
    throw new RuntimeException("Tipo de mensaje desconocido: $tipo");
}

/** Procesa hasta $limite pendientes. Devuelve [enviados, fallidos, reintentos]. */
function ob_procesar(int $limite = 20): array {
    $pdo = db();
    $q = $pdo->prepare("SELECT id, tipo, payload, intentos FROM outbox
                         WHERE estado = 'pendiente' ORDER BY id LIMIT " . (int)$limite);
    $q->execute();
    $enviados = 0; $fallidos = 0; $reintentos = 0;

    foreach ($q->fetchAll() as $m) {
        $id = (int)$m['id'];
        $intentos = (int)$m['intentos'] + 1;
        try {
            $ok = ob_enviar((string)$m['tipo'], (array)json_decode((string)$m['payload'], true));
            $error = $ok ? null : 'El servidor SMTP no aceptó el mensaje';
        } catch (Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 250);
        }

        if ($ok) {
            $pdo->prepare("UPDATE outbox SET estado = 'enviado', intentos = ?, enviado_en = NOW(), ultimo_error = NULL WHERE id = ?")
                ->execute([$intentos, $id]);
            $enviados++;
        } else {
            $estado = $intentos >= OB_MAX_INTENTOS ? 'fallido' : 'pendiente';
            $pdo->prepare('UPDATE outbox SET estado = ?, intentos = ?, ultimo_error = ? WHERE id = ?')
                ->execute([$estado, $intentos, $error, $id]);
            if ($estado === 'fallido') $fallidos++; else $reintentos++;
        }
    }
    return [$enviados, $fallidos, $reintentos];
}

/** Resumen para el panel y el cron. */
function ob_estado(): array {
    $r = db()->query("SELECT estado, COUNT(*) AS n FROM outbox GROUP BY estado")->fetchAll();
    $o = ['pendiente' => 0, 'enviado' => 0, 'fallido' => 0];
    foreach ($r as $x) $o[(string)$x['estado']] = (int)$x['n'];
    return $o;
}
