<?php
declare(strict_types=1);
/*
 * Pedidos (Fase 3) · Máquina de estados, listado, detalle, pago, guía y factura.
 *
 * Toda transición: BEGIN → SELECT ... FOR UPDATE → validar estado → UPDATE
 * pedidos → INSERT pedido_eventos → efectos → COMMIT. Nunca un UPDATE suelto.
 */

/* --------------------------- máquina de estados -------------------------- */

/** Única fuente de verdad: estado origen => [estado destino => permiso requerido]. */
const PEDIDO_TRANSICIONES = [
    'pendiente_pago' => ['confirmado' => 'pedidos.editar', 'cancelado' => 'pedidos.cancelar'],
    'confirmado'     => ['en_preparacion' => 'pedidos.editar'],
    'en_preparacion' => ['enviado' => 'envios.gestionar'],
    'enviado'        => ['entregado' => 'pedidos.editar'],
];

/** Devuelve el permiso requerido para una transición, o null si es inválida. */
function ped_permiso_transicion(string $desde, string $hacia): ?string {
    return PEDIDO_TRANSICIONES[$desde][$hacia] ?? null;
}

/** Devuelve el stock de un pedido (solo una vez), dentro de la transacción. */
function ped_devolver_stock(PDO $pdo, int $pedidoId): void {
    $q = $pdo->prepare('SELECT variante_id, producto_id, talla, cantidad FROM pedido_items WHERE pedido_id = ?');
    $q->execute([$pedidoId]);
    $items = $q->fetchAll();

    $stock = $pdo->prepare('SELECT stock FROM variantes WHERE id = ?');
    $busca = $pdo->prepare("SELECT id FROM variantes WHERE producto_id = ? AND talla = ? ORDER BY (color='') DESC, id LIMIT 1");
    $suma  = $pdo->prepare('UPDATE variantes SET stock = stock + ? WHERE id = ?');
    $mov   = $pdo->prepare('INSERT INTO movimientos_stock (variante_id, tipo, cantidad, stock_despues, motivo, ref_tipo, ref_id, usuario_id) VALUES (?,?,?,?,?,?,?,?)');

    foreach ($items as $it) {
        $vid = (int)($it['variante_id'] ?? 0);
        if (!$vid) {
            $busca->execute([(int)$it['producto_id'], (string)$it['talla']]);
            $vid = (int)$busca->fetchColumn();
        }
        if (!$vid) continue;
        $cant = (int)$it['cantidad'];
        $suma->execute([$cant, $vid]);
        $stock->execute([$vid]);
        $mov->execute([$vid, 'devolucion', $cant, (int)$stock->fetchColumn(), 'Cancelación pedido #' . $pedidoId, 'cancelacion', $pedidoId, (int)($_SESSION['uid'] ?? 0) ?: null]);
    }
}

/* -------------------------------- listado ------------------------------- */

function ped_lista(array $g): array {
    $q      = s($g['q'] ?? '', 80);
    $estado = s($g['estado'] ?? '', 30);
    $pagina = max(1, (int)($g['pagina'] ?? 1));
    $limite = min(100, max(1, (int)($g['limite'] ?? 20)));

    $where = ['1=1'];
    $args = [];
    if ($q !== '') {
        $where[] = '(p.cliente_nombre LIKE ? OR p.cliente_email LIKE ? OR p.cliente_tel LIKE ? OR p.id = ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, (ctype_digit($q) ? (int)$q : 0));
    }
    if ($estado !== '') {
        $where[] = 'p.estado = ?';
        $args[] = $estado;
    }
    $w = implode(' AND ', $where);

    $qTotal = db()->prepare("SELECT COUNT(*) FROM pedidos p WHERE $w");
    $qTotal->execute($args);
    $total = (int)$qTotal->fetchColumn();

    $offset = ($pagina - 1) * $limite;
    $sql = "SELECT p.id, p.cliente_nombre, p.cliente_email, p.cliente_tel, p.envio_ciudad,
                   p.total, p.estado, p.pago_estado, p.metodo_pago, p.creado,
                   (SELECT COUNT(*) FROM pedido_items i WHERE i.pedido_id = p.id) AS n_items
              FROM pedidos p
             WHERE $w
             ORDER BY p.id DESC
             LIMIT $limite OFFSET $offset";
    $q = db()->prepare($sql);
    $q->execute($args);
    $items = array_map(fn($x) => [
        'id'            => (int)$x['id'],
        'cliente'       => (string)$x['cliente_nombre'],
        'email'         => (string)$x['cliente_email'],
        'telefono'      => (string)$x['cliente_tel'],
        'ciudad'        => (string)$x['envio_ciudad'],
        'total'         => (int)$x['total'],
        'estado'        => (string)$x['estado'],
        'pago_estado'   => (string)$x['pago_estado'],
        'metodo_pago'   => (string)$x['metodo_pago'],
        'n_items'       => (int)$x['n_items'],
        'creado'        => (string)$x['creado'],
    ], $q->fetchAll());

    return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'limite' => $limite, 'paginas' => max(1, (int)ceil($total / $limite))];
}

