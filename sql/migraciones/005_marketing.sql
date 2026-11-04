-- ============================================================================
-- Fase 5 · Marketing
-- Amplía cupones, y crea cupon_usos, promociones, promocion_items, carritos
-- y carrito_items. Idempotente. HAZ UN BACKUP ANTES DE EJECUTAR.
-- ============================================================================

SET NAMES utf8mb4;

-- ===========================================================================
-- 1. Ampliar cupones (tabla heredada)
-- ===========================================================================
SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cupones' AND column_name='tipo');
SET @sql := IF(@existe=0, 'ALTER TABLE cupones ADD COLUMN tipo ENUM(''pct'',''fijo'',''envio_gratis'') NOT NULL DEFAULT ''pct'' AFTER pct', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cupones' AND column_name='valor');
SET @sql := IF(@existe=0, 'ALTER TABLE cupones ADD COLUMN valor INT UNSIGNED NOT NULL DEFAULT 0 AFTER tipo', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cupones' AND column_name='desde');
SET @sql := IF(@existe=0, 'ALTER TABLE cupones ADD COLUMN desde DATETIME NULL AFTER minimo', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cupones' AND column_name='max_por_cliente');
SET @sql := IF(@existe=0, 'ALTER TABLE cupones ADD COLUMN max_por_cliente TINYINT UNSIGNED NULL AFTER usos_max', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cupones' AND column_name='primera_compra');
SET @sql := IF(@existe=0, 'ALTER TABLE cupones ADD COLUMN primera_compra TINYINT(1) NOT NULL DEFAULT 0 AFTER max_por_cliente', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cupones' AND column_name='acumulable');
SET @sql := IF(@existe=0, 'ALTER TABLE cupones ADD COLUMN acumulable TINYINT(1) NOT NULL DEFAULT 0 AFTER primera_compra', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill: los cupones existentes son porcentuales
UPDATE cupones SET tipo = 'pct', valor = pct WHERE valor = 0;

-- ===========================================================================
-- 2. Uso de cupones (clave por código, la tabla heredada no tiene id)
-- ===========================================================================
CREATE TABLE IF NOT EXISTS cupon_usos (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cupon_codigo VARCHAR(30) NOT NULL,
  pedido_id    INT NOT NULL,
  monto        INT UNSIGNED NOT NULL,
  creado       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_uso (cupon_codigo, pedido_id),
  KEY idx_uso_ped (pedido_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 3. Promociones
-- ===========================================================================
CREATE TABLE IF NOT EXISTS promociones (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(80) NOT NULL,
  tipo       ENUM('nxm','volumen','combo','descuento_categoria') NOT NULL,
  prioridad  TINYINT UNSIGNED NOT NULL DEFAULT 10,
  desde      DATETIME NULL,
  hasta      DATETIME NULL,
  condiciones JSON NOT NULL,
  beneficios  JSON NOT NULL,
  activa     TINYINT(1) NOT NULL DEFAULT 1,
  exclusiva  TINYINT(1) NOT NULL DEFAULT 1,
  creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promocion_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  promocion_id INT UNSIGNED NOT NULL,
  producto_id  INT NULL,
  categoria_id VARCHAR(40) NULL,
  UNIQUE KEY uq_pi (promocion_id, producto_id, categoria_id),
  CONSTRAINT fk_pi_prom FOREIGN KEY (promocion_id) REFERENCES promociones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 4. Carritos persistentes
-- ===========================================================================
CREATE TABLE IF NOT EXISTS carritos (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token            CHAR(36) NOT NULL UNIQUE,
  cliente_id       INT UNSIGNED NULL,
  email            VARCHAR(120) NULL,
  telefono         VARCHAR(20) NULL,
  cupon_codigo     VARCHAR(30) NULL,
  subtotal         INT UNSIGNED NOT NULL DEFAULT 0,
  estado           ENUM('activo','abandonado','recuperado','convertido') NOT NULL DEFAULT 'activo',
  recordatorios    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ultima_actividad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pedido_id        INT NULL,
  creado           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_car_estado (estado, ultima_actividad),
  KEY idx_car_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS carrito_items (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  carrito_id  BIGINT UNSIGNED NOT NULL,
  variante_id INT UNSIGNED NOT NULL,
  cantidad    SMALLINT UNSIGNED NOT NULL,
  precio      INT UNSIGNED NOT NULL,
  CONSTRAINT fk_ci_car FOREIGN KEY (carrito_id) REFERENCES carritos(id) ON DELETE CASCADE,
  CONSTRAINT fk_ci_var FOREIGN KEY (variante_id) REFERENCES variantes(id) ON DELETE CASCADE,
  UNIQUE KEY uq_ci (carrito_id, variante_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 5. Configuración de marketing y SMTP
-- ===========================================================================
INSERT INTO configuracion (clave, valor, tipo, grupo, es_publico) VALUES
  ('carrito_abandono_horas', '2', 'numero', 'marketing', 0),
  ('carrito_recordatorio_horas', '24', 'numero', 'marketing', 0),
  ('smtp_host', 'smtp.hostinger.com', 'texto', 'correo', 0),
  ('smtp_puerto', '465', 'numero', 'correo', 0),
  ('smtp_usuario', 'ecomerce@sebasdigital.site', 'texto', 'correo', 0),
  ('smtp_remitente', 'MotoGear <ecomerce@sebasdigital.site>', 'texto', 'correo', 0)
  -- smtp_clave: NO se guarda en la base. Va en config.local.php como SMTP_CLAVE.
ON DUPLICATE KEY UPDATE clave = clave;

-- ===========================================================================
-- 6. Permisos nuevos
-- ===========================================================================
INSERT INTO permisos (clave, grupo, descripcion) SELECT 'promociones.ver', 'marketing', 'Ver promociones' WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='promociones.ver');
INSERT INTO permisos (clave, grupo, descripcion) SELECT 'promociones.editar', 'marketing', 'Crear y editar promociones' WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='promociones.editar');
INSERT INTO permisos (clave, grupo, descripcion) SELECT 'promociones.borrar', 'marketing', 'Borrar promociones' WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='promociones.borrar');
INSERT INTO permisos (clave, grupo, descripcion) SELECT 'carritos.ver', 'marketing', 'Ver carritos abandonados' WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='carritos.ver');

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave IN ('promociones.ver','promociones.editar','carritos.ver') WHERE r.nombre='ventas'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id=r.id AND rp.permiso_id=p.id);

-- ===========================================================================
-- 7. Versión del esquema
-- ===========================================================================
INSERT INTO configuracion (clave, valor, tipo, grupo, es_publico)
VALUES ('schema_version', '6', 'numero', 'sistema', 0)
ON DUPLICATE KEY UPDATE valor = '6', actualizado = NOW();
