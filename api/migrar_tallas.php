<?php
// Migración de UNA vez para tiendas ya instaladas: pasa el stock a "stock por talla".
// Debes estar logueado en admin.html (misma sesión) y luego abrir /api/migrar_tallas.php. Después BÓRRALO.
declare(strict_types=1);
require __DIR__ . '/db.php';
admin();
try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS producto_tallas (
      producto_id INT NOT NULL, talla VARCHAR(10) NOT NULL DEFAULT '', stock INT NOT NULL DEFAULT 0,
      PRIMARY KEY (producto_id, talla), FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $n = 0;
    if ($pdo->query("SHOW COLUMNS FROM productos LIKE 'tallas'")->fetch()) {
        $ins = $pdo->prepare('INSERT INTO producto_tallas (producto_id,talla,stock) VALUES (?,?,?)');
        foreach ($pdo->query('SELECT id, tallas, stock FROM productos WHERE id NOT IN (SELECT producto_id FROM producto_tallas)')->fetchAll() as $p) {
            $tl = array_values(array_unique(array_filter(array_map(fn($t) => strtoupper(trim($t)), explode(',', (string)$p['tallas'])))));
            $st = (int)$p['stock'];
            if (!$tl) { $ins->execute([$p['id'], '', $st]); }
            else { $per = intdiv($st, count($tl)); foreach ($tl as $k => $t) $ins->execute([$p['id'], $t, $per + ($k === 0 ? $st - $per * count($tl) : 0)]); }
            $n++;
        }
    }
    out(['ok' => true, 'productos_migrados' => $n, 'nota' => 'Listo. Borra api/migrar_tallas.php del servidor.']);
} catch (Throwable $e) { error_log($e->getMessage()); out(['error' => 'No se pudo migrar: ' . $e->getMessage()], 500); }