/* -------------------------------- detalle ------------------------------- */

function ped_detalle(int $id): array {
    $q = db()->prepare('SELECT * FROM pedidos WHERE id = ?');
    $q->execute([$id]);
    $p = $q->fetch();
    if (!$p) throw new UserError('Pedido no encontrado');

    $q = db()->prepare('SELECT * FROM pedido_items WHERE pedido_id = ? ORDER BY id');
    $q->execute([$id]);
    $items = array_map(fn($x) => [
        'id'        => (int)$x['id'],
        'nombre'    => (string)$x['nombre'],
        'sku'       => (string)$x['sku'],
        'talla'     => (string)$x['talla'],
        'color'     => (string)$x['color'],
        'precio'    => (int)$x['precio'],
        'cantidad'  => (int)$x['cantidad'],
        'total_linea'=> (int)($x['total_linea'] ?? ($x['precio'] * $x['cantidad'])),
    ], $q->fetchAll());

    $q = db()->prepare('SELECT * FROM pagos WHERE pedido_id = ? ORDER BY id DESC');
    $q->execute([$id]);
    $pagos = array_map(fn($x) => [
        'id' => (int)$x['id'], 'pasarela' => (string)$x['pasarela'], 'estado' => (string)$x['estado'],
        'monto' => (int)$x['monto'], 'referencia' => $x['referencia_externa'], 'creado' => (string)$x['creado'],
    ], $q->fetchAll());

    $q = db()->prepare('SELECT e.*, t.nombre AS transportadora, t.tracking_url FROM envios e LEFT JOIN transportadoras t ON t.id = e.transportadora_id WHERE e.pedido_id = ?');
    $q->execute([$id]);
    $envio = $q->fetch();

    $q = db()->prepare('SELECT * FROM facturas WHERE pedido_id = ?');
    $q->execute([$id]);
    $factura = $q->fetch();

    $q = db()->prepare('SELECT e.*, u.nombre AS usuario FROM pedido_eventos e LEFT JOIN usuarios u ON u.id = e.usuario_id WHERE e.pedido_id = ? ORDER BY e.id');
    $q->execute([$id]);
    $eventos = array_map(fn($x) => [
        'id' => (int)$x['id'], 'antes' => $x['estado_anterior'], 'despues' => $x['estado_nuevo'],
        'nota' => (string)$x['nota'], 'usuario' => $x['usuario'], 'creado' => (string)$x['creado'],
    ], $q->fetchAll());

    $q = db()->prepare('SELECT * FROM transportadoras WHERE activo = 1 ORDER BY nombre');
    $q->execute();
    $transportadoras = $q->fetchAll();

    return [
        'pedido' => [
            'id' => (int)$p['id'], 'cliente_nombre' => (string)$p['cliente_nombre'],
            'cliente_email' => (string)$p['cliente_email'], 'cliente_tel' => (string)$p['cliente_tel'],
            'envio_ciudad' => (string)$p['envio_ciudad'], 'envio_direccion' => (string)$p['envio_direccion'],
            'envio_departamento' => (string)$p['envio_departamento'], 'envio_indicaciones' => (string)$p['envio_indicaciones'],
            'subtotal' => (int)$p['subtotal'], 'descuento' => (int)$p['descuento'],
            'costo_envio' => (int)$p['costo_envio'], 'impuestos' => (int)$p['impuestos'], 'total' => (int)$p['total'],
            'cupon_codigo' => $p['cupon_codigo'],
            'estado' => (string)$p['estado'], 'pago_estado' => (string)$p['pago_estado'],
            'metodo_pago' => (string)$p['metodo_pago'], 'notas_cliente' => (string)$p['notas_cliente'],
            'notas_internas' => $p['notas_internas'], 'expira_pago' => $p['expira_pago'],
            'creado' => (string)$p['creado'], 'entregado_en' => $p['entregado_en'],
        ],
        'items' => $items,
        'pagos' => $pagos,
        'envio' => $envio ? [
            'id' => (int)$envio['id'], 'guia' => $envio['guia'], 'estado' => (string)$envio['estado'],
            'transportadora_id' => $envio['transportadora_id'] ? (int)$envio['transportadora_id'] : null,
            'transportadora' => $envio['transportadora'],
            'tracking_url' => $envio['tracking_url'] ? str_replace('{guia}', (string)$envio['guia'], $envio['tracking_url']) : '',
        ] : null,
        'factura' => $factura ? [
            'numero_completo' => (string)$factura['numero_completo'], 'fecha' => (string)$factura['fecha'],
            'total' => (int)$factura['total'],
        ] : null,
        'eventos' => $eventos,
        'transportadoras' => array_map(fn($x) => ['id' => (int)$x['id'], 'nombre' => (string)$x['nombre']], $transportadoras),
        'transiciones' => ped_transiciones_disponibles((string)$p['estado']),
    ];
}

