<?php
declare(strict_types=1);
/*
 * Marketing (Fase 5) · Cupones avanzados, promociones, carritos y correo.
 */

/* ------------------------------ correo (SMTP) ---------------------------- */

function mk_config(string $clave, string $def = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT clave, valor FROM configuracion WHERE grupo IN ("correo","marketing")')->fetchAll() as $r) {
            $cache[(string)$r['clave']] = (string)$r['valor'];
        }
    }
    return $cache[$clave] ?? $def;
}

/**
 * Envía un correo por SMTP (Hostinger, SSL 465).
 * Lee y valida cada respuesta del servidor: solo devuelve true si el mensaje
 * fue aceptado (250 tras el cuerpo). Cualquier otro código se registra en el log.
 */
function mk_enviar_correo(string $para, string $asunto, string $html): bool {
    $host = mk_config('smtp_host');
    $puerto = (int)mk_config('smtp_puerto', '465');
    $usuario = mk_config('smtp_usuario');
    $clave = defined('SMTP_CLAVE') ? (string)SMTP_CLAVE : mk_config('smtp_clave');
    $remitente = mk_config('smtp_remitente', $usuario);
    if ($host === '' || $usuario === '' || $clave === '' || filter_var($para, FILTER_VALIDATE_EMAIL) === false) {
        error_log('[MotoGear][correo] SMTP sin configurar o destinatario no válido');
        return false;
    }

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client("ssl://$host:$puerto", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log("[MotoGear][correo] conexión fallida: $errno $errstr");
        return false;
    }
    stream_set_timeout($fp, 20);

    // Lee una respuesta completa (incluye las líneas "250-" de EHLO).
    $leer = function () use ($fp): string {
        $t = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $t .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        return $t;
    };
    $paso = function (string $c, array $esperados) use ($fp, $leer): bool {
        fwrite($fp, $c);
        $r = $leer();
        if (!in_array((int)substr($r, 0, 3), $esperados, true)) {
            error_log('[MotoGear][correo] SMTP rechazó "' . trim(strtok($c, " \r\n")) . '": ' . trim($r));
            return false;
        }
        return true;
    };

    $ok = false;
    if ($leer() !== '' && $paso("EHLO localhost\r\n", [250])
        && $paso("AUTH LOGIN\r\n", [334])
        && $paso(base64_encode($usuario) . "\r\n", [334])
        && $paso(base64_encode($clave) . "\r\n", [235])
        && $paso("MAIL FROM:<$usuario>\r\n", [250])
        && $paso("RCPT TO:<$para>\r\n", [250, 251])
        && $paso("DATA\r\n", [354])
    ) {
        $asuntoCod = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
        // Dot-stuffing: una línea que empiece por "." debe duplicarse.
        $cuerpo = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $html));
        $cuerpo = str_replace("\n", "\r\n", $cuerpo);
        $mensaje = "From: $remitente\r\n"
            . "To: <$para>\r\n"
            . "Subject: $asuntoCod\r\n"
            . "Date: " . date('r') . "\r\n"
            . "Message-ID: <" . bin2hex(random_bytes(12)) . "@sebasdigital.site>\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $cuerpo . "\r\n.\r\n";
        $ok = $paso($mensaje, [250]);
    }
    if ($ok) {
        fwrite($fp, "QUIT\r\n");
    }
    fclose($fp);
    return $ok;
}

/* --------------------------- validación de cupón ------------------------- */

