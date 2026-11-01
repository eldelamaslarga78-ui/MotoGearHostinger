<?php
declare(strict_types=1);
/*
 * CRM y RMA (Fase 4) · Clientes y devoluciones.
 */

/* --------------------------- identificación ----------------------------- */

/** Busca (o crea) un cliente por email o teléfono normalizado. Devuelve su id. */
function crm_cliente_por_datos(string $nombre, string $email, string $tel): int {
    $email = strtolower(trim($email));
    $tel = preg_replace('/\D+/', '', $tel);
    $pdo = db();

    if ($email !== '') {
        $q = $pdo->prepare('SELECT id FROM clientes WHERE email = ?');
        $q->execute([$email]);
        $id = (int)$q->fetchColumn();
        if ($id) return $id;
    }
    if ($tel !== '') {
        $q = $pdo->prepare('SELECT id FROM clientes WHERE telefono = ?');
        $q->execute([$tel]);
        $id = (int)$q->fetchColumn();
        if ($id) return $id;
    }
    $pdo->prepare('INSERT INTO clientes (nombre, email, telefono) VALUES (?,?,?)')
        ->execute([$nombre, $email === '' ? null : $email, $tel === '' ? null : $tel]);
    return (int)$pdo->lastInsertId();
}

/** Segmento de un cliente según sus compras entregadas. */
function crm_segmento(array $c): string {
    $pedidos = (int)($c['total_pedidos'] ?? 0);
    $gasto = (int)($c['total_gastado'] ?? 0);
    $ultima = $c['ultima_compra'] ?? null;

    if ($pedidos === 0) return 'sin_compras';
    if ($pedidos >= 5 || $gasto >= 5000000) return 'vip';
    if ($pedidos >= 2) return 'recurrente';
    if ($pedidos === 1) return 'nuevo';
    if ($ultima && strtotime((string)$ultima) < strtotime('-90 days')) return 'inactivo';
    return 'nuevo';
}

/* ------------------------------- clientes ------------------------------- */

function crm_clientes(array $g): array {
    $q      = s($g['q'] ?? '', 80);
    $segmento = s($g['segmento'] ?? '', 30);
    $pagina = max(1, (int)($g['pagina'] ?? 1));
    $limite = min(100, max(1, (int)($g['limite'] ?? 20)));

    $where = ['1=1'];
    $args = [];
    if ($q !== '') {
        $where[] = '(c.nombre LIKE ? OR c.email LIKE ? OR c.telefono LIKE ? OR c.documento LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $w = implode(' AND ', $where);

    $qTotal = db()->prepare("SELECT COUNT(*) FROM clientes c WHERE $w");
    $qTotal->execute($args);
    $total = (int)$qTotal->fetchColumn();

    $offset = ($pagina - 1) * $limite;
    $sql = "SELECT c.* FROM clientes c WHERE $w ORDER BY c.ultima_compra DESC, c.id DESC LIMIT $limite OFFSET $offset";
    $q = db()->prepare($sql);
    $q->execute($args);
    $items = array_map(function ($x) {
        $seg = crm_segmento($x);
        return [
            'id' => (int)$x['id'], 'nombre' => (string)$x['nombre'], 'email' => $x['email'],
            'telefono' => $x['telefono'], 'documento' => $x['documento'],
            'total_pedidos' => (int)$x['total_pedidos'], 'total_gastado' => (int)$x['total_gastado'],
            'ultima_compra' => $x['ultima_compra'], 'segmento' => $seg,
        ];
    }, $q->fetchAll());

    if ($segmento !== '') {
        $items = array_values(array_filter($items, fn($c) => $c['segmento'] === $segmento));
        $total = count($items);
    }

    return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'limite' => $limite, 'paginas' => max(1, (int)ceil($total / $limite))];
}

