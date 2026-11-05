<?php
declare(strict_types=1);
/*
 * Checkout · creación atómica del pedido.
 *
 * Orden (no cambiar sin revisar la transacción):
 *   1. Validar datos del cliente
 *   2. Bloquear productos y variantes (FOR UPDATE)
 *   3. Validar líneas y stock disponible
 *   4. Cliente, promociones y cupón (recalculados en servidor)
 *   5. Insertar pedido y asignar stock (libro mayor)
 *   6. Registrar uso de cupón y vincular carrito
 *   7. COMMIT y después, notificar (nunca bloquea el pedido)
 */

/** 1. Datos del cliente. Devuelve los valores normalizados. */
function chk_validar_datos(array $b): array {
    req(($b['web'] ?? '') === '', 'Solicitud rechazada');
    $d = [
        'nombre' => s($b['nombre'] ?? '', 100),
        'tel'    => preg_replace('/[^\d+]/', '', s($b['telefono'] ?? '', 20)),
        'ciudad' => s($b['ciudad'] ?? '', 60),
        'dir'    => s($b['direccion'] ?? '', 200),
        'mail'   => s($b['email'] ?? '', 120),
        'notas'  => s($b['notas'] ?? '', 300),
    ];
    req(mb_strlen($d['nombre']) >= 3, 'Escribe tu nombre completo');
    req(preg_match('/^\+?\d{7,15}$/', $d['tel']) === 1, 'Teléfono no válido');
    req($d['ciudad'] !== '', 'Indica tu ciudad');
    req(mb_strlen($d['dir']) >= 5, 'Indica tu dirección');
    req($d['mail'] !== '' && filter_var($d['mail'], FILTER_VALIDATE_EMAIL) !== false, 'Escribe un correo válido para confirmar tu pedido');
    return $d;
}

