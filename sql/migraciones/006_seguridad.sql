-- ============================================================================
-- Fase 6 · Seguridad (2FA)
-- Añade TOTP (autenticación en dos pasos) a los usuarios. Idempotente.
-- ============================================================================
SET NAMES utf8mb4;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='usuarios' AND column_name='totp_secreto');
SET @sql := IF(@existe=0, 'ALTER TABLE usuarios ADD COLUMN totp_secreto VARBINARY(64) NULL AFTER clave_hash', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @existe := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='usuarios' AND column_name='totp_activo');
SET @sql := IF(@existe=0, 'ALTER TABLE usuarios ADD COLUMN totp_activo TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secreto', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO configuracion (clave, valor, tipo, grupo, es_publico)
VALUES ('schema_version', '7', 'numero', 'sistema', 0)
ON DUPLICATE KEY UPDATE valor = '7', actualizado = NOW();