function crm_cliente_detalle(int $id): array {
    $q = db()->prepare('SELECT * FROM clientes WHERE id = ?');
    $q->execute([$id]);
    $c = $q->fetch();
    if (!$c) throw new UserError('Cliente no encontrado');

    $q = db()->prepare('SELECT * FROM cliente_direcciones WHERE cliente_id = ? ORDER BY principal DESC, id');
    $q->execute([$id]);
    $direcciones = $q->fetchAll();

    $q = db()->prepare('SELECT * FROM pedidos WHERE cliente_id = ? ORDER BY id DESC LIMIT 100');
    $q->execute([$id]);
    $pedidos = $q->fetchAll();

    $q = db()->prepare('SELECT d.*, p.total AS pedido_total FROM devoluciones d JOIN pedidos p ON p.id = d.pedido_id WHERE p.cliente_id = ? ORDER BY d.id DESC');
    $q->execute([$id]);
    $devoluciones = $q->fetchAll();

    return [
        'cliente' => [
            'id' => (int)$c['id'], 'nombre' => (string)$c['nombre'], 'email' => $c['email'],
            'telefono' => $c['telefono'], 'tipo_doc' => (string)$c['tipo_doc'], 'documento' => $c['documento'],
            'notas' => $c['notas'], 'acepta_marketing' => (bool)$c['acepta_marketing'],
            'total_pedidos' => (int)$c['total_pedidos'], 'total_gastado' => (int)$c['total_gastado'],
            'primera_compra' => $c['primera_compra'], 'ultima_compra' => $c['ultima_compra'],
            'segmento' => crm_segmento($c), 'creado' => (string)$c['creado'],
        ],
        'direcciones' => array_map(fn($x) => [
            'id' => (int)$x['id'], 'alias' => (string)$x['alias'], 'destinatario' => (string)$x['destinatario'],
            'telefono' => (string)$x['telefono'], 'departamento' => (string)$x['departamento'],
            'ciudad' => (string)$x['ciudad'], 'direccion' => (string)$x['direccion'],
            'indicaciones' => (string)$x['indicaciones'], 'principal' => (bool)$x['principal'],
        ], $direcciones),
        'pedidos' => array_map(fn($x) => ['id' => (int)$x['id'], 'estado' => (string)$x['estado'], 'total' => (int)$x['total'], 'creado' => (string)$x['creado']], $pedidos),
        'devoluciones' => array_map(fn($x) => ['id' => (int)$x['id'], 'estado' => (string)$x['estado'], 'monto' => (int)$x['monto'], 'creado' => (string)$x['creado']], $devoluciones),
    ];
}

function crm_cliente_guardar(array $b): array {
    $d = $b['data'] ?? [];
    $id = (int)($d['id'] ?? 0);
    $nombre = s($d['nombre'] ?? '', 100);
    $email = strtolower(s($d['email'] ?? '', 120));
    $tel = preg_replace('/\D+/', '', s($d['telefono'] ?? '', 20));
    $notas = ($d['notas'] ?? '') === '' ? null : mb_substr(trim((string)$d['notas']), 0, 4000);
    $acepta = !empty($d['acepta_marketing']) ? 1 : 0;
    $tipoDoc = in_array($d['tipo_doc'] ?? '', ['', 'CC', 'CE', 'NIT', 'PP'], true) ? (string)($d['tipo_doc'] ?? '') : '';
    $documento = s($d['documento'] ?? '', 20);

    req($nombre !== '', 'Escribe el nombre');
    req($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'Correo no válido');

    db()->prepare('UPDATE clientes SET nombre=?, email=?, telefono=?, tipo_doc=?, documento=?, notas=?, acepta_marketing=? WHERE id=?')
        ->execute([$nombre, $email === '' ? null : $email, $tel === '' ? null : $tel, $tipoDoc, $documento === '' ? null : $documento, $notas, $acepta, $id]);

    auditar('cliente.editar', 'clientes', $id, ['nombre' => $nombre, 'email' => $email]);
    return ['ok' => true];
}

