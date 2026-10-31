# Fase 3 · Pedidos

> **Duración:** 2 semanas · **Estado:** pendiente
> **Depende de:** Fase 2 (catálogo y variantes)
> **Entregable verificable:** un pedido recorre todo su ciclo —creación, pago, preparación, guía, entrega y factura— sin tocar la base de datos a mano.
> **Documento base:** `docs/ARQUITECTURA-BACKOFFICE.md` § 2 y § 1.3.4–1.3.7.

---

## 1. Alcance

### Entra

- Máquina de estados con transiciones validadas y auditadas.
- Drawer de pedido de 560 px con detalle, ítems, cliente, pagos, envío y eventos.
- Confirmación manual de pago y emisión de factura interna.
- Picking: lista de preparación por SKU, talla, color y cantidad.
- Guías de envío con transportadora y URL de tracking.
- Factura imprimible (el navegador guarda PDF), numeración consecutiva interna.
- Cron de expiración de pedidos `pendiente_pago` a las 2 horas.
- Idempotencia en la creación del pedido.
- Búsqueda y filtros por estado, fecha, cliente y código.

### No entra

- Pasarela de pago automática (Fase 6).
- Devoluciones/RMA (Fase 4); en esta fase `devuelto` solo se alcanza con un RMA aprobado.
- Facturación electrónica DIAN.

## 2. Punto de partida

- `POST ?r=pedido` ya valida stock, aplica cupón, descuenta inventario y registra `movimientos_stock` y `pedido_eventos`.
- `POST ?r=pedido_estado` cambia el estado sin máquina de estados; debe reemplazarse.
- `GET ?r=admin` devuelve pedidos y mensajes al panel.
- Tablas `pedidos`, `pedido_items`, `pedido_eventos`, `pagos`, `envios`, `transportadoras`, `facturas`, `zonas_*` y `tarifas_envio` ya existen.

## 3. Modelo de datos

Sin tablas nuevas. Se añade la clave de consecutivo de factura:

```sql
-- sql/migraciones/003_pedidos.sql
INSERT INTO configuracion (clave, valor, tipo, grupo, es_publico)
VALUES ('factura_consecutivo', '0', 'numero', 'facturacion', 0)
ON DUPLICATE KEY UPDATE clave = clave;
```

| Tabla | Uso |
|---|---|
| `pedidos` | Estado, pago, totales, `idempotencia`, `expira_pago` |
| `pedido_items` | Snapshots de nombre, SKU, talla, color, precio y costo |
| `pedido_eventos` | Historial inmutable de transiciones |
| `pagos` | Registro manual o de pasarela |
| `envios`, `transportadoras` | Guía y tracking |
| `facturas` | Numeración interna |
| `zonas_cobertura`, `tarifas_envio` | Costo de envío por zona y peso |

## 4. Backend

| Ruta | Método | Permiso | Función |
|---|---|---|---|
| `?r=pedidos` | GET | `pedidos.ver` | Lista paginada con filtros |
| `?r=pedido_detalle&id=` | GET | `pedidos.ver` | Cabecera, ítems, pagos, envío, eventos |
| `?r=pedido_transicion` | POST | según transición | Cambia de estado con validación |
| `?r=pedido_pago` | POST | `pedidos.editar` | Registra pago manual y confirma |
| `?r=pedido_nota` | POST | `pedidos.editar` | Nota interna |
| `?r=guia_guardar` | POST | `envios.gestionar` | Crea/actualiza envío y pasa a `enviado` |
| `?r=factura_emitir` | POST | `facturas.emitir` | Emite factura una sola vez |
| `?r=factura_ver&id=` | GET | `facturas.ver` | HTML imprimible |
| `?r=transportadoras` | GET/POST | `envios.gestionar` | CRUD básico |
| `cron/cron.php` | CLI | token | Expira pedidos y restaura stock |

### Máquina de estados

| Desde | Hacia | Permiso | Efectos |
|---|---|---|---|
| — | `pendiente_pago` | público | Reserva stock, evento, notificación |
| `pendiente_pago` | `confirmado` | `pedidos.editar` | Pago, factura, correo |
| `pendiente_pago` | `cancelado` | `pedidos.cancelar` | Stock devuelto, cupón revertido |
| `confirmado` | `en_preparacion` | `pedidos.editar` | Responsable, picking |
| `en_preparacion` | `enviado` | `envios.gestionar` | `envios` + guía + tracking |
| `enviado` | `entregado` | `pedidos.editar` | `entregado_en`, postventa |
| `enviado` / `entregado` | `devuelto` | `devoluciones.editar` | Solo con RMA (Fase 4) |
| `cancelado` | — | nadie | Terminal |

Toda transición: `BEGIN` → `SELECT … FOR UPDATE` → validar estado actual → `UPDATE pedidos` → `INSERT pedido_eventos` → efectos → `COMMIT`. Nunca un `UPDATE estado` suelto.

### Reglas de negocio