/** Valida un cupón avanzado y calcula el descuento. [cupón, error, descuento] */
function mk_cupon(?array $c, int $subtotal, int $clienteId, int $pedidosEntregados): array {
    if (!$c) return [null, 'Cupón no válido'];
    if (!(int)$c['activo']) return [null, 'Cupón no válido'];
    if ($c['desde'] && $c['desde'] > date('Y-m-d H:i:s')) return [null, 'Cupón aún no vigente'];
    if ($c['vence'] && $c['vence'] < date('Y-m-d')) return [null, 'Cupón vencido'];
    if ($subtotal < (int)$c['minimo']) return [null, 'Compra mínima de $' . number_format((int)$c['minimo'], 0, ',', '.')];
    if ($c['usos_max'] !== null && (int)$c['usos'] >= (int)$c['usos_max']) return [null, 'Cupón agotado'];

    if ($clienteId && $c['max_por_cliente'] !== null) {
        $q = db()->prepare('SELECT COUNT(*) FROM cupon_usos WHERE cupon_codigo = ? AND pedido_id IN (SELECT id FROM pedidos WHERE cliente_id = ?)');
        $q->execute([$c['codigo'], $clienteId]);
        if ((int)$q->fetchColumn() >= (int)$c['max_por_cliente']) return [null, 'Ya usaste este cupón el máximo de veces'];
    }
    if ((int)$c['primera_compra'] && $pedidosEntregados > 0) return [null, 'Este cupón es solo para tu primera compra'];

    $tipo = (string)($c['tipo'] ?? 'pct');
    $valor = (int)($c['valor'] ?? 0);
    if ($valor === 0 && isset($c['pct']) && (int)$c['pct'] > 0) $valor = (int)$c['pct'];
    $descuento = 0;
    if ($tipo === 'fijo') {
        $descuento = min($valor, $subtotal);
    } elseif ($tipo === 'pct') {
        $descuento = intdiv($subtotal * min(100, $valor), 100);
    } elseif ($tipo === 'envio_gratis') {
        $descuento = 0; // se aplica sobre el envío, no el subtotal
    }
    return [$c, null, $descuento];
}

/* ------------------------------- promociones ----------------------------- */

/** Evalúa las promociones activas y devuelve el descuento total + detalle. */
function mk_evaluar_promociones(array $lineas, int $subtotal): array {
    $promos = db()->query('SELECT * FROM promociones WHERE activa = 1 AND (desde IS NULL OR desde <= NOW()) AND (hasta IS NULL OR hasta >= NOW()) ORDER BY prioridad, id')->fetchAll();
    $descuento = 0;
    $detalle = [];

    foreach ($promos as $p) {
        $cond = json_decode((string)$p['condiciones'], true) ?: [];
        $ben = json_decode((string)$p['beneficios'], true) ?: [];
        $itemsIds = array_column(db()->query('SELECT producto_id, categoria_id FROM promocion_items WHERE promocion_id = ' . (int)$p['id'])->fetchAll(), null);

        $elegibles = [];
        foreach ($lineas as $l) {
            $ok = !$itemsIds;
            foreach ($itemsIds as $pi) {
                if ($pi['producto_id'] && (int)$l['producto_id'] === (int)$pi['producto_id']) { $ok = true; break; }
                if ($pi['categoria_id'] && $l['categoria'] === $pi['categoria_id']) { $ok = true; break; }
            }
            if ($ok) $elegibles[] = $l;
        }
        if (!$elegibles) continue;

        $d = 0;
        $tipo = (string)$p['tipo'];
        if ($tipo === 'descuento_categoria') {
            $pct = (int)($ben['pct'] ?? 0);
            foreach ($elegibles as $l) {
                $d += intdiv((int)$l['precio'] * (int)$l['cantidad'] * $pct, 100);
            }
        } elseif ($tipo === 'volumen') {
            $desde = (int)($ben['desde'] ?? 0);
            $pct = (int)($ben['pct'] ?? 0);
            $unidades = array_sum(array_column($elegibles, 'cantidad'));
            if ($unidades >= $desde) {
                foreach ($elegibles as $l) {
                    $d += intdiv((int)$l['precio'] * (int)$l['cantidad'] * $pct, 100);
                }
            }
        } elseif ($tipo === 'nxm') {
            $lleva = (int)($ben['lleva'] ?? 2);
            $paga = (int)($ben['paga'] ?? 1);
            $gratis = max(0, $lleva - $paga);
            // Aplica a las unidades más baratas del grupo
            $todas = [];
            foreach ($elegibles as $l) {
                for ($i = 0; $i < (int)$l['cantidad']; $i++) $todas[] = (int)$l['precio'];
            }
            sort($todas);
            $grupos = intdiv(count($todas), $lleva);
            for ($g = 0; $g < $grupos; $g++) {
                $ini = $g * $lleva;
                for ($f = 0; $f < $gratis; $f++) {
                    if (isset($todas[$ini + $f])) $d += $todas[$ini + $f];
                }
            }
        } elseif ($tipo === 'combo') {
            $pct = (int)($ben['pct'] ?? 10);
            $requeridos = count($itemsIds);
            $presentes = 0;
            foreach ($itemsIds as $pi) {
                foreach ($lineas as $l) {
                    if (($pi['producto_id'] && (int)$l['producto_id'] === (int)$pi['producto_id'])
                        || ($pi['categoria_id'] && $l['categoria'] === $pi['categoria_id'])) { $presentes++; break; }
                }
            }
            if ($presentes >= $requeridos && $requeridos > 0) {
                foreach ($elegibles as $l) {
                    $d += intdiv((int)$l['precio'] * (int)$l['cantidad'] * $pct, 100);
                }
            }
        }

        if ($d > 0) {
            $descuento += $d;
            $detalle[] = ['nombre' => (string)$p['nombre'], 'descuento' => $d];
            if ((int)$p['exclusiva']) {
                break; // las exclusivas no se acumulan con más promociones ni cupones
            }
        }
    }
    return ['descuento' => $descuento, 'detalle' => $detalle, 'exclusiva' => !empty($detalle) && !empty($promos) && (bool)$promos[0]['exclusiva']];
}

