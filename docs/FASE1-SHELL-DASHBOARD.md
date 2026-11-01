# Fase 1 · Shell y Dashboard

> **Duración:** 2 semanas · **Estado:** ✅ implementada y probada en entorno local el 31/10/2026 (pendiente de subir a producción)
> **Depende de:** Fase 0 (cerrada el 30/10/2026)
> **Entregable verificable:** dashboard con datos reales, comparación contra el periodo anterior, gráficos SVG sin dependencias y alertas accionables.
> **Documento base:** `docs/ARQUITECTURA-BACKOFFICE.md` § 3.1, § 3.2 y § 1.3.11.

---

## 1. Alcance

### Entra

- Shell del backoffice (`backoffice/`) con sidebar, topbar y router por hash.
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
- Tablas `kpi_snapshots`, `cache_kv`, `visitas`, `notificaciones` y vista `v_stock_bajo` **definidas** en `sql/schema.sql`, pero **aún no creadas en producción** (ver sección 3).

**Regla de convivencia:** `admin.html` sigue publicado y funcionando. El shell nuevo vive en `backoffice/` y se cambia el enlace cuando alcance paridad en Fase 3. Nada de big-bang.

**Por qué `backoffice/` y no `admin/`:** `/admin` ya sirve el panel actual (el `.htaccess` reescribe `admin.html` → `/admin`). Crear un directorio `admin/` dejaría el panel viejo inaccesible. Además, `backoffice/` deja claro qué es el panel nuevo mientras conviven los dos; el enlace "Panel anterior" del sidebar apunta al viejo para las secciones aún no migradas.

## 3. Modelo de datos

**Prerrequisito real:** la Fase 0 solo creó `variantes`, `movimientos_stock`, `pedido_eventos`, `configuracion` y las tablas de seguridad. Las tablas y la vista que el dashboard necesita **no existen todavía en producción**. Se crean con:

```
sql/migraciones/001_fase1_dashboard.sql   (idempotente)
sql/verificar_fase1.sql                   (todas las filas deben dar OK)
```

| Objeto | Tipo | Uso en la fase |
|---|---|---|
| `kpi_snapshots` | tabla nueva | Un registro por día cerrado |
| `cache_kv` | tabla nueva | Caché de 60 s del rango en curso |
| `visitas` | tabla nueva | Base del cálculo de conversión |
| `notificaciones` | tabla nueva | Bandeja interna |
| `v_stock_bajo` | vista nueva | Alerta de reposición |
| `pedidos`, `pedido_items` | tablas heredadas | Ingresos, ticket y estados |
| `mensajes` | tabla heredada | Alerta de mensajes sin responder |

La migración también crea, si faltan, los índices `pedidos.idx_ped_fecha (creado)` y `pedidos.idx_ped_estado (estado, creado)`, y sube `configuracion.schema_version` a `2`.

### Estados heredados de `pedidos`

Hoy `pedidos.estado` usa `nuevo`, `confirmado`, `enviado`, `entregado` y `cancelado`. La Fase 3 los migrará al enum nuevo (`pendiente_pago`, `en_preparacion`, `devuelto`). Mientras tanto, todo el cálculo debe centralizarse en una función de mapeo:

| Concepto | Valores heredados | Valores nuevos (post Fase 3) |
|---|---|---|
| Venta confirmada (ingresos) | `confirmado`, `enviado`, `entregado` | `confirmado`, `en_preparacion`, `enviado`, `entregado` |
| Por despachar | `nuevo`, `confirmado` | `confirmado`, `en_preparacion` |
| Cancelado (excluido) | `cancelado` | `cancelado` |

Nunca dupliques esta lógica en las vistas: vive en `api/src/Metricas.php`.

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
- "Por despachar" usa el mapeo de estados heredados de la sección 3.
- "Ingresos" solo suma ventas confirmadas; los pedidos `nuevo`/`pendiente_pago` no cuentan como ingreso.
- Días cerrados: se leen de `kpi_snapshots`. Rango en curso: consulta en vivo + caché 60 s en `cache_kv`.
- Si el snapshot de ayer no existe, el endpoint lo calcula al vuelo y lo guarda (auto-reparación).

### Beacon de visitas

`POST ?r=visita` desde `main.js` con `navigator.sendBeacon`. Guarda `fecha`, hash de sesión, ruta, referrer, UTMs, dispositivo y hash de IP. Sin datos personales, sin cookies de terceros, rate limit por IP.

## 5. Frontend

```
backoffice/
├── index.html          shell (sidebar + topbar + <main>)
├── admin.css           tokens existentes + layout
└── js/
    ├── app.js          arranque, router por hash, sesión, paleta, notificaciones
    ├── api.js          fetch + X-CSRF + manejo de 401/403/422
    ├── formato.js      dinero COP, fechas, números (es-CO)
    ├── ui/
    │   ├── charts.js   SVG propios: línea, barras y dona
    │   ├── toast.js    avisos efímeros
    │   └── skeleton.js estados de carga/vacío/error
    └── vistas/
        └── dashboard.js
```

Ningún archivo del shell usa estilos ni scripts en línea: la CSP del sitio (`style-src 'self'`, `script-src 'self'`) se cumple tal cual.

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

