<?php
declare(strict_types=1);
/*
 * Métricas del backoffice (Fase 1).
 *
 * Reglas que no se negocian:
 *  - Dinero en enteros (COP). Nunca float para sumar.
 *  - Días de America/Bogota; el corte es a medianoche local.
 *  - Los estados heredados (`nuevo`, `confirmado`…) y los nuevos
 *    (`pendiente_pago`, `en_preparacion`, `devuelto`) conviven en `pedidos`
 *    hasta la Fase 3. El mapeo vive SOLO aquí.
 */

defined('MG_APP') || exit;

/** Pedidos que cuentan como venta (ingresos y ticket). */
const MG_VENTA = ['confirmado', 'en_preparacion', 'enviado', 'entregado'];

/** Pedidos que siguen pendientes de despacho. */
const MG_POR_DESPACHAR = ['nuevo', 'confirmado', 'en_preparacion'];

/** Estados que no cuentan como pedido válido. */
const MG_ANULADO = ['cancelado', 'devuelto'];

function mg_sql_in(array $valores): string
{
    // Solo se usa con constantes de este archivo, nunca con entrada del usuario.
    return "'" . implode("','", $valores) . "'";
}

function mg_hoy(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone('America/Bogota'));
}

function mg_fecha_ok(string $f): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) === 1
        && checkdate((int)substr($f, 5, 2), (int)substr($f, 8, 2), (int)substr($f, 0, 4));
}

/**
 * Normaliza el rango pedido por la URL.
 * Devuelve [desde, hasta] en formato Y-m-d, sin fechas futuras y con tope de 366 días.
 *
 * @return array{0:string,1:string}
 */
function mg_rango(?string $desde, ?string $hasta): array
{
    $tz  = new DateTimeZone('America/Bogota');
    $hoy = mg_hoy();

    $h = ($hasta !== null && mg_fecha_ok($hasta)) ? new DateTimeImmutable($hasta, $tz) : $hoy;
    $d = ($desde !== null && mg_fecha_ok($desde)) ? new DateTimeImmutable($desde, $tz) : $h->modify('-29 days');

    if ($d > $h) {
        [$d, $h] = [$h, $d];
    }
    if ((int)$d->diff($h)->days > 366) {
        $d = $h->modify('-366 days');
    }
    if ($h > $hoy) {
        $h = $hoy;
    }

    return [$d->format('Y-m-d'), $h->format('Y-m-d')];
}

/**
 * Métricas de un periodo cerrado.
 *
 * @return array{desde:string,hasta:string,dias:int,ingresos:int,pedidos:int,
 *               visitas:int,ticket:?int,conversion:?float,
 *               serie:list<array{fecha:string,ingresos:int,pedidos:int,visitas:int}>}
 */
