-- ============================================================================
-- Verificación de la Fase 1
-- Ejecutar en phpMyAdmin (pestaña SQL) con la BASE DE DATOS DE LA TIENDA
-- seleccionada en la barra izquierda. NO la ejecutes en "information_schema":
-- la primera fila del resultado te dirá qué base está activa.
-- Todas las filas de comprobación deben devolver OK.
-- ============================================================================

SELECT CONCAT('base de datos activa: ', DATABASE()) AS objeto, 'INFO' AS estado

UNION ALL
SELECT 'tabla cache_kv' AS objeto,
       IF(COUNT(*) > 0, 'OK', 'FALTA') AS estado
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'cache_kv'

UNION ALL
SELECT 'tabla visitas',
       IF(COUNT(*) > 0, 'OK', 'FALTA')
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'visitas'

UNION ALL
SELECT 'tabla kpi_snapshots',
       IF(COUNT(*) > 0, 'OK', 'FALTA')
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'kpi_snapshots'

UNION ALL
SELECT 'tabla notificaciones',
       IF(COUNT(*) > 0, 'OK', 'FALTA')
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'notificaciones'

UNION ALL
SELECT 'vista v_stock_bajo',
       IF(COUNT(*) > 0, 'OK', 'FALTA')
FROM information_schema.views
WHERE table_schema = DATABASE() AND table_name = 'v_stock_bajo'

UNION ALL
SELECT 'pedidos.total',
       IF(COUNT(*) = 1, 'OK', 'FALTA')
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'pedidos' AND column_name = 'total'

UNION ALL
SELECT 'pedidos.estado',
       IF(COUNT(*) = 1, 'OK', 'FALTA')
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'pedidos' AND column_name = 'estado'

UNION ALL
SELECT 'pedidos.creado',
       IF(COUNT(*) = 1, 'OK', 'FALTA')
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'pedidos' AND column_name = 'creado'

UNION ALL
SELECT 'indice pedidos.idx_ped_fecha',
       IF(COUNT(*) > 0, 'OK', 'FALTA')
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'pedidos' AND index_name = 'idx_ped_fecha'

UNION ALL
SELECT 'indice pedidos.idx_ped_estado',
       IF(COUNT(*) > 0, 'OK', 'FALTA')
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'pedidos' AND index_name = 'idx_ped_estado'

UNION ALL
SELECT 'tabla configuracion',
       IF(COUNT(*) > 0, 'OK', 'FALTA')
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'configuracion';

-- Consulta aparte, con la base de la tienda seleccionada:
-- SELECT clave, valor FROM configuracion WHERE clave = 'schema_version';
