# Fase 1 · Shell y Dashboard

> **Duración:** 2 semanas · **Estado:** pendiente
> **Depende de:** Fase 0 (cerrada el 30/10/2026)
> **Entregable verificable:** dashboard con datos reales, comparación contra el periodo anterior, gráficos SVG sin dependencias y alertas accionables.
> **Documento base:** `docs/ARQUITECTURA-BACKOFFICE.md` § 3.1, § 3.2 y § 1.3.11.

---

## 1. Alcance

### Entra

- Shell del backoffice (`admin/`) con sidebar, topbar y router por hash.
- Cliente API común (fetch + CSRF + errores + reintentos).
- KPIs: ingresos, pedidos, ticket promedio, conversión, por despachar.
- Comparación con periodo anterior y selector de rango.
- Gráficos SVG propios (líneas, barras, dona) accesibles.
- Alertas: stock bajo, pagos pendientes, pedidos detenidos, mensajes sin responder.
- Notificaciones internas con contador y marcado como leídas.
- Beacon de visitas para que la conversión sea real.
- Cron base: snapshot diario de KPIs y limpieza de caché.

### No entra (queda para fases siguientes)

- CRUD completo de productos (Fase 2).
- Drawer y máquina de estados de pedidos (Fase 3).
- Ficha de cliente y RMA (Fase 4).
- Pasarela de pago y 2FA (Fase 6).

## 2. Punto de partida

- `admin.html` + `admin.js`: panel de una sola página con pestañas `pedidos`, `productos`, `categorias`, `cupones`, `mensajes`, `cuenta`.
- API única `api/api.php?r=…` con casos `catalogo`, `pedido`, `login`, `me`, `logout`, `admin`, `guardar`, `borrar`, `producto`, `categoria`, `cupon`, `mensaje`, `pedido_estado`, `upload`, `clave`.
- RBAC ya operativo (`puede()`, `admin()`, `auditar()`).
- Tablas `kpi_snapshots`, `cache_kv`, `visitas`, `notificaciones` y vista `v_stock_bajo` ya existen en `sql/schema.sql`.

**Regla de convivencia:** `admin.html` sigue publicado y funcionando. El shell nuevo vive en `admin/` y se cambia el enlace cuando alcance paridad en Fase 3. Nada de big-bang.

## 3. Modelo de datos

No hay DDL nuevo. Se usan:

| Tabla | Uso en la fase |
|---|---|
| `kpi_snapshots` | Un registro por día cerrado (fuente histórica) |
| `cache_kv` | Caché de 60 s para el rango en curso |
| `visitas` | Base del cálculo de conversión |
| `notificaciones` | Bandeja interna |
| `v_stock_bajo` | Alerta de reposición |
| `pedidos`, `pedido_items` | Ingresos, ticket y estados |
| `mensajes` | Alerta de mensajes sin responder |

Índices que ya cubren las consultas: `idx_ped_estado (estado, creado)`, `idx_ped_fecha (creado)`, `idx_not_usr (usuario_id, leida, creado)`, `idx_vis_fecha (fecha)`.

## 4. Backend

| Ruta | Método | Permiso | Función |
|---|---|---|---|
| `?r=kpis&desde=&hasta=` | GET | `dashboard.ver` | Métricas, serie diaria y comparación |
| `?r=alertas` | GET | `dashboard.ver` | Stock bajo, pagos vencidos, pedidos detenidos, mensajes |
| `?r=notificaciones` | GET | sesión válida | Últimas notificaciones del usuario o globales |
| `?r=notificacion_leer` | POST | sesión válida | Marca una o todas como leídas |
| `?r=visita` | POST | público | Beacon de visita (sin cookies de terceros) |
| `?r=buscar&q=` | GET | sesión válida | Búsqueda global para el command palette |
| `cron/cron.php` | CLI | token + `php_sapi_name() === 'cli'` | Snapshot y limpieza |

### Reglas de cálculo

- Zona horaria fija `America/Bogota`, documentada; nunca mezclar con UTC.
- Dinero en enteros (COP). Nada de `float` para sumar.
- Periodos: hoy, 7 días, 30 días, mes actual y personalizado.
- Comparación: mismo número de días inmediatamente anterior.
- Conversión = pedidos ÷ visitas del periodo. Si no hay visitas, se muestra `—`, no `0 %`.
- "Por despachar" = pedidos en `confirmado` o `en_preparacion`.
- Días cerrados: se leen de `kpi_snapshots`. Rango en curso: consulta en vivo + caché 60 s en `cache_kv`.
- Si el snapshot de ayer no existe, el endpoint lo calcula al vuelo y lo guarda (auto-reparación).

### Beacon de visitas

`POST ?r=visita` desde `main.js` con `navigator.sendBeacon`. Guarda `fecha`, hash de sesión, ruta, referrer, UTMs, dispositivo y hash de IP. Sin datos personales, sin cookies de terceros, rate limit por IP.

## 5. Frontend

