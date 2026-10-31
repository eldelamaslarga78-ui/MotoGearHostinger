-- ============================================================================
-- Fase 1 · Dashboard
-- Crea los objetos que la Fase 0 no creó y que el dashboard necesita.
-- Idempotente: se puede ejecutar varias veces sin error.
-- Ejecutar en phpMyAdmin sobre la base de datos de la tienda.
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. Configuración (por si la instalación es anterior a la Fase 0)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS configuracion (
  clave      VARCHAR(60) NOT NULL PRIMARY KEY,
  valor      TEXT NULL,
  tipo       ENUM('texto','numero','bool','json') NOT NULL DEFAULT 'texto',
  grupo      VARCHAR(30) NOT NULL DEFAULT 'general',
  es_publico TINYINT(1) NOT NULL DEFAULT 0,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Caché de KPIs (60 s)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cache_kv (
  clave     VARCHAR(80) NOT NULL PRIMARY KEY,
  valor     MEDIUMTEXT NOT NULL,
  expira    DATETIME NOT NULL,
  KEY idx_cache_exp (expira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Visitas (base de la conversión real)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS visitas (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fecha        DATE NOT NULL,
  sesion       CHAR(32) NOT NULL,
  ruta         VARCHAR(120) NOT NULL DEFAULT '',
  referrer     VARCHAR(255) NOT NULL DEFAULT '',
  utm_source   VARCHAR(60) NOT NULL DEFAULT '',
  utm_medium   VARCHAR(60) NOT NULL DEFAULT '',
  utm_campaign VARCHAR(60) NOT NULL DEFAULT '',
  dispositivo  ENUM('movil','escritorio','tablet') NOT NULL DEFAULT 'escritorio',
  ip_hash      CHAR(64) NOT NULL DEFAULT '',
  KEY idx_vis_fecha (fecha),
  KEY idx_vis_ses (sesion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Snapshot diario de KPIs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kpi_snapshots (
  fecha            DATE NOT NULL PRIMARY KEY,
  ingresos         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  pedidos          INT UNSIGNED NOT NULL DEFAULT 0,
  ticket           INT UNSIGNED NOT NULL DEFAULT 0,
  visitas          INT UNSIGNED NOT NULL DEFAULT 0,
  conversion       DECIMAL(5,2) NOT NULL DEFAULT 0,
  carritos_creados INT UNSIGNED NOT NULL DEFAULT 0,
  actualizado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. Notificaciones internas
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notificaciones (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NULL,
  tipo       ENUM('pedido','stock','pago','mensaje','sistema') NOT NULL,
  titulo     VARCHAR(120) NOT NULL,
  cuerpo     VARCHAR(300) NOT NULL DEFAULT '',
  url        VARCHAR(200) NOT NULL DEFAULT '',
  leida      TINYINT(1) NOT NULL DEFAULT 0,
  creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_not_usr (usuario_id, leida, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. Vista de alertas de stock
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_stock_bajo AS
SELECT p.id AS producto_id, p.nombre, v.id AS variante_id, v.sku, v.talla, v.color,
       v.stock, v.stock_minimo
FROM variantes v
JOIN productos p ON p.id = v.producto_id
WHERE p.activo = 1 AND v.activo = 1 AND v.stock <= v.stock_minimo;

-- ---------------------------------------------------------------------------
-- 7. Índices para los KPIs sobre la tabla heredada `pedidos`
--    (idempotente: solo los crea si no existen)
-- ---------------------------------------------------------------------------
SET @existe := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'pedidos' AND index_name = 'idx_ped_fecha'
);
SET @sql := IF(@existe = 0, 'ALTER TABLE pedidos ADD INDEX idx_ped_fecha (creado)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'pedidos' AND index_name = 'idx_ped_estado'
);
SET @sql := IF(@existe = 0, 'ALTER TABLE pedidos ADD INDEX idx_ped_estado (estado, creado)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 8. Versión del esquema
-- ---------------------------------------------------------------------------
INSERT INTO configuracion (clave, valor, tipo, grupo, es_publico)
VALUES ('schema_version', '2', 'numero', 'sistema', 0)
ON DUPLICATE KEY UPDATE valor = '2', actualizado = NOW();