- **Idempotencia:** `Idempotency-Key` (UUID) en la cabecera; se guarda en `pedidos.idempotencia` (único). Un reintento devuelve `200` con el pedido existente.
- **Expiración:** `expira_pago = NOW() + 2 h` al crear. El cron cancela solo si sigue en `pendiente_pago`; devuelve stock **una vez** y revierte `cupon_usos`.
- **Pago manual:** marca `pago_estado='pagado'`, pasa a `confirmado`, emite factura y notifica.
- **Factura:** numeración `MG-000123` con contador en `configuracion` bloqueado con `FOR UPDATE`; una factura por pedido (`pedido_id` único); reimprimir no genera otra.
- **Guía:** guardar `guia` pasa el pedido a `enviado`; la URL se arma con `tracking_url` reemplazando `{guia}`.
- **Entrega:** guarda `entregado_en` y actualiza contadores del cliente (base para Fase 4).
- **Totales:** siempre recalculados en servidor; el `subtotal` del cliente se ignora si no coincide.

## 5. Frontend

```
admin/js/vistas/
├── pedidos.js          tabla, filtros, chips, vistas guardadas
├── pedido.js           drawer 560 px
└── factura.js          vista imprimible
```

- Drawer lateral que conserva la tabla y el scroll de fondo.
- Cabecera del drawer: `#MG-000123`, estado, total, cliente y acciones según permiso.
- Pestañas internas: Resumen, Ítems, Pago, Envío, Eventos, Notas.
- Picking imprimible: una línea por SKU/talla/color con cantidad y checkbox.
- Timeline de eventos con usuario y fecha.
- Confirmaciones destructivas: escribir `CANCELAR` para cancelar un pedido.
- Factura: plantilla HTML/CSS pensada para A4 y `@media print`; sin dependencias.

## 6. Permisos

| Clave | Estado | Roles |
|---|---|---|
| `pedidos.ver` | existe | admin, ventas, bodega, soporte |
| `pedidos.editar` | existe | admin, ventas, bodega |
| `pedidos.cancelar` | existe | admin |
| `envios.gestionar` | **nueva** | admin, bodega |
| `facturas.emitir` | **nueva** | admin, ventas |
| `facturas.ver` | **nueva** | admin, ventas, soporte |

## 7. Plan de tareas

### Semana 1

- [ ] `EstadoMaquina` con matriz de transiciones y permisos.
- [ ] `pedidos`, `pedido_detalle`, `pedido_transicion`, `pedido_pago`, `pedido_nota`.
- [ ] Reemplazar `pedido_estado` por la máquina (mantener alias temporal).
- [ ] Idempotencia en la creación del pedido.
- [ ] Drawer, timeline y acciones por permiso.

### Semana 2

- [ ] Picking imprimible.
- [ ] `guia_guardar`, transportadoras y tracking.
- [ ] `factura_emitir`, `factura_ver` y consecutivo.
- [ ] Cron de expiración con devolución de stock.
- [ ] Pruebas de concurrencia, doble cancelación y doble guía.

## 8. Criterios de aceptación

1. Un pedido completo se gestiona sin abrir phpMyAdmin.
2. Las transiciones inválidas se rechazan con `422` y las no autorizadas con `403`.
3. Cancelar dos veces no devuelve stock dos veces.
4. El cron cancela pedidos vencidos y revierte el cupón.
5. La factura se emite una sola vez y el consecutivo no tiene huecos ni duplicados.
6. La guía genera URL de tracking válida.
7. El total del pedido coincide con ítems + envío + impuestos − descuento.
8. El drawer funciona en móvil como tarjeta apilada.

## 9. Pruebas

| Caso | Esperado |
|---|---|
| Repetir `POST ?r=pedido` con misma clave | Mismo pedido, sin duplicar stock |
| Cancelar `entregado` | `422` |
| Cancelar dos veces | Segundo intento `422`, stock intacto |
| Guía sin transportadora | `422` |
| Factura duplicada | Devuelve la existente |
| Emitir factura sin pago | `422` |
| Cron con pedido pagado | No lo cancela |
| Rol bodega intenta facturar | `403` |
| Transición sin CSRF | Rechazada |

## 10. Riesgos

| Riesgo | Mitigación |
|---|---|
| Devolución doble de stock | Validar estado dentro de la transacción con `FOR UPDATE` |
| Huecos en facturación | Contador bloqueado y transacción única |
| Cron duplicado | Idempotencia por estado; repetir no rompe |
| Zona horaria en expiraciones | `America/Bogota` fija y documentada |
| Pedidos huérfanos sin ítems | La transacción crea cabecera e ítems juntos |

## 11. Despliegue

1. Ejecutar `003_pedidos.sql` (consecutivo).
2. Subir API, vistas y cron.
3. Programar el cron cada 5 min.
4. Probar un pedido de prueba de punta a punta y cancelarlo.
5. Verificar que `admin.html` sigue funcionando mientras se migra la UI.

**Rollback:** conservar `pedido_estado` como alias; desactivar el cron; restaurar backup si el consecutivo queda inconsistente.

## 12. Definición de terminado

`php -l` limpio, ciclo completo probado en móvil, backup restaurado y los 8 criterios de aceptación cumplidos.