```
admin/
├── index.html          shell (sidebar + topbar + <main>)
├── admin.css           tokens existentes + layout
└── js/
    ├── app.js          arranque, router por hash, sesión
    ├── api.js          fetch + X-CSRF + manejo de 401/403/422
    ├── ui/
    │   ├── charts.js   helper SVG (~80 líneas)
    │   ├── toast.js    reutiliza el actual
    │   └── skeleton.js estados de carga/vacío/error
    └── vistas/
        └── dashboard.js
```

- Router por hash (`#/dashboard`, `#/pedidos`…); sin recargar página.
- Polling cada 60 s solo con la pestaña visible (`document.visibilityState`), pausa al ocultar.
- Indicador honesto: "Actualizado hace X s" con botón de refresco.
- Gráficos: `<rect>`, `<polyline>`, `<circle>` con `viewBox`, `role="img"` y `<desc>`; respetan `prefers-reduced-motion`.
- Estados: esqueleto con shimmer, vacío con acción concreta, error con "Reintentar". Nunca panel en blanco.
- Sidebar 248 px colapsable a 64 px; overlay bajo 1024 px; tablas como tarjetas bajo 768 px.
- Command palette con `Ctrl/Cmd + K` y `/`: busca productos, pedidos (`#MG-…`) y teléfonos.

## 6. Permisos

No se crean claves nuevas: todo el dashboard usa `dashboard.ver`, ya asignado a los cuatro roles. Las tarjetas y alertas se filtran además por permiso de destino (`pedidos.ver`, `productos.ver`, `mensajes.ver`).

## 7. Plan de tareas

### Semana 1

- [ ] Extraer de `api/api.php` un servicio de métricas (`api/src/Metricas.php`) con funciones puras.
- [ ] Implementar `?r=kpis` con snapshots + caché.
- [ ] Implementar `?r=alertas` y `?r=notificaciones`.
- [ ] Crear `cron/cron.php` (solo CLI, token) con snapshot y limpieza.
- [ ] Añadir beacon `?r=visita` y llamada desde `main.js`.
- [ ] Crear shell `admin/index.html` + `admin.css` + router.

### Semana 2

- [ ] Helper SVG y las tres visualizaciones.
- [ ] Tarjetas KPI con variación y comparación.
- [ ] Tabla de últimos pedidos y panel de alertas.
- [ ] Command palette con `?r=buscar`.
- [ ] Polling, estados de carga/vacío/error y accesibilidad.
- [ ] Pruebas con datos reales y ajuste de consultas lentas.

## 8. Criterios de aceptación

1. El dashboard muestra ingresos, pedidos, ticket, conversión y pendientes por despachar con datos reales.
2. Cada KPI indica variación contra el periodo anterior con flecha **y** texto (no solo color).
3. Cambiar de rango actualiza todos los KPIs en menos de 2 s con 1.000 pedidos.
4. La conversión usa visitas reales; sin visitas muestra `—`.
5. Las alertas llevan a la vista correspondiente y respetan permisos.
6. El contador de notificaciones baja al marcarlas como leídas.
7. Funciona en móvil (lectura) y con teclado; sin errores de consola ni de CSP.
8. El cron deja `kpi_snapshots` del día anterior y limpia `cache_kv` vencida.

## 9. Pruebas

| Caso | Esperado |
|---|---|
| Sin sesión en `?r=kpis` | `401` |
| Rol sin `dashboard.ver` | `403` |
| Rango sin datos | KPIs en 0 y series vacías, sin error |
| Snapshot ausente | Se calcula y guarda |
| Polling con pestaña oculta | No hay peticiones |
| `?r=visita` repetido | Una visita por sesión y ruta cada 30 min |
| Cambio de zona horaria del servidor | Los cortes de día no se mueven |
| 1.000 pedidos en 30 días | Respuesta < 2 s en caché |

## 10. Riesgos

| Riesgo | Mitigación |
|---|---|
| CPU/I-O del plan Single | Snapshots, caché 60 s, índices, series acotadas |
| Conversión inventada | Beacon obligatorio; si falta, `—` |
| Polling agresivo | Solo pestaña visible + 60 s + caché |
| CSP bloquea módulos | Todo servido desde el mismo origen; sin CDN |
| Consultas `SUM()` sobre todo el histórico | Días cerrados desde `kpi_snapshots` |

## 11. Despliegue

1. Subir `api/src/`, `cron/` y `admin/`.
2. Crear el token del cron en `config.local.php` (`CRON_TOKEN`).
3. Programar en hPanel: `php /home/uXXXX/cron/cron.php` cada 10 min.
4. Probar `?r=kpis` con sesión real.
5. Mantener `admin.html` publicado; enlazar `admin/` solo cuando esté validado.

**Rollback:** borrar `admin/` y el cron; la API existente no cambia.

## 12. Definición de terminado

`php -l` sin errores, flujos críticos probados en móvil, backup restaurado en entorno de prueba y los 8 criterios de aceptación cumplidos.
