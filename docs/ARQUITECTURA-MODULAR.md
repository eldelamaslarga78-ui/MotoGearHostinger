# Arquitectura modular · MotoGear

> **Decisión:** monolito modular en Hostinger, sin microservicios.
> **Motivo:** hosting compartido, un solo desarrollador y menos de 500 pedidos al mes.
> Ver la discusión en el historial del proyecto.

## Mapa de módulos

| Módulo | Archivo | Responsabilidad |
|---|---|---|
| Helpers | `api/src/Util.php` | `UserError`, `req()`, `s()`, `slug()`, `ph()` |
| Catálogo | `api/src/Catalogo.php` | Productos, variantes, inventario, imágenes, categorías |
| Pedidos | `api/src/Pedidos.php` | Máquina de estados (`PEDIDO_TRANSICIONES`), pago, guía, factura, expiración |
| Checkout | `api/src/Checkout.php` | Creación atómica del pedido, en funciones de una sola responsabilidad |
| CRM | `api/src/CRM.php` | Clientes, direcciones, devoluciones (RMA), fusión |
| Marketing | `api/src/Marketing.php` | Cupones, promociones, carritos, envío de correo SMTP |
| Outbox | `api/src/Outbox.php` | Cola transaccional de mensajes salientes |
| Seguridad | `api/src/Seguridad.php` | 2FA (TOTP RFC 6238) |
| Métricas | `api/src/Metricas.php` | KPIs del dashboard y snapshots |

`api/api.php` es solo el router `?r=`: valida permisos y delega en los módulos.

## Reglas entre módulos

- Un módulo no consulta tablas que pertenecen a otro sin pasar por sus funciones públicas.
- Toda escritura de negocio ocurre dentro de una transacción.
- El stock solo cambia junto con su fila en `movimientos_stock`.
- El correo nunca se envía dentro de la petición del cliente: se encola en `outbox`.

## Outbox (cola de correos)

```
Checkout ──(misma transacción)──> pedidos + pedido_items + outbox(pendiente)
                                            │
                       ┌────────────────────┴────────────────────┐
                       │                                         │
    1) envío inmediato (tras responder)              2) red de seguridad
       litespeed_finish_request → SMTP                     cron cada 5 min
       ob_procesar_ids([id], timeout 6 s)                  ob_procesar(20)
                       │                                         │
                       └──────────► enviado | pendiente (reintento) | fallido (5 intentos)
```

- `ob_encolar()` se llama dentro de la transacción del negocio: el mensaje existe si y solo si existe el pedido.
- **Envío inmediato:** el router responde al cliente (JSON) y llama a `litespeed_finish_request()`, que libera la conexión; después envía el correo con `ob_procesar_ids()` y un tiempo límite de 6 s. El cliente no espera al SMTP.
- **Red de seguridad:** si el envío inmediato falla o expira, la fila queda `pendiente` y el cron la reintenta (hasta 5 intentos, guardando `ultimo_error`).
- Consumidores actuales: confirmación de pedido con enlace a WhatsApp, recordatorio de carrito a las 24 h y recuperación manual desde el panel.

**Latencia:** normalmente el correo sale a los ~2 s del pedido. Solo si el SMTP falla se espera al cron (hasta 5 min).

**Copia oculta:** `CORREO_CCO` (en `config.local.php`) añade un segundo destinatario en el sobre SMTP (`RCPT TO`), sin cabecera `Bcc`, para que la dirección nunca se exponga al cliente. Es "mejor esfuerzo": si la copia falla, el correo al cliente sale igual.

## Pruebas

```
php tests/run.php
```

`tests/` cubre lógica sin escrituras en la base de datos:
- Cupones: porcentaje, monto fijo, envío gratis, vencimiento, mínimo, agotado, primera compra.
- Checkout: subtotal, cantidades, talla, stock (incluido el acumulado de líneas repetidas), datos del cliente, campo trampa y correo obligatorio.
- Estados de pedido: transiciones válidas e inválidas.
- 2FA: codificación Base32, verificación y rechazo.
- Utilidades: `s()`, `slug()`, `req()`.

**Estado:** 38 de 38 pruebas pasan en el servidor (PHP 8.3).

`tests/` **no se sube** al servidor público (`tools/subir.ps1` y `tools/vigilar.ps1` la excluyen). Para ejecutarla en producción se sube temporalmente con un script protegido por token y se borra al terminar.

## Fase D (diferida)

Caché de catálogo, servicios separados y colas externas se posponen hasta superar ~500 pedidos al mes o tener un VPS con contenedores. Con el volumen actual no aportan y suman operación.

## Migraciones

| Versión | Archivo | Contenido |
|---|---|---|
| 1 | `001_fase1_dashboard.sql` | Snapshots, visitas, notificaciones |
| 2 | `002_catalogo.sql` | Columnas de catálogo, `producto_imagenes` |
| 3 | `003_pedidos.sql` | Pagos, envíos, facturas, transportadoras |
| 4 | `004_crm_rma.sql` | Direcciones, devoluciones |
| 5 | `005_marketing.sql` | Cupones avanzados, promociones, carritos |
| 6 | `006_seguridad.sql` | 2FA en usuarios |
| 7 | `007_outbox.sql` | Cola de mensajes salientes |