/** 2. Bloquea productos y variantes. Devuelve [productos por id, variantes por producto/talla]. */
function chk_cargar_catalogo(PDO $pdo, array $ids): array {
    $ph = ph(count($ids));

    $q = $pdo->prepare("SELECT * FROM productos WHERE activo=1 AND id IN ($ph) FOR UPDATE");
    $q->execute($ids);
    $P = [];
    foreach ($q->fetchAll() as $row) $P[(int)$row['id']] = $row;

    $q = $pdo->prepare("SELECT id, producto_id, talla, color, sku, stock
                          FROM variantes
                         WHERE activo=1 AND producto_id IN ($ph)
                      ORDER BY (color='') DESC, stock DESC, id
                         FOR UPDATE");
    $q->execute($ids);
    $V = [];
    foreach ($q->fetchAll() as $v) {
        $V[(int)$v['producto_id']][(string)$v['talla']][] = [
            'id' => (int)$v['id'], 'color' => (string)$v['color'],
            'sku' => (string)$v['sku'], 'stock' => (int)$v['stock'],
        ];
    }
    return [$P, $V];
}

/** 3. Valida cada línea contra el catálogo bloqueado. Devuelve [subtotal, líneas, líneas para promociones]. */
function chk_validar_lineas(array $items, array $P, array $V): array {
    $sub = 0; $need = []; $lineas = []; $lineasPromo = [];
    foreach ($items as $i) {
        $id = (int)($i['id'] ?? 0); $qty = (int)($i['qty'] ?? 0); $talla = s($i['talla'] ?? '', 10);
        req(isset($P[$id]), 'Un producto de tu carrito ya no está disponible');
        req($qty >= 1 && $qty <= 20, 'Cantidad no válida');
        req(!empty($V[$id][$talla]), 'Elige una talla válida para ' . $P[$id]['nombre']);
        $disp = array_sum(array_column($V[$id][$talla], 'stock'));
        $k = $id . '|' . $talla;
        $need[$k] = ($need[$k] ?? 0) + $qty;
        req($need[$k] <= $disp, 'Stock insuficiente de ' . $P[$id]['nombre'] . ($talla !== '' ? " (talla $talla)" : ''));
        $sub += (int)$P[$id]['precio'] * $qty;
        $lineas[] = ['id' => $id, 'qty' => $qty, 'talla' => $talla];
        $lineasPromo[] = ['producto_id' => $id, 'categoria' => (string)$P[$id]['categoria_id'], 'precio' => (int)$P[$id]['precio'], 'cantidad' => $qty];
    }
    return [$sub, $lineas, $lineasPromo];
}

/**
 * 4. Promociones y cupón. Una promoción exclusiva bloquea el cupón.
 * Devuelve: descuento total, descuento de promociones, descuento de cupón, total y el cupón aplicado.
 */
function chk_calcular_descuentos(PDO $pdo, int $sub, array $lineasPromo, int $clienteId, string $codigoCupon): array {
    $q = $pdo->prepare('SELECT COUNT(*) FROM pedidos WHERE cliente_id = ? AND estado = "entregado"');
    $q->execute([$clienteId]);
    $pedidosEntregados = (int)$q->fetchColumn();

    $promos = mk_evaluar_promociones($lineasPromo, $sub);
    $descuentoPromo = (int)$promos['descuento'];

    $cup = null; $descuentoCupon = 0;
    if ($codigoCupon !== '' && !$promos['exclusiva']) {
        // FOR UPDATE: dos pedidos simultáneos no pueden superar usos_max.
        $q = $pdo->prepare('SELECT * FROM cupones WHERE codigo = ? FOR UPDATE');
        $q->execute([strtoupper($codigoCupon)]);
        [$cup, $err, $descuentoCupon] = mk_cupon($q->fetch() ?: null, $sub, $clienteId, $pedidosEntregados);
        req($cup !== null, (string)$err);
    }
    $descuento = $descuentoPromo + $descuentoCupon;
    $total = $sub - $descuento;
    req($total >= 0, 'Descuento no válido');
    return [
        'descuento' => $descuento, 'descuento_promo' => $descuentoPromo,
        'descuento_cupon' => $descuentoCupon, 'total' => $total, 'cupon' => $cup,
    ];
}

/** 5a. Inserta cabecera y evento inicial del pedido. Devuelve su id. */
function chk_insertar_pedido(PDO $pdo, array $d, int $sub, array $desc, int $clienteId, string $ip, string $idem): int {
    $codigoCupon = $desc['cupon'] ? $desc['cupon']['codigo'] : null;
    $pdo->prepare('INSERT INTO pedidos
            (cliente, telefono, email, ciudad, direccion, notas, subtotal, descuento, cupon, total, ip,
             cliente_id, cliente_nombre, cliente_email, cliente_tel, envio_ciudad, envio_direccion, notas_cliente, cupon_codigo,
             estado, pago_estado, metodo_pago, expira_pago, idempotencia)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$d['nombre'], $d['tel'], $d['mail'], $d['ciudad'], $d['dir'], $d['notas'], $sub, $desc['descuento'], $codigoCupon, $desc['total'], $ip,
            $clienteId, $d['nombre'], $d['mail'], $d['tel'], $d['ciudad'], $d['dir'], $d['notas'], $codigoCupon,
            'pendiente_pago', 'pendiente', 'contraentrega', date('Y-m-d H:i:s', time() + 7200), $idem === '' ? null : $idem]);
    $pid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota) VALUES (?,?,?,?)')
        ->execute([$pid, null, 'pendiente_pago', 'Pedido creado desde la tienda']);
    return $pid;
}

/** 5b. Asigna stock por variante y deja rastro en el libro mayor. */
function chk_reservar_stock(PDO $pdo, int $pid, array $lineas, array &$V, array $P): void {
    $insItem = $pdo->prepare('INSERT INTO pedido_items (pedido_id, variante_id, producto_id, nombre, sku, talla, color, precio, cantidad) VALUES (?,?,?,?,?,?,?,?,?)');
    $updVar  = $pdo->prepare('UPDATE variantes SET stock = ? WHERE id = ?');
    $insMov  = $pdo->prepare('INSERT INTO movimientos_stock (variante_id, tipo, cantidad, stock_despues, motivo, ref_tipo, ref_id) VALUES (?,?,?,?,?,?,?)');

    foreach ($lineas as $l) {
        $restante = $l['qty'];
        foreach ($V[$l['id']][$l['talla']] as $k => $v) {
            if ($restante <= 0) break;
            if ($v['stock'] <= 0) continue;
            $take = min($restante, $v['stock']);
            $nuevo = $v['stock'] - $take;
            $updVar->execute([$nuevo, $v['id']]);
            $insMov->execute([$v['id'], 'salida', -$take, $nuevo, 'Pedido #' . $pid, 'pedido', $pid]);
            $insItem->execute([$pid, $v['id'], $l['id'], $P[$l['id']]['nombre'], $v['sku'], $l['talla'], $v['color'], (int)$P[$l['id']]['precio'], $take]);
            $V[$l['id']][$l['talla']][$k]['stock'] = $nuevo;
            $restante -= $take;
        }
        req($restante === 0, 'Stock insuficiente de ' . $P[$l['id']]['nombre']);
    }
}