function mg_periodo(string $desde, string $hasta, bool $usarSnapshots = true): array
{
    $tz  = new DateTimeZone('America/Bogota');
    $ini = new DateTimeImmutable($desde . ' 00:00:00', $tz);
    $fin = (new DateTimeImmutable($hasta . ' 00:00:00', $tz))->modify('+1 day');
    $dias = max(1, (int)$ini->diff($fin)->days);

    $venta  = mg_sql_in(MG_VENTA);
    $anulado = mg_sql_in(MG_ANULADO);
    $pdo = db();

    $porDia = [];

    // Pedidos e ingresos por día. `total` es INT UNSIGNED: SUM devuelve DECIMAL,
    // se castea a int al leer para no arrastrar floats.
    $q = $pdo->prepare(
        "SELECT DATE(creado) AS dia,
                COUNT(*) AS pedidos,
                COALESCE(SUM(CASE WHEN estado IN ($venta) THEN total ELSE 0 END), 0) AS ingresos
           FROM pedidos
          WHERE creado >= ? AND creado < ? AND estado NOT IN ($anulado)
       GROUP BY DATE(creado)"
    );
    $q->execute([$ini->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')]);
    foreach ($q->fetchAll() as $r) {
        $porDia[(string)$r['dia']] = [
            'ingresos' => (int)$r['ingresos'],
            'pedidos'  => (int)$r['pedidos'],
            'visitas'  => 0,
        ];
    }

    // Visitas reales (sesiones distintas por día).
    $q = $pdo->prepare(
        'SELECT fecha, COUNT(DISTINCT sesion) AS v
           FROM visitas
          WHERE fecha >= ? AND fecha <= ?
       GROUP BY fecha'
    );
    $q->execute([$desde, $hasta]);
    foreach ($q->fetchAll() as $r) {
        $d = (string)$r['fecha'];
        $porDia[$d] ??= ['ingresos' => 0, 'pedidos' => 0, 'visitas' => 0];
        $porDia[$d]['visitas'] = (int)$r['v'];
    }

    // Días ya cerrados: si el cron dejó snapshot, ese es el dato oficial.
    if ($usarSnapshots) {
        $hoy = mg_hoy()->format('Y-m-d');
        $q = $pdo->prepare(
            'SELECT fecha, ingresos, pedidos, visitas
               FROM kpi_snapshots
              WHERE fecha >= ? AND fecha <= ? AND fecha < ?'
        );
        $q->execute([$desde, $hasta, $hoy]);
        foreach ($q->fetchAll() as $r) {
            $d = (string)$r['fecha'];
            $porDia[$d] = [
                'ingresos' => (int)$r['ingresos'],
                'pedidos'  => (int)$r['pedidos'],
                'visitas'  => (int)$r['visitas'],
            ];
        }
    }

    $serie = [];
    $ingresos = 0;
    $pedidos  = 0;
    $visitas  = 0;
    $ultimo   = new DateTimeImmutable($hasta, $tz);
    for ($f = $ini; $f <= $ultimo; $f = $f->modify('+1 day')) {
        $k = $f->format('Y-m-d');
        $v = $porDia[$k] ?? ['ingresos' => 0, 'pedidos' => 0, 'visitas' => 0];
        $ingresos += $v['ingresos'];
        $pedidos  += $v['pedidos'];
        $visitas  += $v['visitas'];
        $serie[] = [
            'fecha'    => $k,
            'ingresos' => $v['ingresos'],
            'pedidos'  => $v['pedidos'],
            'visitas'  => $v['visitas'],
        ];
    }

    return [
        'desde'     => $desde,
        'hasta'     => $hasta,
        'dias'      => $dias,
        'ingresos'  => $ingresos,
        'pedidos'   => $pedidos,
        'visitas'   => $visitas,
        // Ticket = ingresos ÷ pedidos válidos del periodo.
        'ticket'    => $pedidos > 0 ? (int)round($ingresos / $pedidos) : null,
        // Sin visitas reales no se inventa conversión: se devuelve null ("—").
        'conversion' => $visitas > 0 ? round($pedidos * 100 / $visitas, 2) : null,
        'serie'     => $serie,
    ];
}

/** Versión barata de los datos: si entra un pedido o una visita, cambia. */
function mg_version_datos(): string
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $pdo = db();
    $p = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM pedidos')->fetchColumn();
    $s = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM visitas')->fetchColumn();
    return $v = $p . '.' . $s;
}

function mg_por_despachar(): int
{
    return (int)db()
        ->query('SELECT COUNT(*) FROM pedidos WHERE estado IN (' . mg_sql_in(MG_POR_DESPACHAR) . ')')
        ->fetchColumn();
}

/** @return array<string,int> */
function mg_estados_actuales(): array
{
    $r = db()->query('SELECT estado, COUNT(*) AS n FROM pedidos GROUP BY estado')->fetchAll();
    $o = [];
    foreach ($r as $x) {
        $o[(string)$x['estado']] = (int)$x['n'];
    }
    return $o;
}

function mg_var(?float $actual, ?float $anterior): ?float
{
    if ($actual === null || $anterior === null || $anterior == 0.0) {
        return null;
    }
    return round((($actual - $anterior) / $anterior) * 100, 1);
}

/**
 * Respuesta completa de `?r=kpis`, cacheada 60 s.
 */
function mg_kpis(string $desde, string $hasta): array
{
    $clave = 'kpis:v1:' . $desde . ':' . $hasta . ':' . mg_version_datos();
    $hit = cache_leer($clave);
    if (is_array($hit)) {
        $hit['cache'] = true;
        return $hit;
    }

    $tz  = new DateTimeZone('America/Bogota');
    $ini = new DateTimeImmutable($desde . ' 00:00:00', $tz);
    $fin = (new DateTimeImmutable($hasta . ' 00:00:00', $tz))->modify('+1 day');
    $dias = max(1, (int)$ini->diff($fin)->days);

    $actual   = mg_periodo($desde, $hasta);
    $antFin   = $ini->modify('-1 day');
    $antIni   = $ini->modify('-' . $dias . ' days');
    $anterior = mg_periodo($antIni->format('Y-m-d'), $antFin->format('Y-m-d'));

    $r = [
        'rango'    => ['desde' => $desde, 'hasta' => $hasta, 'dias' => $dias],
        'actual'   => $actual + ['por_despachar' => mg_por_despachar()],
        'anterior' => $anterior,
        'variacion' => [
            'ingresos'   => mg_var($actual['ingresos'], $anterior['ingresos']),
            'pedidos'    => mg_var((float)$actual['pedidos'], (float)$anterior['pedidos']),
            'ticket'     => mg_var($actual['ticket'] !== null ? (float)$actual['ticket'] : null, $anterior['ticket'] !== null ? (float)$anterior['ticket'] : null),
            'conversion' => mg_var($actual['conversion'], $anterior['conversion']),
        ],
        'estados'  => mg_estados_actuales(),
        'generado' => date('c'),
        'cache'    => false,
    ];

    cache_guardar($clave, $r, 60);
    return $r;
}

/** ¿Existe la columna? Sirve para tablas heredadas que pueden variar. */
function mg_columna(string $tabla, string $columna): bool
{
    static $c = [];
    $k = $tabla . '.' . $columna;
    if (array_key_exists($k, $c)) {
        return $c[$k];
    }
    $q = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $q->execute([$tabla, $columna]);
    return $c[$k] = ((int)$q->fetchColumn() > 0);
}

function mg_alerta_stock(int $limite = 5): array
{
    try {
        return [
            'total' => (int)db()->query('SELECT COUNT(*) FROM v_stock_bajo')->fetchColumn(),
            'items' => db()->query(
                'SELECT nombre, sku, talla, color, stock, stock_minimo
                   FROM v_stock_bajo
               ORDER BY (CAST(stock AS SIGNED) - CAST(stock_minimo AS SIGNED)) ASC, stock ASC, nombre ASC
                  LIMIT ' . max(1, min(20, $limite))
            )->fetchAll(),
        ];
    } catch (Throwable $e) {
        error_log('[MotoGear][alertas] ' . $e->getMessage());
        return ['total' => 0, 'items' => [], 'no_disponible' => true];
    }
}

function mg_alerta_detenidos(int $limite = 5): array
{
    $pdo = db();
    $total = (int)$pdo->query(
        "SELECT COUNT(*) FROM pedidos
          WHERE estado = 'nuevo' AND creado < NOW() - INTERVAL 24 HOUR"
    )->fetchColumn();

    $q = $pdo->prepare(
        "SELECT id, cliente, telefono, total, creado
           FROM pedidos
          WHERE estado = 'nuevo' AND creado < NOW() - INTERVAL 24 HOUR
       ORDER BY creado ASC
          LIMIT " . max(1, min(20, $limite))
    );
    $q->execute();

    return ['total' => $total, 'items' => $q->fetchAll()];
}

function mg_alerta_mensajes(): array
{
    $pdo = db();
    try {
        if (mg_columna('mensajes', 'respondido')) {
            $filtro = 'respondido = 0';
        } elseif (mg_columna('mensajes', 'leido')) {
            $filtro = 'leido = 0';
        } else {
            $filtro = 'creado > NOW() - INTERVAL 7 DAY';
        }
        $total = (int)$pdo->query("SELECT COUNT(*) FROM mensajes WHERE $filtro")->fetchColumn();
        $ultimo = $pdo->query('SELECT MAX(creado) FROM mensajes')->fetchColumn();
        return ['total' => $total, 'ultimo' => $ultimo ?: null];
    } catch (Throwable $e) {
        error_log('[MotoGear][alertas] ' . $e->getMessage());
        return ['total' => 0, 'ultimo' => null, 'no_disponible' => true];
    }
}

/**
 * Alertas accionables. El endpoint las filtra por permiso antes de salir.
 */
function mg_alertas(): array
{
    $clave = 'alertas:v1:' . mg_version_datos() . ':' . date('YmdH');
    $hit = cache_leer($clave);
    if (is_array($hit)) {
        return $hit;
    }

    $r = [
        'stock'     => mg_alerta_stock(),
        'detenidos' => mg_alerta_detenidos(),
        'mensajes'  => mg_alerta_mensajes(),
        // La tabla `pagos` se crea en la Fase 3; hasta entonces no hay cartera.
        'pagos'     => ['total' => 0, 'no_disponible' => true],
        'generado'  => date('c'),
    ];

    cache_guardar($clave, $r, 60);
    return $r;
}

function mg_notificaciones(int $usuarioId): array
{
    $pdo = db();
    $q = $pdo->prepare(
        'SELECT id, tipo, titulo, cuerpo, url, leida, creado
           FROM notificaciones
          WHERE usuario_id IS NULL OR usuario_id = ?
       ORDER BY leida ASC, id DESC
          LIMIT 20'
    );
    $q->execute([$usuarioId]);
    $items = $q->fetchAll();

    $q = $pdo->prepare(
        'SELECT COUNT(*) FROM notificaciones
          WHERE leida = 0 AND (usuario_id IS NULL OR usuario_id = ?)'
    );
    $q->execute([$usuarioId]);

    return ['no_leidas' => (int)$q->fetchColumn(), 'items' => $items];
}

/** Marca una notificación o todas. Devuelve cuántas cambió. */
function mg_notificacion_leer(int $usuarioId, ?int $id = null): int
{
    if ($id !== null && $id > 0) {
        $q = db()->prepare(
            'UPDATE notificaciones SET leida = 1
              WHERE id = ? AND leida = 0 AND (usuario_id IS NULL OR usuario_id = ?)'
        );
        $q->execute([$id, $usuarioId]);
        return $q->rowCount();
    }

    $q = db()->prepare(
        'UPDATE notificaciones SET leida = 1
          WHERE leida = 0 AND (usuario_id IS NULL OR usuario_id = ?)'
    );
    $q->execute([$usuarioId]);
    return $q->rowCount();
}

/** Últimos pedidos para la tabla del dashboard. */
function mg_pedidos_recientes(int $limite = 8): array
{
    $limite = max(1, min(50, $limite));
    $pdo = db();
    $rows = $pdo->query(
        "SELECT id, cliente, telefono, ciudad, total, estado, creado
           FROM pedidos
       ORDER BY id DESC
          LIMIT $limite"
    )->fetchAll();

    foreach ($rows as &$r) {
        $r['id']    = (int)$r['id'];
        $r['total'] = (int)$r['total'];
    }
    unset($r);

    return ['items' => $rows];
}

/** Búsqueda global del command palette. Respeta permisos. */
function mg_buscar(string $texto): array
{
    $texto = trim(mb_substr($texto, 0, 60));
    if (mb_strlen($texto) < 2) {
        return ['items' => []];
    }

    $like = '%' . $texto . '%';
    $items = [];
    $pdo = db();

    if (puede('pedidos.ver')) {
        $sql = 'SELECT id, cliente, telefono, total, estado
                  FROM pedidos
                 WHERE (cliente LIKE ? OR telefono LIKE ?)';
        $par = [$like, $like];
        if (ctype_digit($texto)) {
            $sql .= ' OR id = ?';
            $par[] = (int)$texto;
        }
        $sql .= ' ORDER BY id DESC LIMIT 5';
        $q = $pdo->prepare($sql);
        $q->execute($par);
        foreach ($q->fetchAll() as $p) {
            $items[] = [
                'tipo'    => 'pedido',
                'id'      => (int)$p['id'],
                'titulo'  => '#' . $p['id'] . ' · ' . $p['cliente'],
                'detalle' => (string)$p['estado'],
                'total'   => (int)$p['total'],
                'url'     => '#/pedidos/' . (int)$p['id'],
            ];
        }
    }

    if (puede('productos.ver')) {
        $q = $pdo->prepare(
            'SELECT id, nombre, marca, precio
               FROM productos
              WHERE activo = 1 AND (nombre LIKE ? OR marca LIKE ?)
           ORDER BY nombre ASC
              LIMIT 5'
        );
        $q->execute([$like, $like]);
        foreach ($q->fetchAll() as $p) {
            $items[] = [
                'tipo'    => 'producto',
                'id'      => (int)$p['id'],
                'titulo'  => (string)$p['nombre'],
                'detalle' => (string)$p['marca'],
                'total'   => (int)$p['precio'],
                'url'     => '#/productos/' . (int)$p['id'],
            ];
        }
    }

    return ['items' => $items];
}

/**
 * Beacon de visitas. Nunca responde error: si algo falla, la tienda sigue igual.
 */
function mg_visita(array $b): array
{
    try {
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        if (preg_match('/bot|crawl|spider|slurp|curl|wget|preview|facebookexternalhit|monitor/i', $ua) === 1) {
            return ['ok' => true, 'guardada' => false];
        }

        $ruta = preg_replace('/[?#].*$/', '', mb_substr((string)($b['ruta'] ?? ''), 0, 120)) ?: '/';
        if ($ruta === '' || $ruta[0] !== '/') {
            $ruta = '/' . ltrim($ruta, '/');
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $ref  = mb_substr((string)($b['referrer'] ?? ''), 0, 255);
        if ($ref !== '' && $host !== '' && strpos($ref, $host) !== false) {
            $ref = ''; // navegación interna: no aporta como origen
        }

        $fecha  = mg_hoy()->format('Y-m-d');
        $sesion = substr(hash('sha256', session_id()), 0, 32);
        $ipHash = hash('sha256', mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) . '|motogear');

        $pdo = db();

        // Tope por IP y día: un visitante con muchas sesiones no infla la muestra.
        $q = $pdo->prepare('SELECT COUNT(*) FROM visitas WHERE ip_hash = ? AND fecha = ?');
        $q->execute([$ipHash, $fecha]);
        if ((int)$q->fetchColumn() >= 300) {
            return ['ok' => true, 'guardada' => false];
        }

        // Una visita por sesión y ruta al día.
        $q = $pdo->prepare('SELECT 1 FROM visitas WHERE fecha = ? AND sesion = ? AND ruta = ? LIMIT 1');
        $q->execute([$fecha, $sesion, $ruta]);
        if ($q->fetchColumn()) {
            return ['ok' => true, 'guardada' => false];
        }

        $disp = preg_match('/tablet|ipad/i', $ua) === 1
            ? 'tablet'
            : (preg_match('/mobile|android|iphone|ipod/i', $ua) === 1 ? 'movil' : 'escritorio');

        $pdo->prepare(
            'INSERT INTO visitas (fecha, sesion, ruta, referrer, utm_source, utm_medium, utm_campaign, dispositivo, ip_hash)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $fecha,
            $sesion,
            $ruta,
            $ref,
            mb_substr((string)($b['utm_source'] ?? ''), 0, 60),
            mb_substr((string)($b['utm_medium'] ?? ''), 0, 60),
            mb_substr((string)($b['utm_campaign'] ?? ''), 0, 60),
            $disp,
            $ipHash,
        ]);

        return ['ok' => true, 'guardada' => true];
    } catch (Throwable $e) {
        error_log('[MotoGear][visita] ' . $e->getMessage());
        return ['ok' => true, 'guardada' => false];
    }
}

