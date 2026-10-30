# Fase 0 · Despliegue seguro

> Cimientos del backoffice: secretos fuera del repositorio, RBAC, variantes e inventario con libro mayor.
> Tiempo estimado: **20 minutos**. Ventana recomendada: con la tienda sin tráfico.

---

## Qué cambia

| Antes | Ahora |
|---|---|
| Credenciales en `api/config.php` (versionadas) | `config.local.php` fuera de `public_html` (ignorado por Git) |
| `admins` (un solo usuario, sin roles) | `usuarios` + `roles` + `permisos` + `auditoria` |
| `producto_tallas` | `variantes` (talla + color + SKU + stock mínimo), **sin borrar la tabla vieja** |
| Stock editado a mano | `movimientos_stock` (libro mayor: quién, cuánto, por qué) |
| Sin cabeceras de seguridad | CSP, HSTS, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` |
| Sin rastro de acciones | `auditoria` de cada cambio sensible |

**Nada se borra.** `producto_tallas` y `admins` quedan intactas como respaldo: si algo sale mal, restauras el backup y vuelves a subir el `api.php` anterior.

---

## Paso 0 · Respaldo (no lo saltes)

1. hPanel → Bases de datos → **Copias de seguridad** → descarga un `.sql` completo.
2. Descarga el contenido actual de `public_html` a tu PC (o usa el commit `1226320` de Git).

---

## Paso 1 · Crear `config.local.php` fuera del sitio

En hPanel → **Administrador de archivos**, entra a `/home/uXXXXXXX/` (el home, **no** `public_html`).

1. Crea el archivo `config.local.php` con este contenido y tus datos actuales de MySQL:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'u000000000_Ecomerce');   // los tuyos aparecen en hPanel → Bases de datos
define('DB_USER', 'u000000000_Motogear');
define('DB_PASS', 'TU_CLAVE_ACTUAL');       // no la escribas en el repositorio
```

2. Verifica que la ruta queda así: `/home/uXXXXXXX/config.local.php` y el sitio en `/home/uXXXXXXX/public_html/`.
   El cargador busca exactamente ahí (`dirname(__DIR__, 2)` desde `public_html/api/`).
3. Si tu plan no te deja escribir fuera de `public_html`, usa `public_html/api/config.local.php`
   (funciona igual, pero `api/.htaccess` lo bloquea por HTTP y el log avisará de que está dentro del sitio).

La plantilla `api/config.local.php.ejemplo` está en el repo solo como referencia. **No la edites ahí.**

---

## Paso 2 · Subir solo el cargador y el migrador

Sube **únicamente** estos tres archivos:

- `api/config.php`  ← ahora sin credenciales, solo carga `config.local.php`
- `api/migrar_fase0.php`
- `api/.htaccess`

El resto del sitio sigue con el código anterior, así que **todo debe seguir funcionando**.
Comprobación rápida: abre la tienda, entra a un producto y verifica que el catálogo carga.

> Si ves `Configuración del servidor incompleta` → `config.local.php` no está en la ruta esperada.
> Revisa el paso 1 y el log de errores de hPanel.

---

## Paso 3 · Ejecutar la migración

1. Entra a `https://tudominio.com/admin.html` e inicia sesión (como siempre).
2. Sin cerrar sesión, abre `https://tudominio.com/api/migrar_fase0.php`.
3. Pulsa **Ejecutar migración**.

Verás el detalle de cada paso. Es **idempotente**: si se corta a mitad, vuelve a pulsar y continúa sin duplicar nada.

Qué hace exactamente:

- Crea `roles`, `permisos`, `rol_permisos`, `usuarios`, `auditoria`.
- Siembra 4 roles (admin, ventas, bodega, soporte) y 19 permisos; el rol `admin` recibe todos.
- Copia tus usuarios de `admins` a `usuarios` **con el mismo hash de contraseña** (no cambias nada al entrar).
- Crea `variantes` y copia el stock de `producto_tallas` (y de `productos.tallas` si tu tienda es más vieja).
- Crea `movimientos_stock` y registra el stock actual como "Carga inicial".
- Añade `variante_id`, `color` y `sku` a `pedido_items` y enlaza los pedidos históricos.
- Crea `pedido_eventos` y `configuracion`; guarda `schema_version = 1`.
- Ajusta el tipo de `producto_id` / `pedido_id` (con o sin signo) y la colación de las tablas
  nuevas a las de tus tablas actuales: así las claves foráneas y los `JOIN` por `talla`
  funcionan sin tocar el esquema viejo.

---

## Paso 4 · Subir el código nuevo

Ahora sí, sube:

