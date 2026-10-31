# Fase 0 · Cimientos

> **Duración real:** 1 semana · **Estado:** ✅ completada el 30/10/2026
> **Entregable verificable:** el panel actual sigue funcionando leyendo de `variantes`, con login por roles, secretos fuera del repositorio y cabeceras de seguridad.
> **Documento base:** `docs/ARQUITECTURA-BACKOFFICE.md` § 0, § 1.3.10 y § 4.4.

---

## 1. Alcance ejecutado

| Área | Antes | Ahora |
|---|---|---|
| Credenciales | `DB_PASS` en `api/config.php` versionado | `config.local.php` fuera del sitio, ignorado por Git |
| Inventario | `producto_tallas` (solo talla) | `variantes` (talla + color + SKU + stock mínimo) |
| Movimientos de stock | Sin historial | Libro mayor `movimientos_stock` |
| Seguridad | Sesión simple | RBAC por ruta, CSRF, auditoría, bloqueo por intentos |
| Borrado de productos | Físico | Lógico (`activo = 0`) si tiene pedidos |
| Cabeceras | Ninguna | CSP, HSTS, `nosniff`, `Referrer-Policy`, `Permissions-Policy` |

## 2. Qué quedó implementado

- `api/config.php`: cargador de credenciales sin secretos. Busca `MG_CONFIG`, después `dirname(__DIR__, 3)/config.local.php` (subdominio) y por último `api/config.local.php`.
- `api/db.php`: cabeceras de seguridad, sesión endurecida, `usuario_actual()`, `permisos()`, `puede()`, `admin()`, `auditar()`.
- `api/api.php`: endpoints de variantes, libro mayor, eventos de pedido, RBAC por ruta, auditoría y borrado lógico.
- `api/migrar_fase0.php`: migrador idempotente (ya retirado del servidor; recuperable con `git show ad9da9b:api/migrar_fase0.php`).
- `admin.js`: UI que oculta pestañas y botones según `PERM_TAB`.
- `.htaccess` y `api/.htaccess`: deniegan `config.local.php`, `.sql`, `.md`, `.log`, `.zip`, instaladores.
- `sql/schema.sql`: 36 objetos verificados en MariaDB 10.4.

## 3. Modelo de datos resultante

| Tabla / vista | Uso |
|---|---|
| `variantes` | Stock por talla/color con SKU propio y `stock_minimo` |
| `movimientos_stock` | Toda alteración de stock, con `stock_despues` |
| `v_stock_bajo` | Alertas de reposición (la vista se crea en la migración `001` de la Fase 1) |
| `roles`, `permisos`, `rol_permisos` | RBAC |
| `usuarios` | Reemplaza a `admins`; conserva `clave_hash` |
| `auditoria` | Quién cambió qué, con `cambios` en JSON |
| `pedido_eventos` | Historial de estados |

`producto_tallas` y `admins` se conservaron a propósito como respaldo de rollback.

## 4. Permisos sembrados

`dashboard.ver`, `pedidos.ver`, `pedidos.editar`, `pedidos.cancelar`, `productos.ver`, `productos.editar`, `productos.borrar`, `categorias.editar`, `categorias.borrar`, `cupones.ver`, `cupones.editar`, `cupones.borrar`, `mensajes.ver`, `mensajes.borrar`, `clientes.ver`, `devoluciones.ver`, `usuarios.gestionar`, `auditoria.ver`, `config.editar`.

Roles: `admin` (comodín `*`), `ventas`, `bodega`, `soporte`.

## 5. Evidencia de pruebas

Probado en MariaDB 10.4 + PHP 8.2 antes de desplegar:

| Caso | Resultado |
|---|---|
| Migración ejecutada 3 veces | Conteos estables (6-5-1-0), idempotente |
| Login / `me` / `admin` | OK con roles y CSRF |
| Alta de producto + variantes | OK; stock 5 → 3 al crear pedido |
| Pedido público con cupón | `500.000 − 50.000 = 450.000` |
| Cancelación | Stock 3 → 5 + movimiento `devolucion` |
| Archivar producto con pedidos | `activo = 0`, no borra |
| Sin sesión / sin permiso / dato inválido | `401` / `403` / `422` |
| POST sin `X-CSRF` | Rechazado |

Dos bugs reales corregidos: FK con signo distinto (`INT` vs `INT UNSIGNED`) y colación distinta (`utf8mb4_general_ci` vs `unicode_ci`).

## 6. Commits de la fase

`70af5a4` → `da7bca5` → `6de65c4` → `ba77905` → `ad9da9b` (Fase 0) → `6ae967e` (ruta de 3 niveles) → `8efa12d` (imágenes + `.gitignore`) → `79fa927` (instaladores retirados).

## 7. Estado de producción al cierre

- `api/api.php`, `api/db.php`, `api/config.php`, `api/.htaccess`, `.htaccess`, `admin.js`: idénticos al repositorio.
- `admin.js` nuevo (RBAC) desplegado en la raíz del sitio.
- `img/api/`, `install.php`, `install.lock`, `migrar_tallas.php` y `migrar_fase0.php`: eliminados del servidor.
- `img/impermeable 1.webp` renombrado a `impermeable-1.webp`.
- Descarga completa verificada: 57/57 archivos rastreados con hash idéntico.

## 8. Pendientes heredados

1. **Rotar la contraseña de MySQL** en hPanel y actualizar `config.local.php`. Obligatorio: GitHub aún sirve por SHA los commits antiguos con la clave.
2. Borrar `%TEMP%\opencode\motogear-pre-scrub.bundle` cuando ya no se necesite la historia antigua.
3. Revisar que no existan copias del repositorio con la historia anterior.
4. **Aplicar `sql/migraciones/001_fase1_dashboard.sql`** antes de la Fase 1: la Fase 0 no creó `kpi_snapshots`, `cache_kv`, `visitas`, `notificaciones` ni `v_stock_bajo`.

## 9. Deuda técnica asumida

| Deuda | Se resuelve en |
|---|---|
| `api/api.php` monolítico (router + lógica) | Fase 1 (extraer servicios) |
| Sin cron ni tareas programadas | Fase 1 (snapshot de KPIs) y Fase 3 (expiración) |
| Sin pruebas automatizadas | Transversal; checklist manual por fase |
| Sin 2FA ni cierre global de sesiones | Fase 6 |
| Sin pasarela de pago | Fase 6 |

## 10. Criterio de cierre

Fase 0 se considera cerrada cuando el panel existente opera con `variantes`, el login respeta roles y ninguna credencial vive en el repositorio. **Cumplido.**

> Guía de despliegue original: `docs/FASE0-DESPLIEGUE.md`.
