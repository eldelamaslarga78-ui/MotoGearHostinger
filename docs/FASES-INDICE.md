# Plan de fases — MotoGear Backoffice

> Índice de ejecución. Cada fase tiene su documento con alcance, datos, API, UI, permisos, tareas, criterios de aceptación, pruebas y despliegue.
> Documento de diseño base: [`ARQUITECTURA-BACKOFFICE.md`](ARQUITECTURA-BACKOFFICE.md).

| Fase | Documento | Duración | Estado | Entregable |
|---|---|---|---|---|
| 0 · Cimientos | [FASE0-CIMIENTOS.md](FASE0-CIMIENTOS.md) | 1 sem | ✅ completada 30/10/2026 | Panel con `variantes`, RBAC y secretos fuera del repo |
| 1 · Shell + Dashboard | [FASE1-SHELL-DASHBOARD.md](FASE1-SHELL-DASHBOARD.md) | 2 sem | ✅ implementada y probada 31/10/2026 (sin subir) | Dashboard con KPIs reales y comparación |
| 2 · Catálogo | [FASE2-CATALOGO.md](FASE2-CATALOGO.md) | 2–3 sem | pendiente | Producto con 15 variantes en < 2 min |
| 3 · Pedidos | [FASE3-PEDIDOS.md](FASE3-PEDIDOS.md) | 2 sem | pendiente | Ciclo completo sin tocar la base de datos |
| 4 · CRM y RMA | [FASE4-CRM-RMA.md](FASE4-CRM-RMA.md) | 2 sem | pendiente | Devolución que reingresa stock |
| 5 · Marketing | [FASE5-MARKETING.md](FASE5-MARKETING.md) | 2 sem | pendiente | Promociones y carrito recuperado medido |
| 6 · Pagos y 2FA | [FASE6-PAGOS-2FA.md](FASE6-PAGOS-2FA.md) | 2 sem | pendiente | Pago aprobado sin intervención humana |

## Reglas del proyecto

1. **El servidor manda:** precios, stock, descuentos e impuestos se calculan en PHP.
2. **Todo movimiento de stock deja fila** en `movimientos_stock`.
3. **Borrado lógico** antes que físico cuando hay historial.
4. **Permisos en servidor:** ocultar un botón es UX; el `403` es la seguridad.
5. **Sin dependencias externas:** nada de CDNs, frameworks ni build en producción.
6. **Cada fase cierra** con `php -l`, prueba de flujos en móvil y un backup restaurado.

## Estado de despliegue

- Repositorio: `main` sincronizado con GitHub y con producción (última revisión de documentación incluida).
- Fase 0 desplegada y verificada.
- Fase 1 implementada y probada en local (31/10/2026): el shell vive en `backoffice/`, la migración 001 ya está aplicada en `u441937558_Ecommerce` y la suite E2E pasa las 53 comprobaciones. Falta subir `backoffice/`, `api/src/`, `cron/` y los archivos modificados, y programar el cron.
- **Antes de la Fase 2:** subir la Fase 1, crear `CRON_TOKEN` en `config.local.php` y comprobar `?r=kpis` con sesión real en el servidor.
- Pendiente crítico: **rotar la contraseña de MySQL** y actualizar `config.local.php`.
