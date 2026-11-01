-- ============================================================================
-- Fase 3 · Pedidos
-- Amplía las tablas heredadas `pedidos` y `pedido_items` y crea las tablas que
-- faltan para el ciclo completo: pagos, envíos, transportadoras, zonas,
-- tarifas, facturas, clientes y cupon_usos.
-- Idempotente: se puede ejecutar varias veces sin error.
--
-- IMPORTANTE: en phpMyAdmin selecciona primero la BASE DE DATOS DE LA TIENDA.
-- HAZ UN BACKUP ANTES DE EJECUTAR.
-- ============================================================================

SET NAMES utf8mb4;

-- ===========================================================================
-- 1. Tablas nuevas (idempotentes)
-- ===========================================================================

CREATE TABLE IF NOT EXISTS clientes (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre           VARCHAR(100) NOT NULL,
  email            VARCHAR(120) NULL,
  telefono         VARCHAR(20)  NULL,
  tipo_doc         ENUM('','CC','CE','NIT','PP') NOT NULL DEFAULT '',
  documento        VARCHAR(20)  NULL,
  notas            TEXT NULL,
  acepta_marketing TINYINT(1) NOT NULL DEFAULT 0,
  total_pedidos    INT UNSIGNED NOT NULL DEFAULT 0,
  total_gastado    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  primera_compra   DATETIME NULL,
  ultima_compra    DATETIME NULL,
  creado           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cli_email (email),
  UNIQUE KEY uq_cli_tel (telefono),
  KEY idx_cli_compra (ultima_compra)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pagos (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pedido_id          INT NOT NULL,            -- pedidos.id es int(11) CON signo en producción
  pasarela           ENUM('manual','transferencia','nequi','wompi','mercadopago','contraentrega') NOT NULL,
  referencia_externa VARCHAR(100) NULL,
  estado             ENUM('creado','aprobado','rechazado','reembolsado','error') NOT NULL DEFAULT 'creado',
  monto              INT UNSIGNED NOT NULL,
  payload            JSON NULL,
  creado             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pago_ref (pasarela, referencia_externa),
  CONSTRAINT fk_pago_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transportadoras (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(60) NOT NULL,
  activo       TINYINT(1)  NOT NULL DEFAULT 1,
  tracking_url VARCHAR(200) NOT NULL DEFAULT '',
  api_driver   VARCHAR(30) NOT NULL DEFAULT 'manual',
  config       JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS envios (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pedido_id         INT NOT NULL UNIQUE,      -- pedidos.id es int(11) CON signo en producción
  transportadora_id SMALLINT UNSIGNED NULL,
  guia              VARCHAR(60) NULL,
  estado            ENUM('pendiente','generada','en_transito','entregado','devuelto') NOT NULL DEFAULT 'pendiente',
  peso_g            INT UNSIGNED NOT NULL DEFAULT 0,
  costo             INT UNSIGNED NOT NULL DEFAULT 0,
  fecha_despacho    DATETIME NULL,
  fecha_entrega     DATETIME NULL,
  CONSTRAINT fk_env_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  CONSTRAINT fk_env_trans FOREIGN KEY (transportadora_id) REFERENCES transportadoras(id) ON DELETE SET NULL,
  KEY idx_env_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zonas_envio (
  id     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zonas_cobertura (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  zona_id      SMALLINT UNSIGNED NOT NULL,
  departamento VARCHAR(40) NOT NULL,
  ciudad       VARCHAR(60) NOT NULL DEFAULT '',
  UNIQUE KEY uq_cob (departamento, ciudad),
  CONSTRAINT fk_cob_zona FOREIGN KEY (zona_id) REFERENCES zonas_envio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tarifas_envio (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  zona_id      SMALLINT UNSIGNED NOT NULL,
  desde_peso_g INT UNSIGNED NOT NULL DEFAULT 0,
  hasta_peso_g INT UNSIGNED NOT NULL,
  precio       INT UNSIGNED NOT NULL,
  gratis_desde INT UNSIGNED NULL,
  CONSTRAINT fk_tar_zona FOREIGN KEY (zona_id) REFERENCES zonas_envio(id) ON DELETE CASCADE,
  KEY idx_tar_zona (zona_id, desde_peso_g)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS facturas (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pedido_id       INT NOT NULL UNIQUE,        -- pedidos.id es int(11) CON signo en producción
  prefijo         VARCHAR(4)  NOT NULL DEFAULT 'MG',
  numero          INT UNSIGNED NOT NULL,
  numero_completo VARCHAR(20) NOT NULL UNIQUE,
  fecha           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  subtotal        INT UNSIGNED NOT NULL,
  descuento       INT UNSIGNED NOT NULL DEFAULT 0,
  impuestos       INT UNSIGNED NOT NULL DEFAULT 0,
  total           INT UNSIGNED NOT NULL,
  estado          ENUM('emitida','anulada') NOT NULL DEFAULT 'emitida',
  cufe            VARCHAR(120) NULL,
  CONSTRAINT fk_fac_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE RESTRICT,
  KEY idx_fac_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 2. Columnas nuevas en `pedidos` (tabla heredada)
-- ===========================================================================

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='cliente_id');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN cliente_id INT UNSIGNED NULL AFTER id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='idempotencia');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN idempotencia CHAR(36) NULL UNIQUE AFTER cliente_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='cliente_nombre');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN cliente_nombre VARCHAR(100) NOT NULL DEFAULT '''' AFTER idempotencia', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='cliente_email');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN cliente_email VARCHAR(120) NOT NULL DEFAULT '''' AFTER cliente_nombre', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='cliente_tel');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN cliente_tel VARCHAR(20) NOT NULL DEFAULT '''' AFTER cliente_email', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='envio_departamento');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN envio_departamento VARCHAR(40) NOT NULL DEFAULT '''' AFTER cliente_tel', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='envio_ciudad');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN envio_ciudad VARCHAR(60) NOT NULL DEFAULT '''' AFTER envio_departamento', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='envio_direccion');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN envio_direccion VARCHAR(200) NOT NULL DEFAULT '''' AFTER envio_ciudad', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='envio_indicaciones');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN envio_indicaciones VARCHAR(200) NOT NULL DEFAULT '''' AFTER envio_direccion', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='costo_envio');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN costo_envio INT UNSIGNED NOT NULL DEFAULT 0 AFTER descuento', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='impuestos');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN impuestos INT UNSIGNED NOT NULL DEFAULT 0 AFTER costo_envio', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='cupon_codigo');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN cupon_codigo VARCHAR(30) NULL AFTER cupon', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='pago_estado');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN pago_estado ENUM(''pendiente'',''pagado'',''rechazado'',''reembolsado'') NOT NULL DEFAULT ''pendiente'' AFTER estado', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='metodo_pago');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN metodo_pago ENUM(''contraentrega'',''transferencia'',''nequi'',''wompi'',''mercadopago'') NOT NULL DEFAULT ''contraentrega'' AFTER pago_estado', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='expira_pago');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN expira_pago DATETIME NULL AFTER metodo_pago', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='notas_cliente');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN notas_cliente VARCHAR(300) NOT NULL DEFAULT '''' AFTER expira_pago', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='notas_internas');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN notas_internas TEXT NULL AFTER notas_cliente', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='user_agent');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN user_agent VARCHAR(255) NOT NULL DEFAULT '''' AFTER ip', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='entregado_en');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN entregado_en DATETIME NULL AFTER creado', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='actualizado');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD COLUMN actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER entregado_en', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ===========================================================================
-- 3. Columnas nuevas en `pedido_items`
-- ===========================================================================

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedido_items' AND column_name='costo');
SET @sql := IF(@existe=0, 'ALTER TABLE pedido_items ADD COLUMN costo INT UNSIGNED NOT NULL DEFAULT 0 AFTER precio', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedido_items' AND column_name='descuento');
SET @sql := IF(@existe=0, 'ALTER TABLE pedido_items ADD COLUMN descuento INT UNSIGNED NOT NULL DEFAULT 0 AFTER costo', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedido_items' AND column_name='total_linea');
SET @sql := IF(@existe=0, 'ALTER TABLE pedido_items ADD COLUMN total_linea INT UNSIGNED NOT NULL DEFAULT 0 AFTER descuento', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ===========================================================================
-- 4. Ampliar el enum de estado y migrar 'nuevo' -> 'pendiente_pago'
-- ===========================================================================

ALTER TABLE pedidos MODIFY estado ENUM('nuevo','pendiente_pago','confirmado','en_preparacion','enviado','entregado','cancelado','devuelto') NOT NULL DEFAULT 'pendiente_pago';
UPDATE pedidos SET estado = 'pendiente_pago' WHERE estado = 'nuevo';

-- ===========================================================================
-- 5. Rellenar las columnas nuevas desde las heredadas (idempotente)
-- ===========================================================================

UPDATE pedidos SET cliente_nombre = cliente WHERE cliente_nombre = '';
UPDATE pedidos SET cliente_email = email WHERE cliente_email = '';
UPDATE pedidos SET cliente_tel = telefono WHERE cliente_tel = '';
UPDATE pedidos SET envio_ciudad = ciudad WHERE envio_ciudad = '';
UPDATE pedidos SET envio_direccion = direccion WHERE envio_direccion = '';
UPDATE pedidos SET notas_cliente = notas WHERE notas_cliente = '';
UPDATE pedidos SET cupon_codigo = cupon WHERE cupon_codigo IS NULL AND cupon IS NOT NULL;
UPDATE pedidos SET entregado_en = creado WHERE estado = 'entregado' AND entregado_en IS NULL;
UPDATE pedido_items SET total_linea = precio * cantidad WHERE total_linea = 0;

-- ===========================================================================
-- 6. FK de pedidos -> clientes (si no existe)
-- ===========================================================================

SET @existe := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='pedidos' AND index_name='fk_ped_cli');
SET @sql := IF(@existe=0, 'ALTER TABLE pedidos ADD CONSTRAINT fk_ped_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ===========================================================================
-- 7. Permisos nuevos
-- ===========================================================================

INSERT INTO permisos (clave, grupo, descripcion) SELECT 'envios.gestionar', 'envios', 'Crear guías y gestionar envíos' WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='envios.gestionar');
INSERT INTO permisos (clave, grupo, descripcion) SELECT 'facturas.emitir', 'facturacion', 'Emitir facturas internas' WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='facturas.emitir');
INSERT INTO permisos (clave, grupo, descripcion) SELECT 'facturas.ver', 'facturacion', 'Ver facturas' WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='facturas.ver');

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave='envios.gestionar' WHERE r.nombre='bodega'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id=r.id AND rp.permiso_id=p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave='facturas.emitir' WHERE r.nombre='ventas'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id=r.id AND rp.permiso_id=p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave='facturas.ver' WHERE r.nombre IN ('ventas','soporte')
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id=r.id AND rp.permiso_id=p.id);

-- ===========================================================================
-- 8. Consecutivo de factura
-- ===========================================================================

INSERT INTO configuracion (clave, valor, tipo, grupo, es_publico)
VALUES ('factura_consecutivo', '0', 'numero', 'facturacion', 0)
ON DUPLICATE KEY UPDATE clave = clave;

-- ===========================================================================
-- 9. Versión del esquema
-- ===========================================================================

INSERT INTO configuracion (clave, valor, tipo, grupo, es_publico)
VALUES ('schema_version', '4', 'numero', 'sistema', 0)
ON DUPLICATE KEY UPDATE valor = '4', actualizado = NOW();
