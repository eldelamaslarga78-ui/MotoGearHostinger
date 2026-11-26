<?php
declare(strict_types=1);
/*
 * Acceso a datos del dashboard (PDO preparado).
 */
namespace App\Repositories;

use App\Repositories\Contracts\DashboardRepositoryInterface;
use PDO;

final class DashboardRepository implements DashboardRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function pedidosPorDia(string $desde, string $hasta, string $ventaSql, string $anuladoSql): array
    {
        $q = $this->pdo->prepare(
            "SELECT DATE(creado) AS dia,
                    COUNT(*) AS pedidos,
                    COALESCE(SUM(CASE WHEN estado IN ($ventaSql) THEN total ELSE 0 END), 0) AS ingresos
               FROM pedidos
              WHERE creado >= ? AND creado < ? AND estado NOT IN ($anuladoSql)
           GROUP BY DATE(creado)"
        );
        $q->execute([$desde, $hasta]);
        return $q->fetchAll();
    }

    public function visitasPorDia(string $desde, string $hasta): array
    {
        $q = $this->pdo->prepare('SELECT fecha, COUNT(DISTINCT sesion) AS v FROM visitas WHERE fecha >= ? AND fecha <= ? GROUP BY fecha');
        $q->execute([$desde, $hasta]);
        return $q->fetchAll();
    }

    public function snapshotsRango(string $desde, string $hasta, string $hoy): array
    {
        $q = $this->pdo->prepare('SELECT fecha, ingresos, pedidos, visitas FROM kpi_snapshots WHERE fecha >= ? AND fecha <= ? AND fecha < ?');
        $q->execute([$desde, $hasta, $hoy]);
        return $q->fetchAll();
    }

    public function versionDatos(): string
    {
        $p = (int)$this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM pedidos')->fetchColumn();
        $s = (int)$this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM visitas')->fetchColumn();
        return $p . '.' . $s;
    }

    public function porDespachar(string $inSql): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM pedidos WHERE estado IN (' . $inSql . ')')->fetchColumn();
    }

    public function estadosActuales(): array
    {
        $o = [];
        foreach ($this->pdo->query('SELECT estado, COUNT(*) AS n FROM pedidos GROUP BY estado')->fetchAll() as $x) {
            $o[(string)$x['estado']] = (int)$x['n'];
        }
        return $o;
    }

    public function columnaExiste(string $tabla, string $columna): bool
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $q->execute([$tabla, $columna]);
        return (int)$q->fetchColumn() > 0;
    }

    public function stockBajoTotal(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM v_stock_bajo')->fetchColumn();
    }

    public function stockBajoItems(int $limite): array
    {
        return $this->pdo->query('SELECT nombre, sku, talla, color, stock, stock_minimo FROM v_stock_bajo
                                   ORDER BY (CAST(stock AS SIGNED) - CAST(stock_minimo AS SIGNED)) ASC, stock ASC, nombre ASC
                                   LIMIT ' . $limite)->fetchAll();
    }

    public function detenidosTotal(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'nuevo' AND creado < NOW() - INTERVAL 24 HOUR")->fetchColumn();
    }

    public function detenidosItems(int $limite): array
    {
        $q = $this->pdo->prepare("SELECT id, cliente, telefono, total, creado FROM pedidos WHERE estado = 'nuevo' AND creado < NOW() - INTERVAL 24 HOUR ORDER BY creado ASC LIMIT " . $limite);
        $q->execute();
        return $q->fetchAll();
    }

    public function mensajesFiltro(string $filtro): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM mensajes WHERE $filtro")->fetchColumn();
    }

    public function ultimoMensaje(): ?string
    {
        $v = $this->pdo->query('SELECT MAX(creado) FROM mensajes')->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    public function notificaciones(int $usuarioId): array
    {
        $q = $this->pdo->prepare('SELECT id, tipo, titulo, cuerpo, url, leida, creado FROM notificaciones WHERE usuario_id IS NULL OR usuario_id = ? ORDER BY leida ASC, id DESC LIMIT 20');
        $q->execute([$usuarioId]);
        return $q->fetchAll();
    }

    public function noLeidas(int $usuarioId): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM notificaciones WHERE leida = 0 AND (usuario_id IS NULL OR usuario_id = ?)');
        $q->execute([$usuarioId]);
        return (int)$q->fetchColumn();
    }

    public function marcarNotificacion(int $usuarioId, ?int $id): int
    {
        if ($id !== null && $id > 0) {
            $q = $this->pdo->prepare('UPDATE notificaciones SET leida = 1 WHERE id = ? AND leida = 0 AND (usuario_id IS NULL OR usuario_id = ?)');
            $q->execute([$id, $usuarioId]);
            return $q->rowCount();
        }
        $q = $this->pdo->prepare('UPDATE notificaciones SET leida = 1 WHERE leida = 0 AND (usuario_id IS NULL OR usuario_id = ?)');
        $q->execute([$usuarioId]);
        return $q->rowCount();
    }

    public function pedidosRecientes(int $limite): array
    {
        return $this->pdo->query("SELECT id, cliente, telefono, ciudad, total, estado, creado FROM pedidos ORDER BY id DESC LIMIT $limite")->fetchAll();
    }

    public function buscarPedidos(string $like, ?int $id): array
    {
        $sql = 'SELECT id, cliente, telefono, total, estado FROM pedidos WHERE (cliente LIKE ? OR telefono LIKE ?)';
        $par = [$like, $like];
        if ($id !== null) {
            $sql .= ' OR id = ?';
            $par[] = $id;
        }
        $sql .= ' ORDER BY id DESC LIMIT 5';
        $q = $this->pdo->prepare($sql);
        $q->execute($par);
        return $q->fetchAll();
    }

    public function buscarProductos(string $like): array
    {
        $q = $this->pdo->prepare('SELECT id, nombre, marca, precio FROM productos WHERE activo = 1 AND (nombre LIKE ? OR marca LIKE ?) ORDER BY nombre ASC LIMIT 5');
        $q->execute([$like, $like]);
        return $q->fetchAll();
    }

    public function visitasPorIpHash(string $ipHash, string $fecha): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM visitas WHERE ip_hash = ? AND fecha = ?');
        $q->execute([$ipHash, $fecha]);
        return (int)$q->fetchColumn();
    }

    public function visitaExiste(string $fecha, string $sesion, string $ruta): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM visitas WHERE fecha = ? AND sesion = ? AND ruta = ? LIMIT 1');
        $q->execute([$fecha, $sesion, $ruta]);
        return (bool)$q->fetchColumn();
    }

    public function insertarVisita(array $v): void
    {
        $this->pdo->prepare('INSERT INTO visitas (fecha, sesion, ruta, referrer, utm_source, utm_medium, utm_campaign, dispositivo, ip_hash) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute($v);
    }

    public function snapshotExiste(string $fecha): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM kpi_snapshots WHERE fecha = ?');
        $q->execute([$fecha]);
        return (bool)$q->fetchColumn();
    }

    public function guardarSnapshot(string $fecha, array $p): void
    {
        $this->pdo->prepare('INSERT INTO kpi_snapshots (fecha, ingresos, pedidos, ticket, visitas, conversion, carritos_creados, actualizado)
                             VALUES (?,?,?,?,?,?,0,NOW())
                             ON DUPLICATE KEY UPDATE ingresos = VALUES(ingresos), pedidos = VALUES(pedidos), ticket = VALUES(ticket),
                                 visitas = VALUES(visitas), conversion = VALUES(conversion), actualizado = NOW()')
            ->execute([
                $fecha,
                (int)$p['ingresos'],
                (int)$p['pedidos'],
                (int)($p['ticket'] ?? 0),
                (int)$p['visitas'],
                (float)($p['conversion'] ?? 0),
            ]);
    }
}