- [x] Extraer de `api/api.php` un servicio de métricas (`api/src/Metricas.php`) con funciones puras.
- [x] Implementar `?r=kpis` con snapshots + caché.
- [x] Implementar `?r=alertas` y `?r=notificaciones`.
- [x] Crear `cron/cron.php` (solo CLI, token) con snapshot y limpieza.
- [x] Añadir beacon `?r=visita` y llamada desde `main.js`.
- [x] Crear shell `backoffice/index.html` + `admin.css` + router.

### Semana 2

- [x] Helper SVG y las tres visualizaciones.
- [x] Tarjetas KPI con variación y comparación.
- [x] Tabla de últimos pedidos y panel de alertas.
- [x] Command palette con `?r=buscar`.
- [x] Polling, estados de carga/vacío/error y accesibilidad.
- [x] Pruebas con datos reales y ajuste de consultas lentas.

## 8. Criterios de aceptación

1. El dashboard muestra ingresos, pedidos, ticket, conversión y pendientes por despachar con datos reales.
2. Cada KPI indica variación contra el periodo anterior con flecha **y** texto (no solo color).
3. Cambiar de rango actualiza todos los KPIs en menos de 2 s con 1.000 pedidos.
4. La conversión usa visitas reales; sin visitas muestra `—`.
5. Las alertas llevan a la vista correspondiente y respetan permisos.
6. El contador de notificaciones baja al marcarlas como leídas.
7. Funciona en móvil (lectura) y con teclado; sin errores de consola ni de CSP.
8. El cron deja `kpi_snapshots` del día anterior y limpia `cache_kv` vencida.
9. `sql/verificar_fase1.sql` devuelve `OK` en las 11 comprobaciones.

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

### Resultado del 31/10/2026

Suite E2E (`probar.ps1`) contra MariaDB 10.4 real en `mg_test`, esquema heredado + migración 001 aplicada dos veces:

**53 comprobaciones, todas en OK.** Cubre: idempotencia de la migración y de `verificar_fase1.sql` (11 OK), KPIs exactos de 7 días (5 pedidos, $1.350.000, ticket $270.000, 70 visitas, 7,14 %), caché en la segunda llamada, snapshots de días cerrados, auto-reparación, alertas, `401` sin sesión, `403` por permiso, CSRF obligatorio, beacon deduplicado, búsqueda, cron con token e idempotencia.

Dos fallos reales encontrados y corregidos gracias a la suite:

1. `ORDER BY (stock - stock_minimo)` reventaba con `SQLSTATE[22003] … BIGINT UNSIGNED value is out of range` cuando `stock < stock_minimo`. Ahora usa `CAST(… AS SIGNED)`.
2. La dona se estiraba al ancho de la tarjeta (cuadrado de 184 px → 463 px) porque `width: 100%` pisaba el atributo `width`; se fijó con la clase `g-dona-svg`.

En navegador: dashboard a 1440 px, 1016 px y 320 px sin desbordes horizontales, cero errores y cero avisos de consola, menú móvil con foco y `Escape`, paleta `Ctrl + K`, rango `30 días` y estados de carga verificados.

## 10. Riesgos

| Riesgo | Mitigación |
|---|---|
| CPU/I-O del plan Single | Snapshots, caché 60 s, índices, series acotadas |
| Conversión inventada | Beacon obligatorio; si falta, `—` |
| Polling agresivo | Solo pestaña visible + 60 s + caché |
| CSP bloquea módulos | Todo servido desde el mismo origen; sin CDN |
| Consultas `SUM()` sobre todo el histórico | Días cerrados desde `kpi_snapshots` |

## 11. Despliegue

0. **Antes de codificar:** en phpMyAdmin selecciona la base de datos de la tienda (no `information_schema`), ejecuta `sql/migraciones/001_fase1_dashboard.sql` y comprueba con `sql/verificar_fase1.sql`. La primera fila debe mostrar la base correcta y las 11 comprobaciones deben dar `OK`. *(Hecho el 30/10/2026 en `u441937558_Ecommerce`.)*
1. Subir `api/src/`, `cron/` y `backoffice/`.
2. Crear el token del cron en `config.local.php` (`CRON_TOKEN`).
3. Programar en hPanel: `php /home/uXXXX/cron/cron.php` cada 10 min.
4. Probar `?r=kpis` con sesión real.
5. Mantener `admin.html` publicado; `backoffice/` se enlaza desde la portada cuando la Fase 3 dé paridad.

**Rollback:** borrar `backoffice/` y el cron; la API existente no cambia.

## 12. Definición de terminado

`php -l` sin errores, flujos críticos probados en móvil, backup restaurado en entorno de prueba y los 9 criterios de aceptación cumplidos.

Estado al 31/10/2026: los 9 criterios tienen evidencia (suite E2E + navegador), `php -l` y `node --check` limpios. Las pruebas corrieron sobre un esquema heredado reconstruido, no sobre un backup de producción restaurado; conviene repetir la suite con un volcado real antes de tocar datos de clientes. Lo que falta para cerrar la fase es solo operativo: subir `backoffice/`, `api/src/`, `cron/` y los tres archivos modificados, crear `CRON_TOKEN` en producción y programar el cron.
