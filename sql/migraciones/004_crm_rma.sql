-- ============================================================================
-- Fase 4 · CRM y RMA
-- Crea cliente_direcciones, devoluciones y devolucion_items, y recalcula los
-- contadores de clientes desde los pedidos entregados.
-- Idempotente.
-- HAZ UN BACKUP ANTES DE EJECUTAR.
-- ============================================================================

SET NAMES utf8mb4;

-- ===========================================================================
-- 1. Direcciones de cliente
-- ===========================================================================
CREATE TABLE IF NOT EXISTS cliente_direcciones (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id    INT UNSIGNED NOT NULL,
  alias         VARCHAR(40) NOT NULL DEFAULT 'Casa',
  destinatario  VARCHAR(100) NOT NULL,
  telefono      VARCHAR(20)  NOT NULL,
  departamento  VARCHAR(40)  NOT NULL,
  ciudad        VARCHAR(60)  NOT NULL,
  direccion     VARCHAR(200) NOT NULL,
  indicaciones  VARCHAR(200) NOT NULL DEFAULT '',
  principal     TINYINT(1)   NOT NULL DEFAULT 0,
  CONSTRAINT fk_dir_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  KEY idx_dir_cli (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 2. Devoluciones (RMA)
-- ===========================================================================
CREATE TABLE IF NOT EXISTS devoluciones (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pedido_id   INT NOT NULL,           -- pedidos.id es int(11) CON signo en producción
  motivo      ENUM('talla','defecto','no_corresponde','arrepentimiento','otro') NOT NULL,
  descripcion VARCHAR(500) NOT NULL DEFAULT '',
  estado      ENUM('solicitada','aprobada','rechazada','recibida','reembolsada') NOT NULL DEFAULT 'solicitada',
  monto       INT UNSIGNED NOT NULL DEFAULT 0,
  usuario_id  INT UNSIGNED NULL,
  creado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dev_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE RESTRICT,
  KEY idx_dev_estado (estado, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devolucion_items (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  devolucion_id   INT UNSIGNED NOT NULL,
  pedido_item_id  INT NOT NULL,       -- pedido_items.id es int(11) CON signo en producción
  cantidad        SMALLINT UNSIGNED NOT NULL,
  accion          ENUM('reembolso','cambio') NOT NULL DEFAULT 'reembolso',
  reingresa_stock TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_di_dev FOREIGN KEY (devolucion_id) REFERENCES devoluciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_di_item FOREIGN KEY (pedido_item_id) REFERENCES pedido_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 3. Recalcular contadores de clientes desde pedidos entregados
-- ===========================================================================
UPDATE clientes c
SET total_pedidos = (SELECT COUNT(*) FROM pedidos p WHERE p.cliente_id = c.id AND p.estado = 'entregado'),
    total_gastado = (SELECT COALESCE(SUM(p.total), 0) FROM pedidos p WHERE p.cliente_id = c.id AND p.estado = 'entregado'),
    primera_compra = (SELECT MIN(p.creado) FROM pedidos p WHERE p.cliente_id = c.id AND p.estado = 'entregado'),
    ultima_compra = (SELECT MAX(p.creado) FROM pedidos p WHERE p.cliente_id = c.id AND p.estado = 'entregado');

-- ===========================================================================
-- 4. Permisos nuevos
-- ===========================================================================
INSERT INTO permisos (clave, grupo, descripcion) SELECT 'clientes.editar', 'clientes', 'Editar datos de clientes' WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='clientes.editar');
INSERT INTO permisos (clave, grupo, descripcion) SELECT 'devoluciones.editar', 'devoluciones', 'Gestionar devoluciones (RMA)' WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='devoluciones.editar');

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave='clientes.editar' WHERE r.nombre='ventas'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id=r.id AND rp.permiso_id=p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave='devoluciones.editar' WHERE r.nombre='soporte'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id=r.id AND rp.permiso_id=p.id);

-- ===========================================================================
-- 5. Versión del esquema
-- ===========================================================================
INSERT INTO configuracion (clave, valor, tipo, grupo, es_publico)
VALUES ('schema_version', '5', 'numero', 'sistema', 0)
ON DUPLICATE KEY UPDATE valor = '5', actualizado = NOW();
