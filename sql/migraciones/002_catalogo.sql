-- ============================================================================
-- Fase 2 · Catálogo
-- Añade a la base las columnas y objetos que el catálogo completo necesita:
--   - producto_imagenes (multimedia)
--   - columnas de catálogo en `productos` (sku, slug, precios, costo, IVA,
--     peso, dimensiones, SEO, descripción larga)
--   - jerarquía y orden en `categorias`
--   - permiso `inventario.ajustar`
-- Idempotente: se puede ejecutar varias veces sin error.
--
-- IMPORTANTE: en phpMyAdmin selecciona primero la BASE DE DATOS DE LA TIENDA
-- en la barra izquierda. Si arriba aparece "information_schema", el script
-- fallará: ahí no se pueden crear tablas.
-- HAZ UN BACKUP ANTES DE EJECUTAR (hPanel → Bases de datos → Copias de seguridad).
-- ============================================================================

SET NAMES utf8mb4;

-- ===========================================================================
-- 1. Columnas de catálogo en `productos`
--    (el esquema legado de la tienda no las tenía)
-- ===========================================================================

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='productos' AND column_name='sku');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD COLUMN sku VARCHAR(40) NULL AFTER id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='productos' AND column_name='slug');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD COLUMN slug VARCHAR(140) NULL AFTER nombre', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='productos' AND column_name='descr_larga');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD COLUMN descr_larga TEXT NULL AFTER descr', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='productos' AND column_name='precio_antes');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD COLUMN precio_antes INT UNSIGNED NULL AFTER precio', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='productos' AND column_name='costo');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD COLUMN costo INT UNSIGNED NULL AFTER precio_antes', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='productos' AND column_name='iva_pct');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD COLUMN iva_pct DECIMAL(5,2) NOT NULL DEFAULT 19.00 AFTER costo', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='productos' AND column_name='peso_g');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD COLUMN peso_g INT UNSIGNED NOT NULL DEFAULT 0 AFTER iva_pct', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='productos' AND column_name='alto_mm');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD COLUMN alto_mm SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER peso_g', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='productos' AND column_name='ancho_mm');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD COLUMN ancho_mm SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER alto_mm', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='productos' AND column_name='largo_mm');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD COLUMN largo_mm SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER ancho_mm', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='productos' AND column_name='meta_titulo');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD COLUMN meta_titulo VARCHAR(70) NULL AFTER destacado', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='productos' AND column_name='meta_desc');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD COLUMN meta_desc VARCHAR(160) NULL AFTER meta_titulo', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='productos' AND column_name='actualizado');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD COLUMN actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER creado', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ===========================================================================
-- 2. Rellenar sku y slug de las filas existentes (únicos y estables)
-- ===========================================================================

UPDATE productos SET sku = CONCAT('MG-PROD-', id) WHERE sku IS NULL OR sku = '';
UPDATE productos SET slug = CONCAT('producto-', id) WHERE slug IS NULL OR slug = '';

-- Ahora sí, NOT NULL + único (solo si no existen ya las claves)
ALTER TABLE productos MODIFY sku VARCHAR(40) NOT NULL;
ALTER TABLE productos MODIFY slug VARCHAR(140) NOT NULL;

SET @existe := (SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema=DATABASE() AND table_name='productos' AND index_name='uq_prod_sku');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD UNIQUE KEY uq_prod_sku (sku)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema=DATABASE() AND table_name='productos' AND index_name='uq_prod_slug');
SET @sql := IF(@existe=0, 'ALTER TABLE productos ADD UNIQUE KEY uq_prod_slug (slug)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ===========================================================================
-- 3. Jerarquía y orden en `categorias`
-- ===========================================================================

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='categorias' AND column_name='padre_id');
SET @sql := IF(@existe=0, 'ALTER TABLE categorias ADD COLUMN padre_id VARCHAR(40) NULL AFTER nombre', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='categorias' AND column_name='orden');
SET @sql := IF(@existe=0, 'ALTER TABLE categorias ADD COLUMN orden SMALLINT NOT NULL DEFAULT 0 AFTER padre_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='categorias' AND column_name='icono');
SET @sql := IF(@existe=0, 'ALTER TABLE categorias ADD COLUMN icono VARCHAR(60) NULL AFTER orden', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='categorias' AND column_name='activo');
SET @sql := IF(@existe=0, 'ALTER TABLE categorias ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER icono', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ===========================================================================
-- 4. Multimedia del producto
-- ===========================================================================

CREATE TABLE IF NOT EXISTS producto_imagenes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  producto_id INT NOT NULL,          -- productos.id es int(11) CON signo en producción
  ruta        VARCHAR(255) NOT NULL,
  alt         VARCHAR(160) NOT NULL DEFAULT '',
  orden       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  principal   TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_img_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  KEY idx_img_prod (producto_id, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migra la imagen principal actual a la tabla nueva (una vez por producto).
INSERT INTO producto_imagenes (producto_id, ruta, alt, orden, principal)
SELECT p.id, p.img, p.nombre, 0, 1
FROM productos p
WHERE p.img <> '' AND p.img IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM producto_imagenes i WHERE i.producto_id = p.id);

-- ===========================================================================
-- 5. SKU faltantes en variantes (red de seguridad)
-- ===========================================================================

UPDATE variantes SET sku = CONCAT('MG-VAR-', id) WHERE sku = '' OR sku IS NULL;

-- ===========================================================================
-- 6. Permiso de inventario + rol bodega
-- ===========================================================================

INSERT INTO permisos (clave, grupo, descripcion)
SELECT 'inventario.ajustar', 'inventario', 'Ajustar stock con libro mayor y motivo'
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'inventario.ajustar');

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
JOIN permisos p ON p.clave = 'inventario.ajustar'
WHERE r.nombre = 'bodega'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

-- ===========================================================================
-- 7. Versión del esquema
-- ===========================================================================

INSERT INTO configuracion (clave, valor, tipo, grupo, es_publico)
VALUES ('schema_version', '3', 'numero', 'sistema', 0)
ON DUPLICATE KEY UPDATE valor = '3', actualizado = NOW();