/** Escribe (o actualiza) el snapshot de un día ya cerrado. */
function mg_snapshot_guardar(string $fecha, array $periodo): void
{
    db()->prepare(
        'INSERT INTO kpi_snapshots (fecha, ingresos, pedidos, ticket, visitas, conversion, carritos_creados, actualizado)
         VALUES (?,?,?,?,?,?,0,NOW())
         ON DUPLICATE KEY UPDATE
            ingresos = VALUES(ingresos), pedidos = VALUES(pedidos), ticket = VALUES(ticket),
            visitas = VALUES(visitas), conversion = VALUES(conversion), actualizado = NOW()'
    )->execute([
        $fecha,
        (int)$periodo['ingresos'],
        (int)$periodo['pedidos'],
        (int)($periodo['ticket'] ?? 0),
        (int)$periodo['visitas'],
        (float)($periodo['conversion'] ?? 0),
    ]);
}

/**
 * Rellena los snapshots que falten de los últimos N días (auto-reparación).
 * Devuelve cuántos días escribió.
 */
function mg_snapshots_reparar(int $dias = 7): int
{
    $pdo = db();
    $hoy = mg_hoy();
    $hechos = 0;

    for ($i = 1; $i <= max(1, min(90, $dias)); $i++) {
        $f = $hoy->modify('-' . $i . ' days')->format('Y-m-d');
        $q = $pdo->prepare('SELECT 1 FROM kpi_snapshots WHERE fecha = ?');
        $q->execute([$f]);
        if ($q->fetchColumn()) {
            continue;
        }
        mg_snapshot_guardar($f, mg_periodo($f, $f, false));
        $hechos++;
    }

    return $hechos;
}