/** 6. Uso de cupón y vínculo con el carrito persistente. */
function chk_registrar_cupon_y_carrito(PDO $pdo, int $pid, array $desc, string $carritoToken): void {
    if ($desc['cupon']) {
        $pdo->prepare('UPDATE cupones SET usos = usos + 1 WHERE codigo = ?')->execute([$desc['cupon']['codigo']]);
        $pdo->prepare('INSERT INTO cupon_usos (cupon_codigo, pedido_id, monto) VALUES (?,?,?)')
            ->execute([$desc['cupon']['codigo'], $pid, $desc['descuento_cupon']]);
    }
    if ($carritoToken !== '') {
        $pdo->prepare('UPDATE carritos SET estado = "convertido", pedido_id = ? WHERE token = ? AND estado = "activo"')
            ->execute([$pid, $carritoToken]);
    }
}

/**
 * 7. Confirmación por correo: se guarda en el outbox DENTRO de la transacción.
 * El cron la envía; el pedido nunca depende del SMTP.
 */
function chk_encolar_confirmacion(int $pid, string $nombre, string $mail, int $total): int {
    $waTexto = rawurlencode("Hola MotoGear, quiero pagar mi pedido #$pid por $" . number_format($total, 0, ',', '.') . " a nombre de $nombre.");
    $waLink = 'https://api.whatsapp.com/send?phone=573167800764&text=' . $waTexto;
    $html = '<p>Hola ' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>Recibimos tu pedido <strong>#' . $pid . '</strong> por <strong>$' . number_format($total, 0, ',', '.') . '</strong>.</p>'
        . '<p>Para completar el pago, escríbenos por WhatsApp y te indicamos los pasos:</p>'
        . '<p style="font-size:16px"><a href="' . $waLink . '">' . $waLink . '</a></p>'
        . '<p>Gracias por comprar en MotoGear.</p>';
    return ob_encolar('correo', ['para' => $mail, 'asunto' => "Tu pedido #$pid en MotoGear", 'html' => $html]);
}

/** Punto de entrada del checkout. Devuelve la respuesta JSON. */
function chk_crear(array $b, string $ip, string $idem): array {
    $d = chk_validar_datos($b);

    $items = $b['items'] ?? [];
    req(is_array($items) && count($items) >= 1 && count($items) <= 50, 'Tu carrito está vacío');
    foreach ($items as $it) req(is_array($it), 'Carrito no válido');

    $pdo = db();

    // Idempotencia: un reintento con la misma clave devuelve el pedido ya creado.
    if ($idem !== '') {
        $q = $pdo->prepare('SELECT id, total FROM pedidos WHERE idempotencia = ?');
        $q->execute([$idem]);
        if ($ex = $q->fetch()) return ['id' => (int)$ex['id'], 'total' => (int)$ex['total'], 'repetido' => true];
    }

    $q = $pdo->prepare('SELECT COUNT(*) FROM pedidos WHERE ip=? AND creado > NOW() - INTERVAL 1 HOUR');
    $q->execute([$ip]);
    req((int)$q->fetchColumn() < 10, 'Demasiados pedidos seguidos. Intenta más tarde');

    $pdo->beginTransaction();
    try {
        $ids = array_values(array_unique(array_map(fn($i) => (int)($i['id'] ?? 0), $items)));
        [$P, $V] = chk_cargar_catalogo($pdo, $ids);
        [$sub, $lineas, $lineasPromo] = chk_validar_lineas($items, $P, $V);
        $clienteId = crm_cliente_por_datos($d['nombre'], $d['mail'], $d['tel']);
        $desc = chk_calcular_descuentos($pdo, $sub, $lineasPromo, $clienteId, s($b['cupon'] ?? '', 30));
        $pid = chk_insertar_pedido($pdo, $d, $sub, $desc, $clienteId, $ip, $idem);
        chk_reservar_stock($pdo, $pid, $lineas, $V, $P);
        chk_registrar_cupon_y_carrito($pdo, $pid, $desc, s($b['carrito_token'] ?? '', 36));
        $avisoId = chk_encolar_confirmacion($pid, $d['nombre'], $d['mail'], $desc['total']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Envío inmediato del correo de confirmación (tiempo límite corto).
    // Si falla o expira, la fila queda pendiente y el cron la reintenta.
    ob_procesar_ids([$avisoId], 6);

    return ['id' => $pid, 'total' => $desc['total'], 'descuento' => $desc['descuento']];
}