function crm_cliente_direccion(array $b): array {
    $d = $b['data'] ?? [];
    $clienteId = (int)($d['cliente_id'] ?? 0);
    $id = (int)($d['id'] ?? 0);
    $alias = s($d['alias'] ?? '', 40);
    $dest = s($d['destinatario'] ?? '', 100);
    $tel = s($d['telefono'] ?? '', 20);
    $dep = s($d['departamento'] ?? '', 40);
    $ciudad = s($d['ciudad'] ?? '', 60);
    $dir = s($d['direccion'] ?? '', 200);
    $ind = s($d['indicaciones'] ?? '', 200);
    $principal = !empty($d['principal']) ? 1 : 0;

    req($clienteId > 0 && $dest !== '' && $dir !== '', 'Destinatario y dirección son obligatorios');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($principal) {
            $pdo->prepare('UPDATE cliente_direcciones SET principal = 0 WHERE cliente_id = ?')->execute([$clienteId]);
        }
        if ($id) {
            $pdo->prepare('UPDATE cliente_direcciones SET alias=?, destinatario=?, telefono=?, departamento=?, ciudad=?, direccion=?, indicaciones=?, principal=? WHERE id=? AND cliente_id=?')
                ->execute([$alias, $dest, $tel, $dep, $ciudad, $dir, $ind, $principal, $id, $clienteId]);
        } else {
            $pdo->prepare('INSERT INTO cliente_direcciones (cliente_id, alias, destinatario, telefono, departamento, ciudad, direccion, indicaciones, principal) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$clienteId, $alias, $dest, $tel, $dep, $ciudad, $dir, $ind, $principal]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true];
}

function crm_cliente_fusionar(array $b): array {
    $origen = (int)($b['origen'] ?? 0);
    $destino = (int)($b['destino'] ?? 0);
    req($origen > 0 && $destino > 0 && $origen !== $destino, 'Cliente origen y destino son obligatorios');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE pedidos SET cliente_id = ? WHERE cliente_id = ?')->execute([$destino, $origen]);
        $pdo->prepare('UPDATE cliente_direcciones SET cliente_id = ? WHERE cliente_id = ?')->execute([$destino, $origen]);
        $pdo->prepare('UPDATE clientes SET activo = 0 WHERE id = ?')->execute([$origen]);
        // Recalcular contadores del destino
        $pdo->prepare('UPDATE clientes SET total_pedidos=(SELECT COUNT(*) FROM pedidos WHERE cliente_id=? AND estado="entregado"),
                       total_gastado=(SELECT COALESCE(SUM(total),0) FROM pedidos WHERE cliente_id=? AND estado="entregado"),
                       primera_compra=(SELECT MIN(creado) FROM pedidos WHERE cliente_id=? AND estado="entregado"),
                       ultima_compra=(SELECT MAX(creado) FROM pedidos WHERE cliente_id=? AND estado="entregado") WHERE id=?')
            ->execute([$destino, $destino, $destino, $destino, $destino]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    auditar('cliente.fusionar', 'clientes', $destino, ['origen' => $origen]);
    return ['ok' => true];
}

/* ------------------------------ devoluciones ---------------------------- */

function crm_devoluciones(array $g): array {
    $estado = s($g['estado'] ?? '', 30);
    $pagina = max(1, (int)($g['pagina'] ?? 1));
    $limite = min(100, max(1, (int)($g['limite'] ?? 20)));

    $where = ['1=1'];
    $args = [];
    if ($estado !== '') { $where[] = 'd.estado = ?'; $args[] = $estado; }
    $w = implode(' AND ', $where);

    $qTotal = db()->prepare("SELECT COUNT(*) FROM devoluciones d JOIN pedidos p ON p.id=d.pedido_id WHERE $w");
    $qTotal->execute($args);
    $total = (int)$qTotal->fetchColumn();

    $offset = ($pagina - 1) * $limite;
    $sql = "SELECT d.*, p.id AS pedido_num, p.cliente_nombre, p.total AS pedido_total
              FROM devoluciones d JOIN pedidos p ON p.id = d.pedido_id
             WHERE $w ORDER BY d.id DESC LIMIT $limite OFFSET $offset";
    $q = db()->prepare($sql);
    $q->execute($args);
    $items = array_map(fn($x) => [
        'id' => (int)$x['id'], 'pedido_id' => (int)$x['pedido_id'], 'cliente' => (string)$x['cliente_nombre'],
        'motivo' => (string)$x['motivo'], 'estado' => (string)$x['estado'], 'monto' => (int)$x['monto'],
        'creado' => (string)$x['creado'],
    ], $q->fetchAll());

    return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'limite' => $limite, 'paginas' => max(1, (int)ceil($total / $limite))];
}

function crm_devolucion_crear(array $b): array {
    $pedidoId = (int)($b['pedido_id'] ?? 0);
    $motivo = s($b['motivo'] ?? '', 30);
    $descripcion = s($b['descripcion'] ?? '', 500);
    $items = $b['items'] ?? [];

    req(in_array($motivo, ['talla', 'defecto', 'no_corresponde', 'arrepentimiento', 'otro'], true), 'Motivo no válido');
    req(is_array($items) && count($items) > 0, 'Indica al menos un ítem');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM pedidos WHERE id = ? FOR UPDATE');
        $q->execute([$pedidoId]);
        $p = $q->fetch();
        if (!$p) throw new UserError('Pedido no encontrado');
        if (!in_array($p['estado'], ['enviado', 'entregado'], true)) throw new UserError('Solo se puede devolver un pedido enviado o entregado');

        $pdo->prepare('INSERT INTO devoluciones (pedido_id, motivo, descripcion, estado, monto, usuario_id) VALUES (?,?,?,?,0,?)')
            ->execute([$pedidoId, $motivo, $descripcion, 'solicitada', (int)($_SESSION['uid'] ?? 0) ?: null]);
        $devId = (int)$pdo->lastInsertId();

        $monto = 0;
        foreach ($items as $it) {
            $itemId = (int)($it['pedido_item_id'] ?? 0);
            $cant = (int)($it['cantidad'] ?? 0);
            $accion = ($it['accion'] ?? 'reembolso') === 'cambio' ? 'cambio' : 'reembolso';
            $reingresa = !empty($it['reingresa_stock']) ? 1 : 0;

            $q = $pdo->prepare('SELECT * FROM pedido_items WHERE id = ? AND pedido_id = ?');
            $q->execute([$itemId, $pedidoId]);
            $item = $q->fetch();
            if (!$item) throw new UserError('Ítem no válido');

            $q = $pdo->prepare('SELECT COALESCE(SUM(cantidad),0) FROM devolucion_items WHERE pedido_item_id = ?');
            $q->execute([$itemId]);
            $yaDevuelto = (int)$q->fetchColumn();
            req($cant >= 1 && ($cant + $yaDevuelto) <= (int)$item['cantidad'], 'Cantidad a devolver no válida');

            $pdo->prepare('INSERT INTO devolucion_items (devolucion_id, pedido_item_id, cantidad, accion, reingresa_stock) VALUES (?,?,?,?,?)')
                ->execute([$devId, $itemId, $cant, $accion, $reingresa]);
            $monto += (int)$item['precio'] * $cant;
        }
        $pdo->prepare('UPDATE devoluciones SET monto = ? WHERE id = ?')->execute([$monto, $devId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    auditar('devolucion.crear', 'devoluciones', $devId, ['pedido' => $pedidoId, 'motivo' => $motivo]);
    return ['ok' => true, 'id' => $devId];
}

function crm_devolucion_transicion(array $b): array {
    $id = (int)($b['id'] ?? 0);
    $hacia = s($b['estado'] ?? '', 30);

    $T = [
        'solicitada' => ['aprobada' => true, 'rechazada' => true],
        'aprobada'   => ['recibida' => true],
        'recibida'   => ['reembolsada' => true],
    ];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM devoluciones WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $d = $q->fetch();
        if (!$d) throw new UserError('Devolución no encontrada');
        $desde = (string)$d['estado'];
        if (empty($T[$desde][$hacia])) throw new UserError("No se puede pasar de '$desde' a '$hacia'");

        if ($hacia === 'recibida') {
            // Reingresar stock una sola vez
            $q = $pdo->prepare('SELECT * FROM devolucion_items WHERE devolucion_id = ?');
            $q->execute([$id]);
            foreach ($q->fetchAll() as $it) {
                if (!(int)$it['reingresa_stock']) continue;
                $q2 = $pdo->prepare('SELECT variante_id FROM pedido_items WHERE id = ?');
                $q2->execute([(int)$it['pedido_item_id']]);
                $vid = (int)$q2->fetchColumn();
                if (!$vid) continue;
                $cant = (int)$it['cantidad'];
                $pdo->prepare('UPDATE variantes SET stock = stock + ? WHERE id = ?')->execute([$cant, $vid]);
                $st = (int)$pdo->query("SELECT stock FROM variantes WHERE id=$vid")->fetchColumn();
                $pdo->prepare('INSERT INTO movimientos_stock (variante_id, tipo, cantidad, stock_despues, motivo, ref_tipo, ref_id, usuario_id) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$vid, 'devolucion', $cant, $st, 'Devolución #' . $id, 'devolucion', $id, (int)($_SESSION['uid'] ?? 0) ?: null]);
            }
        }

        if ($hacia === 'reembolsada') {
            $pdo->prepare("UPDATE pagos SET estado = 'reembolsado' WHERE pedido_id = ?")->execute([(int)$d['pedido_id']]);
            $pdo->prepare("UPDATE pedidos SET estado = 'devuelto', pago_estado = 'reembolsado' WHERE id = ?")->execute([(int)$d['pedido_id']]);
            $pdo->prepare('INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota, usuario_id) VALUES (?,?,?,?,?)')
                ->execute([(int)$d['pedido_id'], 'entregado', 'devuelto', 'RMA reembolsada #' . $id, (int)($_SESSION['uid'] ?? 0) ?: null]);
        }

        $pdo->prepare('UPDATE devoluciones SET estado = ? WHERE id = ?')->execute([$hacia, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    auditar('devolucion.estado', 'devoluciones', $id, ['antes' => $desde, 'despues' => $hacia]);
    return ['ok' => true, 'estado' => $hacia];
}