/** Transiciones permitidas desde un estado (con el permiso de cada una). */
function ped_transiciones_disponibles(string $estado): array {
    $disponibles = PEDIDO_TRANSICIONES[$estado] ?? [];
    $resultado = [];
    foreach ($disponibles as $hacia => $permiso) {
        if (puede($permiso)) $resultado[] = $hacia;
    }
    return $resultado;
}

/* ------------------------------ transiciones ---------------------------- */

function ped_transicion(array $b): array {
    $id = (int)($b['id'] ?? 0);
    $hacia = s($b['estado'] ?? '', 30);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM pedidos WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $p = $q->fetch();
        if (!$p) throw new UserError('Pedido no encontrado');
        $desde = (string)$p['estado'];

        $permiso = ped_permiso_transicion($desde, $hacia);
        if ($permiso === null) throw new UserError("No se puede pasar de '$desde' a '$hacia'");
        if (!puede($permiso)) throw new UserError('No tienes permiso para esta acción');

        // Efectos por transición
        if ($hacia === 'cancelado') {
            ped_devolver_stock($pdo, $id);
            if ($p['cupon_codigo']) {
                $pdo->prepare('UPDATE cupones SET usos = GREATEST(usos - 1, 0) WHERE codigo = ?')->execute([$p['cupon_codigo']]);
            }
        }
        if ($hacia === 'entregado') {
            $pdo->prepare('UPDATE pedidos SET entregado_en = NOW() WHERE id = ?')->execute([$id]);
        }

        $pdo->prepare('UPDATE pedidos SET estado = ? WHERE id = ?')->execute([$hacia, $id]);
        $pdo->prepare('INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, usuario_id) VALUES (?,?,?,?)')
            ->execute([$id, $desde, $hacia, (int)($_SESSION['uid'] ?? 0) ?: null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    auditar('pedido.estado', 'pedidos', $id, ['antes' => $desde, 'despues' => $hacia]);
    return ['ok' => true, 'estado' => $hacia];
}

/** Registra un pago manual y confirma el pedido. */
function ped_pago(array $b): array {
    $id = (int)($b['id'] ?? 0);
    $metodo = s($b['metodo'] ?? 'transferencia', 30);
    $monto = (int)($b['monto'] ?? 0);
    $referencia = s($b['referencia'] ?? '', 100);
    req(in_array($metodo, ['transferencia', 'nequi', 'efectivo', 'contraentrega', 'otro'], true), 'Método no válido');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM pedidos WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $p = $q->fetch();
        if (!$p) throw new UserError('Pedido no encontrado');
        if ((string)$p['estado'] !== 'pendiente_pago') throw new UserError('Solo se puede pagar un pedido pendiente de pago');
        $monto = $monto > 0 ? $monto : (int)$p['total'];

        $pdo->prepare('INSERT INTO pagos (pedido_id, pasarela, referencia_externa, estado, monto) VALUES (?,?,?,?,?)')
            ->execute([$id, $metodo === 'contraentrega' ? 'contraentrega' : 'manual', $referencia === '' ? null : $referencia, 'aprobado', $monto]);
        $pdo->prepare('UPDATE pedidos SET pago_estado = ?, metodo_pago = ?, estado = ? WHERE id = ?')
            ->execute(['pagado', $metodo, 'confirmado', $id]);
        $pdo->prepare('INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota, usuario_id) VALUES (?,?,?,?,?)')
            ->execute([$id, 'pendiente_pago', 'confirmado', 'Pago registrado', (int)($_SESSION['uid'] ?? 0) ?: null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    auditar('pedido.pago', 'pedidos', $id, ['monto' => $monto, 'metodo' => $metodo]);
    return ['ok' => true];
}

/** Guarda la nota interna. */
function ped_nota(array $b): array {
    $id = (int)($b['id'] ?? 0);
    $nota = ($b['nota'] ?? '') === '' ? null : mb_substr(trim((string)$b['nota']), 0, 4000);
    db()->prepare('UPDATE pedidos SET notas_internas = ? WHERE id = ?')->execute([$nota, $id]);
    auditar('pedido.nota', 'pedidos', $id);
    return ['ok' => true];
}

/** Guarda la guía y pasa el pedido a 'enviado'. */
function ped_guia(array $b): array {
    $id = (int)($b['id'] ?? 0);
    $transportadora = (int)($b['transportadora_id'] ?? 0);
    $guia = s($b['guia'] ?? '', 60);
    req($transportadora > 0, 'Elige una transportadora');
    req($guia !== '', 'Escribe el número de guía');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT estado FROM pedidos WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $estado = $q->fetchColumn();
        if ($estado === false) throw new UserError('Pedido no encontrado');
        if (!in_array($estado, ['en_preparacion', 'confirmado'], true)) throw new UserError('No se puede despachar en este estado');

        $pdo->prepare("INSERT INTO envios (pedido_id, transportadora_id, guia, estado, fecha_despacho) VALUES (?,?,?,'generada',NOW())
                       ON DUPLICATE KEY UPDATE transportadora_id = VALUES(transportadora_id), guia = VALUES(guia), estado = 'generada', fecha_despacho = NOW()")
            ->execute([$id, $transportadora, $guia]);
        $pdo->prepare('UPDATE pedidos SET estado = ? WHERE id = ?')->execute(['enviado', $id]);
        $pdo->prepare('INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota, usuario_id) VALUES (?,?,?,?,?)')
            ->execute([$id, (string)$estado, 'enviado', 'Guía ' . $guia, (int)($_SESSION['uid'] ?? 0) ?: null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    auditar('pedido.guia', 'pedidos', $id, ['guia' => $guia, 'transportadora' => $transportadora]);
    return ['ok' => true];
}

/* -------------------------------- factura ------------------------------- */

function ped_factura_emitir(int $id): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM pedidos WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $p = $q->fetch();
        if (!$p) throw new UserError('Pedido no encontrado');
        if ((string)$p['pago_estado'] !== 'pagado') throw new UserError('Emitir factura requiere el pago registrado');

        $q = $pdo->prepare('SELECT * FROM facturas WHERE pedido_id = ?');
        $q->execute([$id]);
        if ($q->fetch()) throw new UserError('El pedido ya tiene factura');

        // Consecutivo bloqueado
        $q = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'factura_consecutivo' FOR UPDATE");
        $q->execute();
        $n = (int)$q->fetchColumn();
        $n++;
        $pdo->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'factura_consecutivo'")->execute([(string)$n]);

        $numeroCompleto = 'MG-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO facturas (pedido_id, numero, numero_completo, subtotal, descuento, impuestos, total) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id, $n, $numeroCompleto, (int)$p['subtotal'], (int)$p['descuento'], (int)$p['impuestos'], (int)$p['total']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    auditar('factura.emitir', 'pedidos', $id, ['numero' => $numeroCompleto]);
    return ['ok' => true, 'numero' => $numeroCompleto];
}

/** Datos de la factura para la vista imprimible. */
function ped_factura_ver(int $id): array {
    $q = db()->prepare('SELECT * FROM facturas WHERE pedido_id = ?');
    $q->execute([$id]);
    $f = $q->fetch();
    if (!$f) throw new UserError('Factura no encontrada');

    $q = db()->prepare('SELECT * FROM pedidos WHERE id = ?');
    $q->execute([$id]);
    $p = $q->fetch();

    $q = db()->prepare('SELECT * FROM pedido_items WHERE pedido_id = ? ORDER BY id');
    $q->execute([$id]);
    $items = $q->fetchAll();

    return [
        'factura' => $f,
        'pedido' => $p,
        'items' => $items,
    ];
}

/* ----------------------------- transportadoras -------------------------- */

function ped_transportadoras(?array $b): array {
    if ($b !== null && isset($b['nombre'])) {
        $id = (int)($b['id'] ?? 0);
        $nombre = s($b['nombre'], 60);
        $url = s($b['tracking_url'] ?? '', 200);
        $activo = array_key_exists('activo', $b) ? (!empty($b['activo']) ? 1 : 0) : 1;
        req($nombre !== '', 'Escribe el nombre');
        if ($id) {
            db()->prepare('UPDATE transportadoras SET nombre = ?, tracking_url = ?, activo = ? WHERE id = ?')->execute([$nombre, $url, $activo, $id]);
        } else {
            db()->prepare('INSERT INTO transportadoras (nombre, tracking_url, activo) VALUES (?,?,?)')->execute([$nombre, $url, $activo]);
        }
        return ['ok' => true];
    }
    return array_map(fn($x) => ['id' => (int)$x['id'], 'nombre' => (string)$x['nombre'], 'tracking_url' => (string)$x['tracking_url'], 'activo' => (bool)$x['activo']],
        db()->query('SELECT * FROM transportadoras ORDER BY nombre')->fetchAll());
}

/* --------------------------- expiración (cron) --------------------------- */

/** Cancela pedidos `pendiente_pago` vencidos y devuelve stock una sola vez. */
function ped_expirar_pendientes(): int {
    $pdo = db();
    $q = $pdo->prepare("SELECT id FROM pedidos WHERE estado = 'pendiente_pago' AND expira_pago IS NOT NULL AND expira_pago < NOW()");
    $q->execute();
    $ids = array_column($q->fetchAll(), 'id');
    $n = 0;

    foreach ($ids as $id) {
        $id = (int)$id;
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT id, cupon_codigo, estado FROM pedidos WHERE id = ? FOR UPDATE');
            $q->execute([$id]);
            $p = $q->fetch();
            if (!$p || $p['estado'] !== 'pendiente_pago') { $pdo->rollBack(); continue; }

            ped_devolver_stock($pdo, $id);
            if ($p['cupon_codigo']) {
                $pdo->prepare('UPDATE cupones SET usos = GREATEST(usos - 1, 0) WHERE codigo = ?')->execute([$p['cupon_codigo']]);
            }
            $pdo->prepare('UPDATE pedidos SET estado = ? WHERE id = ?')->execute(['cancelado', $id]);
            $pdo->prepare('INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota) VALUES (?,?,?,?)')
                ->execute([$id, 'pendiente_pago', 'cancelado', 'Expirado por falta de pago']);
            $pdo->commit();
            $n++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    return $n;
}
