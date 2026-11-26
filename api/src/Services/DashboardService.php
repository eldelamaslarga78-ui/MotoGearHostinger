<?php
declare(strict_types=1);
/*
 * Métricas del backoffice (Fase 1).
 *
 * Reglas que no se negocian:
 *  - Dinero en enteros (COP). Nunca float para sumar.
 *  - Días de America/Bogota; el corte es a medianoche local.
 */
namespace App\Services;

use App\Core\Cache;
use App\Repositories\Contracts\DashboardRepositoryInterface;

final class DashboardService
{
    private const VENTA = ['confirmado', 'en_preparacion', 'enviado', 'entregado'];
    private const POR_DESPACHAR = ['nuevo', 'confirmado', 'en_preparacion'];
    private const ANULADO = ['cancelado', 'devuelto'];

    public function __construct(private DashboardRepositoryInterface $repo, private Cache $cache)
    {
    }

    /* ------------------------------ utilidades ------------------------------ */

    public static function sqlIn(array $valores): string
    {
        return "'" . implode("','", $valores) . "'";
    }

    public static function hoy(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('America/Bogota'));
    }

    public static function fechaOk(string $f): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) === 1
            && checkdate((int)substr($f, 5, 2), (int)substr($f, 8, 2), (int)substr($f, 0, 4));
    }

    public static function rango(?string $desde, ?string $hasta): array
    {
        $tz = new \DateTimeZone('America/Bogota');
        $hoy = self::hoy();
        $h = ($hasta !== null && self::fechaOk($hasta)) ? new \DateTimeImmutable($hasta, $tz) : $hoy;
        $d = ($desde !== null && self::fechaOk($desde)) ? new \DateTimeImmutable($desde, $tz) : $h->modify('-29 days');

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

    public static function var(?float $actual, ?float $anterior): ?float
    {
        if ($actual === null || $anterior === null || $anterior == 0.0) {
            return null;
        }
        return round((($actual - $anterior) / $anterior) * 100, 1);
    }

    /* -------------------------------- periodo ------------------------------- */

    public function periodo(string $desde, string $hasta, bool $usarSnapshots = true): array
    {
        $tz = new \DateTimeZone('America/Bogota');
        $ini = new \DateTimeImmutable($desde . ' 00:00:00', $tz);
        $fin = (new \DateTimeImmutable($hasta . ' 00:00:00', $tz))->modify('+1 day');
        $dias = max(1, (int)$ini->diff($fin)->days);

        $porDia = [];
        foreach ($this->repo->pedidosPorDia($ini->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s'), self::sqlIn(self::VENTA), self::sqlIn(self::ANULADO)) as $r) {
            $porDia[(string)$r['dia']] = ['ingresos' => (int)$r['ingresos'], 'pedidos' => (int)$r['pedidos'], 'visitas' => 0];
        }
        foreach ($this->repo->visitasPorDia($desde, $hasta) as $r) {
            $d = (string)$r['fecha'];
            $porDia[$d] ??= ['ingresos' => 0, 'pedidos' => 0, 'visitas' => 0];
            $porDia[$d]['visitas'] = (int)$r['v'];
        }
        if ($usarSnapshots) {
            $hoy = self::hoy()->format('Y-m-d');
            foreach ($this->repo->snapshotsRango($desde, $hasta, $hoy) as $r) {
                $porDia[(string)$r['fecha']] = ['ingresos' => (int)$r['ingresos'], 'pedidos' => (int)$r['pedidos'], 'visitas' => (int)$r['visitas']];
            }
        }

        $serie = [];
        $ingresos = 0;
        $pedidos = 0;
        $visitas = 0;
        $ultimo = new \DateTimeImmutable($hasta, $tz);
        for ($f = $ini; $f <= $ultimo; $f = $f->modify('+1 day')) {
            $k = $f->format('Y-m-d');
            $v = $porDia[$k] ?? ['ingresos' => 0, 'pedidos' => 0, 'visitas' => 0];
            $ingresos += $v['ingresos'];
            $pedidos += $v['pedidos'];
            $visitas += $v['visitas'];
            $serie[] = ['fecha' => $k, 'ingresos' => $v['ingresos'], 'pedidos' => $v['pedidos'], 'visitas' => $v['visitas']];
        }

        return [
            'desde'     => $desde,
            'hasta'     => $hasta,
            'dias'      => $dias,
            'ingresos'  => $ingresos,
            'pedidos'   => $pedidos,
            'visitas'   => $visitas,
            'ticket'    => $pedidos > 0 ? (int)round($ingresos / $pedidos) : null,
            'conversion'=> $visitas > 0 ? round($pedidos * 100 / $visitas, 2) : null,
            'serie'     => $serie,
        ];
    }

    public function versionDatos(): string
    {
        static $v = null;
        return $v ??= $this->repo->versionDatos();
    }

    public function porDespachar(): int
    {
        return $this->repo->porDespachar(self::sqlIn(self::POR_DESPACHAR));
    }

    public function estadosActuales(): array
    {
        return $this->repo->estadosActuales();
    }

    /* --------------------------------- kpis --------------------------------- */

    public function kpis(string $desde, string $hasta): array
    {
        $clave = 'kpis:v1:' . $desde . ':' . $hasta . ':' . $this->versionDatos();
        $hit = $this->cache->leer($clave);
        if (is_array($hit)) {
            $hit['cache'] = true;
            return $hit;
        }

        $tz = new \DateTimeZone('America/Bogota');
        $ini = new \DateTimeImmutable($desde . ' 00:00:00', $tz);
        $dias = max(1, (int)$ini->diff((new \DateTimeImmutable($hasta . ' 00:00:00', $tz))->modify('+1 day'))->days);

        $actual = $this->periodo($desde, $hasta);
        $antFin = $ini->modify('-1 day');
        $antIni = $ini->modify('-' . $dias . ' days');
        $anterior = $this->periodo($antIni->format('Y-m-d'), $antFin->format('Y-m-d'));

        $r = [
            'rango'    => ['desde' => $desde, 'hasta' => $hasta, 'dias' => $dias],
            'actual'   => $actual + ['por_despachar' => $this->porDespachar()],
            'anterior' => $anterior,
            'variacion' => [
                'ingresos'   => self::var($actual['ingresos'], $anterior['ingresos']),
                'pedidos'    => self::var((float)$actual['pedidos'], (float)$anterior['pedidos']),
                'ticket'     => self::var($actual['ticket'] !== null ? (float)$actual['ticket'] : null, $anterior['ticket'] !== null ? (float)$anterior['ticket'] : null),
                'conversion' => self::var($actual['conversion'], $anterior['conversion']),
            ],
            'estados'  => $this->estadosActuales(),
            'generado' => date('c'),
            'cache'    => false,
        ];

        $this->cache->guardar($clave, $r, 60);
        return $r;
    }

    /* -------------------------------- alertas ------------------------------- */

    public function alertaStock(int $limite = 5): array
    {
        try {
            $limite = max(1, min(20, $limite));
            return ['total' => $this->repo->stockBajoTotal(), 'items' => $this->repo->stockBajoItems($limite)];
        } catch (\Throwable $e) {
            error_log('[MotoGear][alertas] ' . $e->getMessage());
            return ['total' => 0, 'items' => [], 'no_disponible' => true];
        }
    }

    public function alertaDetenidos(int $limite = 5): array
    {
        $limite = max(1, min(20, $limite));
        return ['total' => $this->repo->detenidosTotal(), 'items' => $this->repo->detenidosItems($limite)];
    }

    public function alertaMensajes(): array
    {
        try {
            if ($this->repo->columnaExiste('mensajes', 'respondido')) {
                $filtro = 'respondido = 0';
            } elseif ($this->repo->columnaExiste('mensajes', 'leido')) {
                $filtro = 'leido = 0';
            } else {
                $filtro = 'creado > NOW() - INTERVAL 7 DAY';
            }
            return ['total' => $this->repo->mensajesFiltro($filtro), 'ultimo' => $this->repo->ultimoMensaje()];
        } catch (\Throwable $e) {
            error_log('[MotoGear][alertas] ' . $e->getMessage());
            return ['total' => 0, 'ultimo' => null, 'no_disponible' => true];
        }
    }

    public function alertas(): array
    {
        $clave = 'alertas:v1:' . $this->versionDatos() . ':' . date('YmdH');
        $hit = $this->cache->leer($clave);
        if (is_array($hit)) {
            return $hit;
        }

        $r = [
            'stock'     => $this->alertaStock(),
            'detenidos' => $this->alertaDetenidos(),
            'mensajes'  => $this->alertaMensajes(),
            'pagos'     => ['total' => 0, 'no_disponible' => true],
            'generado'  => date('c'),
        ];

        $this->cache->guardar($clave, $r, 60);
        return $r;
    }

    /* ------------------------------ notificaciones --------------------------- */

    public function notificaciones(int $usuarioId): array
    {
        return ['no_leidas' => $this->repo->noLeidas($usuarioId), 'items' => $this->repo->notificaciones($usuarioId)];
    }

    public function notificacionLeer(int $usuarioId, ?int $id = null): int
    {
        return $this->repo->marcarNotificacion($usuarioId, $id);
    }

    /* ----------------------------- pedidos/buscar ---------------------------- */

    public function pedidosRecientes(int $limite = 8): array
    {
        $limite = max(1, min(50, $limite));
        $rows = $this->repo->pedidosRecientes($limite);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['total'] = (int)$r['total'];
        }
        unset($r);
        return ['items' => $rows];
    }

    public function buscar(string $texto): array
    {
        $texto = trim(mb_substr($texto, 0, 60));
        if (mb_strlen($texto) < 2) {
            return ['items' => []];
        }

        $like = '%' . $texto . '%';
        $items = [];

        if (puede('pedidos.ver')) {
            $id = ctype_digit($texto) ? (int)$texto : null;
            foreach ($this->repo->buscarPedidos($like, $id) as $p) {
                $items[] = [
                    'tipo' => 'pedido', 'id' => (int)$p['id'], 'titulo' => '#' . $p['id'] . ' · ' . $p['cliente'],
                    'detalle' => (string)$p['estado'], 'total' => (int)$p['total'], 'url' => '#/pedidos/' . (int)$p['id'],
                ];
            }
        }
        if (puede('productos.ver')) {
            foreach ($this->repo->buscarProductos($like) as $p) {
                $items[] = [
                    'tipo' => 'producto', 'id' => (int)$p['id'], 'titulo' => (string)$p['nombre'],
                    'detalle' => (string)$p['marca'], 'total' => (int)$p['precio'], 'url' => '#/productos/' . (int)$p['id'],
                ];
            }
        }
        return ['items' => $items];
    }

    /* --------------------------------- visitas ------------------------------- */

    public function visita(array $b): array
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
            $ref = mb_substr((string)($b['referrer'] ?? ''), 0, 255);
            if ($ref !== '' && $host !== '' && strpos($ref, $host) !== false) {
                $ref = '';
            }

            $fecha = self::hoy()->format('Y-m-d');
            $sesion = substr(hash('sha256', session_id()), 0, 32);
            $ipHash = hash('sha256', mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) . '|motogear');

            if ($this->repo->visitasPorIpHash($ipHash, $fecha) >= 300) {
                return ['ok' => true, 'guardada' => false];
            }
            if ($this->repo->visitaExiste($fecha, $sesion, $ruta)) {
                return ['ok' => true, 'guardada' => false];
            }

            $disp = preg_match('/tablet|ipad/i', $ua) === 1
                ? 'tablet'
                : (preg_match('/mobile|android|iphone|ipod/i', $ua) === 1 ? 'movil' : 'escritorio');

            $this->repo->insertarVisita([
                $fecha, $sesion, $ruta, $ref,
                mb_substr((string)($b['utm_source'] ?? ''), 0, 60),
                mb_substr((string)($b['utm_medium'] ?? ''), 0, 60),
                mb_substr((string)($b['utm_campaign'] ?? ''), 0, 60),
                $disp, $ipHash,
            ]);
            return ['ok' => true, 'guardada' => true];
        } catch (\Throwable $e) {
            error_log('[MotoGear][visita] ' . $e->getMessage());
            return ['ok' => true, 'guardada' => false];
        }
    }

    /* -------------------------------- snapshots ------------------------------ */

    public function snapshotGuardar(string $fecha, array $periodo): void
    {
        $this->repo->guardarSnapshot($fecha, $periodo);
    }

    public function snapshotsReparar(int $dias = 7): int
    {
        $hoy = self::hoy();
        $hechos = 0;
        for ($i = 1; $i <= max(1, min(90, $dias)); $i++) {
            $f = $hoy->modify('-' . $i . ' days')->format('Y-m-d');
            if ($this->repo->snapshotExiste($f)) {
                continue;
            }
            $this->snapshotGuardar($f, $this->periodo($f, $f, false));
            $hechos++;
        }
        return $hechos;
    }
}