function mk_promociones(?array $b = null): array {
    if ($b !== null && isset($b['data'])) {
        $d = $b['data'];
        $id = (int)($d['id'] ?? 0);
        $nombre = s($d['nombre'] ?? '', 80);
        $tipo = s($d['tipo'] ?? '', 30);
        $prioridad = (int)($d['prioridad'] ?? 10);
        $desde = ($d['desde'] ?? '') === '' ? null : s($d['desde'], 19);
        $hasta = ($d['hasta'] ?? '') === '' ? null : s($d['hasta'], 19);
        $activa = !empty($d['activa']) ? 1 : 0;
        $exclusiva = !empty($d['exclusiva']) ? 1 : 0;
        $condiciones = json_encode($d['condiciones'] ?? [], JSON_UNESCAPED_UNICODE);
        $beneficios = json_encode($d['beneficios'] ?? [], JSON_UNESCAPED_UNICODE);

        req($nombre !== '' && in_array($tipo, ['nxm', 'volumen', 'combo', 'descuento_categoria'], true), 'Nombre y tipo son obligatorios');

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE promociones SET nombre=?, tipo=?, prioridad=?, desde=?, hasta=?, condiciones=?, beneficios=?, activa=?, exclusiva=? WHERE id=?')
                    ->execute([$nombre, $tipo, $prioridad, $desde, $hasta, $condiciones, $beneficios, $activa, $exclusiva, $id]);
            } else {
                $pdo->prepare('INSERT INTO promociones (nombre, tipo, prioridad, desde, hasta, condiciones, beneficios, activa, exclusiva) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$nombre, $tipo, $prioridad, $desde, $hasta, $condiciones, $beneficios, $activa, $exclusiva]);
                $id = (int)$pdo->lastInsertId();
            }
            // Alcance
            $pdo->prepare('DELETE FROM promocion_items WHERE promocion_id = ?')->execute([$id]);
            foreach (($d['items'] ?? []) as $it) {
                $pdo->prepare('INSERT INTO promocion_items (promocion_id, producto_id, categoria_id) VALUES (?,?,?)')
                    ->execute([$id, ($it['producto_id'] ?? '') === '' ? null : (int)$it['producto_id'], ($it['categoria_id'] ?? '') === '' ? null : s($it['categoria_id'], 40)]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'id' => $id];
    }
    return db()->query('SELECT * FROM promociones ORDER BY prioridad, id')->fetchAll();
}

/* -------------------------------- carritos ------------------------------- */

function mk_carrito(array $b): array {
    $token = s($b['token'] ?? '', 36);
    $email = strtolower(s($b['email'] ?? '', 120));
    $telefono = s($b['telefono'] ?? '', 20);
    $items = $b['items'] ?? [];

    req(preg_match('/^[a-f0-9-]{36}$/i', $token) === 1, 'Token de carrito no válido');
    req($email !== '', 'El correo es obligatorio para guardar el carrito');

    $clienteId = ($email !== '') ? crm_cliente_por_datos('', $email, $telefono) : null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT id FROM carritos WHERE token = ?');
        $q->execute([$token]);
        $carritoId = (int)$q->fetchColumn();

        if (!$carritoId) {
            $pdo->prepare('INSERT INTO carritos (token, cliente_id, email, telefono) VALUES (?,?,?,?)')
                ->execute([$token, $clienteId, $email, $telefono === '' ? null : $telefono]);
            $carritoId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE carritos SET email = ?, telefono = ?, ultima_actividad = NOW() WHERE id = ?')
                ->execute([$email, $telefono === '' ? null : $telefono, $carritoId]);
            $pdo->prepare('DELETE FROM carrito_items WHERE carrito_id = ?')->execute([$carritoId]);
        }

        $subtotal = 0;
        foreach ($items as $it) {
            $vid = (int)($it['variante_id'] ?? 0);
            $cant = max(1, (int)($it['cantidad'] ?? 1));
            $q = $pdo->prepare('SELECT v.precio, p.precio AS base FROM variantes v JOIN productos p ON p.id = v.producto_id WHERE v.id = ?');
            $q->execute([$vid]);
            $v = $q->fetch();
            if (!$v) continue;
            $precio = (int)$v['base'] + (int)$v['precio'];
            $pdo->prepare('INSERT INTO carrito_items (carrito_id, variante_id, cantidad, precio) VALUES (?,?,?,?)')
                ->execute([$carritoId, $vid, $cant, $precio]);
            $subtotal += $precio * $cant;
        }
        $pdo->prepare('UPDATE carritos SET subtotal = ? WHERE id = ?')->execute([$subtotal, $carritoId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'carrito_id' => $carritoId, 'subtotal' => $subtotal];
}

function mk_carritos(array $g): array {
    $pagina = max(1, (int)($g['pagina'] ?? 1));
    $limite = min(100, max(1, (int)($g['limite'] ?? 30)));
    $offset = ($pagina - 1) * $limite;
    $q = db()->prepare('SELECT COUNT(*) FROM carritos WHERE estado = "abandonado"');
    $q->execute();
    $total = (int)$q->fetchColumn();
    $q = db()->prepare('SELECT c.*, (SELECT COUNT(*) FROM carrito_items i WHERE i.carrito_id = c.id) AS n_items
                          FROM carritos c WHERE c.estado = "abandonado" ORDER BY c.ultima_actividad DESC LIMIT ' . $limite . ' OFFSET ' . $offset);
    $q->execute();
    $items = array_map(fn($x) => [
        'id' => (int)$x['id'], 'email' => $x['email'], 'telefono' => $x['telefono'],
        'subtotal' => (int)$x['subtotal'], 'n_items' => (int)$x['n_items'],
        'ultima_actividad' => (string)$x['ultima_actividad'], 'recordatorios' => (int)$x['recordatorios'],
    ], $q->fetchAll());
    return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'limite' => $limite, 'paginas' => max(1, (int)ceil($total / $limite))];
}

function mk_carrito_recuperar(array $b): array {
    $id = (int)($b['id'] ?? 0);
    $q = db()->prepare('SELECT * FROM carritos WHERE id = ?');
    $q->execute([$id]);
    $c = $q->fetch();
    if (!$c || !$c['email']) throw new UserError('Carrito sin correo');

    $enlace = 'https://ecomerce.sebasdigital.site/carrito.html?token=' . urlencode($c['token']);
    $html = '<p>Hola,</p><p>Dejaste artículos en tu carrito de MotoGear. Recupéralos aquí:</p><p><a href="' . $enlace . '">' . $enlace . '</a></p>';
    mk_enviar_correo((string)$c['email'], 'Tu carrito te espera en MotoGear', $html);

    db()->prepare('UPDATE carritos SET estado = "recuperado", recordatorios = recordatorios + 1 WHERE id = ?')->execute([$id]);
    auditar('carrito.recuperar', 'carritos', $id, ['email' => (string)$c['email']]);
    return ['ok' => true];
}

function mk_marketing_resumen(): array {
    $q = db()->query('SELECT estado, COUNT(*) AS n, COALESCE(SUM(subtotal),0) AS monto FROM carritos GROUP BY estado');
    $porEstado = [];
    foreach ($q->fetchAll() as $r) $porEstado[(string)$r['estado']] = ['n' => (int)$r['n'], 'monto' => (int)$r['monto']];
    return [
        'abandonados' => $porEstado['abandonado'] ?? ['n' => 0, 'monto' => 0],
        'recuperados' => $porEstado['recuperado'] ?? ['n' => 0, 'monto' => 0],
        'convertidos' => $porEstado['convertido'] ?? ['n' => 0, 'monto' => 0],
        'tasa' => null,
    ];
}

/* ----------------------------- abandono (cron) --------------------------- */

function mk_marcar_abandonos(): int {
    $horas = (int)mk_config('carrito_abandono_horas', '2');
    return db()->exec("UPDATE carritos SET estado = 'abandonado' WHERE estado = 'activo' AND email <> '' AND ultima_actividad < NOW() - INTERVAL $horas HOUR");
}

/** Envía el recordatorio (una sola vez) a los carritos abandonados hace N horas. */
function mk_enviar_recordatorios(): int {
    $horas = (int)mk_config('carrito_recordatorio_horas', '24');
    $q = db()->prepare("SELECT * FROM carritos WHERE estado = 'abandonado' AND recordatorios = 0 AND email <> '' AND ultima_actividad < NOW() - INTERVAL $horas HOUR");
    $q->execute();
    $n = 0;
    foreach ($q->fetchAll() as $c) {
        $enlace = 'https://ecomerce.sebasdigital.site/carrito.html?token=' . urlencode($c['token']);
        $html = '<p>Hola,</p><p>Dejaste artículos en tu carrito de MotoGear. Recupéralos aquí:</p><p><a href="' . $enlace . '">' . $enlace . '</a></p>';
        if (mk_enviar_correo((string)$c['email'], 'Tu carrito te espera en MotoGear', $html)) {
            db()->prepare('UPDATE carritos SET recordatorios = 1 WHERE id = ?')->execute([(int)$c['id']]);
            $n++;
        }
    }
    return $n;
}

/** Valida un cupón por código (público, checkout). */
function mk_cupon_validar(array $b): array {
    $codigo = strtoupper(s($b['codigo'] ?? '', 30));
    $subtotal = (int)($b['subtotal'] ?? 0);
    $email = strtolower(s($b['email'] ?? '', 120));

    $clienteId = 0;
    $entregados = 0;
    if ($email !== '') {
        $q = db()->prepare('SELECT id FROM clientes WHERE email = ?');
        $q->execute([$email]);
        $clienteId = (int)$q->fetchColumn();
        if ($clienteId) {
            $q = db()->prepare('SELECT COUNT(*) FROM pedidos WHERE cliente_id = ? AND estado = "entregado"');
            $q->execute([$clienteId]);
            $entregados = (int)$q->fetchColumn();
        }
    }

    $q = db()->prepare('SELECT * FROM cupones WHERE codigo = ?');
    $q->execute([$codigo]);
    $c = $q->fetch();
    [$cup, $err, $desc] = mk_cupon($c ?: null, $subtotal, $clienteId, $entregados);
    if ($err) return ['valido' => false, 'error' => $err];
    $valor = (int)($cup['valor'] ?? 0);
    if ($valor === 0 && isset($cup['pct']) && (int)$cup['pct'] > 0) $valor = (int)$cup['pct'];
    return ['valido' => true, 'tipo' => (string)$cup['tipo'], 'valor' => $valor, 'descuento' => $desc];
}
