# Plan de Refactorización · MotoGear

> **Backend MVC + Capa de Servicios · Frontend SPA en JS Vanilla**
> **Estado:** pendiente de aprobación (no se ha modificado ningún archivo de código).

## Objetivo

Refactorizar todo el proyecto (Backend + Frontend) aplicando **SOLID**, **DRY** y **KISS**, preservando el 100 % de la funcionalidad existente y las restricciones técnicas del proyecto:

- PHP 8+ nativo (sin Composer) y JS Vanilla ES Modules (sin Webpack/Vite/npm).
- Funciona directo al subirse a Hostinger (LiteSpeed) con `tools/subir.ps1`.
- Sin frameworks, sin CDN, sin scripts/estilos inline (CSP estricta).
- Nombres en español, dinero en enteros COP, zona `America/Bogota`, el servidor calcula precios/stock/totales.
- Sin regresiones en tienda, carrito, checkout, catálogo, backoffice, cron ni lógica de negocio.

---

## 1. Nuevo árbol de directorios

### Backend (`api/`)

```
api/
├── api.php                      ← front controller (autoloader + Container + Router)
├── config.php                   ← sin cambios (secretos)
├── config.local.php.ejemplo     ← sin cambios
├── .htaccess                    ← sin cambios
└── src/
    ├── Core/
    │   ├── Autoloader.php       ← PSR-4 (namespace App\ → api/src/)
    │   ├── Container.php        ← DI simple (singletons + auto-wiring por reflection)
    │   ├── Database.php         ← wrapper PDO (reemplaza Conexion::db)
    │   ├── Request.php          ← método, ?r=, body JSON, files, ip, headers
    │   ├── Response.php         ← JsonResponse estándar (status + JSON unicode)
    │   ├── Router.php           ← ?r= → controlador/método + guards
    │   ├── Exceptions.php       ← UserError, HttpError(401/403/404/422)
    │   ├── Guard.php            ← usuario_actual/permisos/puede/admin/auditar
    │   ├── Cache.php            ← cache_leer/guardar/limpiar
    │   ├── Texto.php            ← s(), slug()
    │   └── Sql.php              ← ph() (placeholders)
    ├── Controllers/             ← THIN: parsea, delega a Service, devuelve DTO
    │   ├── CatalogoController.php
    │   ├── CheckoutController.php   (público: catalogo, pedido, carrito, cupon, mensaje, visita)
    │   ├── PedidoController.php
    │   ├── CrmController.php
    │   ├── MarketingController.php
    │   ├── SeguridadController.php  (login/logout/me/2fa)
    │   ├── DashboardController.php  (kpis/alertas/notificaciones/buscar)
    │   └── AdminController.php      (usuarios/roles/auditoría/configuración/mensajes)
    ├── Services/                ← lógica de negocio pura (sin SQL directo)
    │   ├── CatalogoService.php
    │   ├── ImagenService.php    ← GD/WebP (extraído de Catalogo)
    │   ├── CheckoutService.php
    │   ├── PedidoService.php
    │   ├── CrmService.php
    │   ├── MarketingService.php
    │   ├── MailService.php      ← SMTP + CCo (extraído de Marketing)
    │   ├── SeguridadService.php ← TOTP/2FA
    │   ├── AuthService.php      ← login/logout/sesión
    │   ├── OutboxService.php
    │   ├── DashboardService.php ← métricas
    │   └── AdminService.php
    ├── Repositories/            ← único lugar con SQL (PDO preparado)
    │   ├── Contracts/           ← interfaces para tests sin DB
    │   │   ├── ProductoRepositoryInterface.php
    │   │   ├── PedidoRepositoryInterface.php
    │   │   ├── ClienteRepositoryInterface.php
    │   │   ├── CuponRepositoryInterface.php
    │   │   └── ... (resto por dominio)
    │   └── ProductoRepository.php, PedidoRepository.php,
    │       ClienteRepository.php, CuponRepository.php, VarianteRepository.php,
    │       CategoriaRepository.php, InventarioRepository.php, DevolucionRepository.php,
    │       TransportadoraRepository.php, UsuarioRepository.php, RolRepository.php,
    │       AuditoriaRepository.php, ConfiguracionRepository.php, MensajeRepository.php,
    │       MetricasRepository.php, NotificacionRepository.php, OutboxRepository.php
    ├── Models/                  ← entidades/DTO tipados
    │   ├── Producto.php, Variante.php, Pedido.php, PedidoItem.php, Cliente.php,
    │   │   Cupon.php, Promocion.php, Mensaje.php, Usuario.php, Pago.php ...
    │   └── Dto/ (PedidoCreadoDto, KpisDto, etc.)
    └── Views/
        └── JsonResponse.php     ← respuesta JSON estándar
```

### Frontend Tienda Pública (raíz)