- `api/db.php`
- `api/api.php`
- `admin.js`
- `.htaccess` (raíz)
- `contacto.css` y `contacto.html` (se quitó un `style=""` inline para que la CSP no lo bloquee)

Si tu panel usa `admin.css?v=5` / `admin.js?v=5`, sube también `admin.html` para forzar la recarga.

---

## Paso 5 · Verificación (5 minutos)

| Prueba | Resultado esperado |
|---|---|
| Abrir la tienda | Carga normal, fuentes e imágenes bien, mapa de contacto visible |
| Consola del navegador (F12) | **Sin errores de CSP** (`Refused to …`) |
| Login en `admin.html` | Entra con tu usuario de siempre |
| Editar un producto y guardar | Se guarda; en phpMyAdmin aparece una fila nueva en `variantes` y otra en `auditoria` |
| Crear producto con tallas S, M, L | 3 filas en `variantes` con SKU `MG-<id>-S`, `-M`, `-L` |
| Pedido de prueba desde la tienda | Se crea con su `pedido_eventos` y descuenta `variantes.stock` |
| Cancelar ese pedido en el panel | El stock vuelve exactamente a su valor anterior y se registra un movimiento `devolucion` |
| Subir una imagen de producto | Se convierte a `.webp` en `img/productos/` y el producto la muestra |
| Eliminar un producto **con** pedidos | Se archiva (`activo=0`) en lugar de borrarse, con aviso en pantalla |

En phpMyAdmin, consulta de control:

```sql
SELECT p.nombre, v.sku, v.talla, v.stock, v.stock_minimo
  FROM variantes v JOIN productos p ON p.id = v.producto_id
 ORDER BY p.id, v.talla;

SELECT tipo, COUNT(*) FROM movimientos_stock GROUP BY tipo;
```

---

## Paso 6 · Cerrar la puerta

1. **Borra `api/migrar_fase0.php`** del servidor (y descomenta el bloque de `api/.htaccess` si quieres la red de seguridad).
2. **Cambia la contraseña de MySQL** en hPanel (estuvo expuesta en un repo público) y actualiza `config.local.php`.
3. Verifica que `https://tudominio.com/api/config.local.php` devuelve **403**.
4. Verifica que `https://tudominio.com/api/install.lock` devuelve **403**.
5. Si vas a rotar también el acceso FTP: usa SFTP/SSH y borra `sftp.json` y `.vscode/sftp.json` de tu PC.

---

## Cómo funciona el RBAC desde ahora

- Roles y permisos viven en la base de datos; el panel oculta pestañas y botones según el rol.
- **El servidor siempre valida**: ocultar un botón es UX, el `403` es la seguridad.
- Permisos actuales: `pedidos.ver/editar/cancelar`, `productos.ver/editar/borrar`, `categorias.editar/borrar`,
  `cupones.ver/editar/borrar`, `mensajes.ver/borrar`, `clientes.ver`, `devoluciones.ver`,
  `usuarios.gestionar`, `auditoria.ver`, `config.editar`, `dashboard.ver`.
- Tu usuario quedó con el rol **admin** (comodín `*`): no pierdes ninguna capacidad.
- La pantalla para crear empleados llega en la Fase 1. Mientras tanto solo existe tu usuario.

---

## Si algo falla

| Síntoma | Causa probable | Solución |
|---|---|---|
| `Configuración del servidor incompleta` | `config.local.php` mal ubicado | Paso 1; revisa el log de hPanel |
| `No autorizado` en el migrador | Sesión no iniciada o expirada | Entra primero a `admin.html` en la misma pestaña |
| `Token de seguridad inválido` | Página vieja tras iniciar sesión | Recarga la página del migrador |
| Login dice "Usuario o contraseña incorrectos" | Migración no ejecutada | Vuelve al paso 3 |
| Catálogo vacío tras el paso 4 | `variantes` sin datos | Re-ejecuta el migrador (`?forzar=1`), es idempotente |
| Página sin estilos tras `.htaccess` | CSP bloqueando algo | Mira la consola; añade el dominio a `style-src`/`frame-src` |
| Imágenes de `sobre.html` no cargan | CSP sin `images.pexels.com` | Ya está incluido; si añades otro dominio de fotos, ábrelo en `img-src` |
| Al subir imagen: `El servidor no tiene soporte WebP (GD)` | Falta la extensión GD | hPanel → PHP → Extensiones → activa `gd` (con soporte WebP) |
| Error 500 al guardar producto | Falta una columna | Revisa `error_log`; el mensaje indica la tabla |

**Reversión completa:** restaura el backup SQL y vuelve a subir el `api/api.php` y `api/db.php` anteriores. Como `producto_tallas` y `admins` nunca se tocaron, el sistema vuelve exactamente al estado previo.
