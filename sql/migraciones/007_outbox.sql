-- ============================================================================
-- Fase C · Outbox de eventos (cola de correos)
-- El pedido y su mensaje se guardan en la MISMA transacción. Un proceso aparte
-- (cron) envía los mensajes pendientes. Si el SMTP falla, se reintenta.
-- Idempotente. HAZ UN BACKUP ANTES DE EJECUTAR.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS outbox (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tipo         VARCHAR(40) NOT NULL,
  payload      JSON NOT NULL,
  estado       ENUM('pendiente','enviado','fallido') NOT NULL DEFAULT 'pendiente',
  intentos     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ultimo_error VARCHAR(255) NULL,
  creado       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviado_en   DATETIME NULL,
  KEY idx_outbox_estado (estado, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion (clave, valor, tipo, grupo, es_publico)
VALUES ('schema_version', '8', 'numero', 'sistema', 0)
ON DUPLICATE KEY UPDATE valor = '8', actualizado = NOW();