```
index.html                      ← ÚNICO punto de entrada (layout base + <main id="app">)
style.css                       ← CSS consolidado (o enlazar los 5 actuales en index)
js/
├── app.js                      ← bootstrap: monta layout, Router, Store
├── core/
│   ├── Router.js               ← History API (o hash), parámetros de ruta
│   ├── Store.js                ← estado reactivo (carrito, cupón, catálogo, filtros) + localStorage
│   └── formato.js              ← money(), esc()
├── services/
│   └── ApiClient.js            ← fetch centralizado a api/api.php
├── components/
│   ├── Navbar.js               ← nav dinámico + contador de carrito en vivo
│   ├── ProductCard.js
│   ├── Toast.js
│   └── Modal.js
└── views/
    ├── InicioView.js           ← hero + carrusel + destacados
    ├── ProductosView.js        ← catálogo + filtros + orden
    ├── ProductoView.js         ← detalle (nuevo, opcional)
    ├── CarritoView.js          ← carrito + cupón + checkout
    ├── PedidoOkView.js         ← confirmación tras crear pedido
    ├── ContactoView.js
    └── SobreView.js
```

---

## 2. Mapa de migración Backend

| Archivo actual | Nuevas clases |
|---|---|
| `src/Conexion.php` (`db()`) | `Core\Database.php` |
| `src/Util.php` (`UserError, req, s, slug, ph`) | `Core\Exceptions.php`, `Core\Texto.php`, `Core\Sql.php` (`req()` → excepción en services) |
| `src/Cache.php` (`cache_*`) | `Core\Cache.php` |
| `src/Catalogo.php` (`cat_*`) | `Controllers\CatalogoController.php` + `Services\CatalogoService.php` + `Services\ImagenService.php` + `Repositories\{Producto, Variante, Categoria, Inventario}Repository` |
| `src/Checkout.php` (`chk_*`) | `Services\CheckoutService.php` + `Controllers\CheckoutController.php` + `Repositories\PedidoRepository` (con `FOR UPDATE`) |
| `src/CRM.php` (`crm_*`) | `Services\CrmService.php` + `Controllers\CrmController.php` + `Repositories\{Cliente, Devolucion}Repository` |
| `src/Marketing.php` (`mk_*`) | `Services\MarketingService.php` + `Services\MailService.php` + `Controllers\MarketingController.php` + `Repositories\{Cupon, Promocion, Carrito}Repository` |
| `src/Seguridad.php` (`totp_*`, `seg_2fa_*`) | `Services\SeguridadService.php` (TOTP) + `Services\AuthService.php` + `Controllers\SeguridadController.php` |
| `src/Pedidos.php` (`ped_*`) | `Services\PedidoService.php` + `Controllers\PedidoController.php` + `Repositories\{Pedido, Transportadora, Factura}Repository` |
| `src/Metricas.php` (`mg_*`) | `Services\DashboardService.php` + `Controllers\DashboardController.php` + `Repositories\{Metricas, Notificacion}Repository` |
| `src/Outbox.php` (`ob_*`) | `Services\OutboxService.php` + `Repositories\OutboxRepository.php` |
| `src/Admin.php` (`adm_*`) | `Services\AdminService.php` + `Controllers\AdminController.php` + `Repositories\{Usuario, Rol, Auditoria, Configuracion, Mensaje}Repository` |
| `api/api.php` (switch) | `Core\Router.php` + controladores delgados; `api.php` queda como bootstrap (~15 líneas) |
| `db.php` | se fusiona en `api.php` + `Core\Database` |

### Router (`?r=...`)

Lee `$_GET['r']` y despacha **conservando los 65 nombres exactos** de endpoint y las formas de respuesta/estados HTTP. Ejemplo de mapeo:

| `?r=` | Controlador@método | Guard |
|---|---|---|
| `catalogo` | `CheckoutController@catalogo` | público |
| `pedido` | `CheckoutController@pedido` | público |
| `producto_guardar` | `CatalogoController@guardar` | `admin('productos.editar')` |
| `pedido_transicion` | `PedidoController@transicion` | `admin(permiso dinámico)` |
| `login` / `logout` / `me` / `2fa_*` | `SeguridadController@*` | sesión |
| `webhook_epayco` (futuro) | `PagoController@webhook` | firma |

- **Autenticación/CSRF/auditoría:** se conservan en `Core\Guard.php` (misma lógica que hoy en `db.php`).
- **Cron:** `cron/cron.php` pasa a usar `Core\Autoloader` + `Core\Container` + los `Services`, manteniendo token CLI y hora `America/Bogota`.

---

## 3. Mapa de migración Frontend SPA

| Actual | Nuevo |
|---|---|
| `index.html` (inicio) | `index.html` (único) + `views/InicioView.js` |
| `productos.html` + filtros/orden en `main.js` | `views/ProductosView.js` + `components/ProductCard.js` |
| `carrito.html` + checkout en `main.js` | `views/CarritoView.js` + `views/PedidoOkView.js` |
| `contacto.html` + form en `main.js` | `views/ContactoView.js` |
| `sobre.html` | `views/SobreView.js` |
| header/nav/footer duplicados en 5 HTML | `components/Navbar.js` (header) + footer en `index.html` |
| badge carrito + toast en `main.js` | `components/Navbar.js` (contador reactivo) + `components/Toast.js` |
| `main.js` (catálogo/carrito/checkout/contacto/carrusel/visita) | repartido en `core/`, `services/`, `components/`, `views/` |
| `datos.js`, `prueba.js` (muertos) | **eliminar** |
| `style.css` + `productos.css` + `carrito.css` + `contacto.css` + `sobre.css` | consolidar en `style.css` (o enlazar los 5 en `index.html`) |

### Enrutamiento recomendado: History API

Se recomienda **History API** (`/productos`, `/carrito`, `/contacto`, `/sobre`, `/producto/:id`) porque **preserva las URLs actuales** y los enlaces entrantes (WhatsApp, "Volver a la tienda", marcadores). Requiere actualizar el `.htaccess` raíz:

```apache
# fallback SPA: no interceptar API, backoffice, imágenes ni archivos reales
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_URI} !^/(api|backoffice|img|cron)/
RewriteRule ^ index.html [L]
```

Se mantiene la regla de caché (no-Expires) y el versionado `?v=` en CSS/JS.

**Plan B:** si el CDN de Hostinger complica el fallback, se usa **hash** (`#/productos`), igual que el backoffice, sin tocar `.htaccess`.

### Estado (`Store.js`)

Carrito (`mg-cart2`), cupón (`mg-coupon`), caché de catálogo (`DATA`) y filtros; reactivo y con sincronización a `localStorage`. El precio y el stock **siguen calculándose en el servidor** (el `Store` solo muestra estimaciones, igual que hoy).

---

## 4. Plan sobre el panel legacy (`admin.html`)

- `backoffice/` **ya cubre toda la administración**: pedidos (máquina de estados), productos con variantes/imágenes, categorías, cupones+promociones, mensajes, clientes, devoluciones, inventario, transportadoras, usuarios/roles, auditoría, configuración y 2FA.
- El legacy usa el **modelo antiguo** (tallas inline, una sola imagen, endpoints `admin/guardar/borrar/pedido_estado/upload`) y **no aporta ninguna función exclusiva**.
- **Endpoints usados solo por legacy:** `admin`, `guardar`, `borrar`, `pedido_estado`, `upload` (el `clave` sí lo usa el backoffice en `cuenta.js`, así que se conserva).

**Recomendación:** dejar `admin.html`/`admin.js` funcionando durante la migración y, tras confirmar que no hay huecos, **eliminarlo junto con esos 5 endpoints legacy** en una fase final.

---

## 5. Riesgos y validaciones

1. **Sin PHP/MySQL local** → validar en Hostinger: `php -l` archivo por archivo y `tests/run.php` ejecutado en el servidor vía wrapper temporal, más smoke tests por curl/navegador.
2. **Paridad de comportamiento** → los 65 endpoints mantienen nombre, parámetros, respuesta y códigos HTTP idénticos; las transacciones (`FOR UPDATE`), el libro mayor de stock, la auditoría, el outbox y el SMTP se conservan tal cual.
3. **Regresión de negocio** → migración **incremental por dominio**, con `tests/run.php` en verde antes/después de cada dominio; el `Router` convive con el `switch` hasta terminar.
4. **Tests sin DB** → `tests/run.php` instanciará `Services` con `Repositories\Contracts` implementados como *fakes en memoria*.
5. **CSP / sin CDN** → History API + ES modules mantienen `script-src 'self'`; sin scripts inline; los formularios pasan a `fetch`, así `form-action 'self'` no se rompe.
6. **CDN/caché** → versionar JS/CSS y conservar `Header always unset Expires` (lección de `main.js?v=4`).
7. **`.htaccess`** → el fallback SPA no debe interceptar `/api/`, `/backoffice/`, `/img/`, `/cron/` ni archivos estáticos; se elimina la regla actual `.html → sin extensión` sin romper enlaces viejos (se deja un 301 `X.html → /X`).
8. **Alcance grande (big-bang)** → ejecutar en fases con commits pequeños y despliegue por FTP en cada una; `tools/subir.ps1` sigue subiendo solo lo modificado.

---

## 6. Decisiones pendientes (aprobación)

1. **¿Apruebas este plan?**
2. **Routing:** ¿History API (URLs limpias, recomendado) o hash (`#/...`)?
3. **Panel legacy:** ¿confirmas que puedo eliminar `admin.html`/`admin.js` y sus 5 endpoints al final, o hay algo que aún usas ahí?
4. **Detalle de producto:** ¿agrego una vista de detalle (`/producto/:id`) o mantengo el flujo actual (agregar directo desde la tarjeta)?
