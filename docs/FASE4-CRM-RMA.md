# Fase 4 · CRM y RMA

> **Duración:** 2 semanas · **Estado:** pendiente
> **Depende de:** Fase 3 (pedidos entregados y facturación)
> **Entregable verificable:** una devolución completa que reingresa stock, ajusta el pago y deja rastro de auditoría, más ficha de cliente con historial real.
> **Documento base:** `docs/ARQUITECTURA-BACKOFFICE.md` § 1.3.3, § 1.3.9 y § 2.2 (paso 10).

---

## 1. Alcance

### Entra

- Listado de clientes con búsqueda por nombre, email, teléfono y documento.
- Ficha 360: datos, direcciones, notas, consentimiento de marketing, historial de pedidos y devoluciones.
- Segmentos calculados: nuevo, recurrente, VIP e inactivo.
- Gestión de devoluciones (RMA): solicitud, aprobación, recepción y reembolso.
- Reingreso de stock al recibir la devolución, una sola vez.
- Detección de clientes duplicados y fusión manual.
- Exportación CSV de clientes y devoluciones.

### No entra

- Campañas de correo a segmentos (Fase 5).
- Programa de puntos o fidelización (ruta de evolución).
- Devolución parcial con cambio de talla automático (se registra como `cambio`, sin reposición automática).

## 2. Punto de partida

- Al crear un pedido, el servidor crea o reutiliza el cliente por email o teléfono.
- `clientes` existe desde la Fase 3 (vínculo `pedidos.cliente_id`).
- `cliente_direcciones`, `devoluciones` y `devolucion_items` **no existen todavía**; las crea la migración `004`.
- `pedidos.entregado_en` y los estados llegan desde Fase 3.
- Permisos `clientes.ver` y `devoluciones.ver` ya existen.

## 3. Modelo de datos

La migración `004` crea `cliente_direcciones`, `devoluciones` y `devolucion_items` (DDL en `sql/schema.sql` § 1.3.3 y § 1.3.9) y recalcula los contadores de `clientes`:

```sql
-- sql/migraciones/004_crm_rma.sql
-- Recalcular contadores desde pedidos entregados
UPDATE clientes c
SET total_pedidos = (
      SELECT COUNT(*) FROM pedidos p
      WHERE p.cliente_id = c.id AND p.estado = 'entregado'
    ),
    total_gastado = (
      SELECT COALESCE(SUM(p.total), 0) FROM pedidos p
      WHERE p.cliente_id = c.id AND p.estado = 'entregado'
    ),
    primera_compra = (
      SELECT MIN(p.creado) FROM pedidos p
      WHERE p.cliente_id = c.id AND p.estado = 'entregado'
    ),
    ultima_compra = (
      SELECT MAX(p.creado) FROM pedidos p
      WHERE p.cliente_id = c.id AND p.estado = 'entregado'
    );
```

| Tabla | Uso |
|---|---|
| `clientes` | Datos, notas, consentimiento, contadores |
| `cliente_direcciones` | Direcciones con alias y principal |
| `devoluciones` | Cabecera, motivo, estado y monto |
| `devolucion_items` | Ítems, cantidad, acción, `reingresa_stock` |
| `pedidos`, `pedido_items` | Historial y validación de cantidades |
| `movimientos_stock` | Entrada por `ref_tipo='devolucion'` |

## 4. Backend

| Ruta | Método | Permiso | Función |
|---|---|---|---|
| `?r=clientes` | GET | `clientes.ver` | Lista paginada con segmento y búsqueda |
| `?r=cliente_detalle&id=` | GET | `clientes.ver` | Ficha 360 |
| `?r=cliente_guardar` | POST | `clientes.editar` | Datos, notas y consentimiento |
| `?r=cliente_direccion` | POST | `clientes.editar` | Alta/edición de dirección |
| `?r=cliente_fusionar` | POST | `clientes.editar` | Fusiona duplicados |
| `?r=devoluciones` | GET | `devoluciones.ver` | Lista con estado y antigüedad |
| `?r=devolucion_crear` | POST | `devoluciones.editar` | Abre RMA sobre pedido enviado/entregado |
| `?r=devolucion_transicion` | POST | `devoluciones.editar` | Aprobar, rechazar, recibir, reembolsar |

### Reglas de negocio

- **Identificación:** email o teléfono normalizado. Si no existe, se crea; nunca se duplica por mayúsculas o formato.
- **Fusión:** mueve pedidos, direcciones y devoluciones al cliente destino y deja el origen inactivo; audita ambos IDs.
- **Contadores:** se actualizan al pasar a `entregado` y se recalculan por migración; nunca se editan a mano.
- **Segmentos** (umbrales en `configuracion`):
  - `nuevo`: 1 pedido entregado.
  - `recurrente`: 2–4 pedidos.
  - `VIP`: 5 o más pedidos, o gasto sobre el umbral configurado.
  - `inactivo`: sin compras en 90 días.
- **RMA:** solo sobre pedidos `enviado` o `entregado`; motivo obligatorio; cantidad devuelta ≤ comprada − ya devuelta.
- **Estados RMA:** `solicitada → aprobada | rechazada`; `aprobada → recibida`; `recibida → reembolsada`.
- **Stock:** al pasar a `recibida`, si `reingresa_stock = 1`, se inserta `movimientos_stock` tipo `devolucion` una sola vez y se actualiza la variante en la misma transacción.
- **Reembolso:** exige `recibida`; marca `pagos.estado='reembolsado'`, `pedidos.estado='devuelto'` y registra evento.
- **Cambio:** `accion='cambio'` no reembolsa; si reingresa stock, queda disponible para el nuevo pedido.
- Toda acción sensible pasa por `auditar()` con antes/después.

## 5. Frontend

```
admin/js/vistas/
├── clientes.js      tabla + drawer 560 px
├── cliente.js       ficha 360
└── devoluciones.js  lista + drawer de RMA
```

- Drawer de cliente: datos editables, direcciones, notas, consentimiento, pedidos y devoluciones.
- Chips de segmento y filtro "sin compras en 90 días".
- Historial con enlace al pedido (abre el drawer de Fase 3).
- RMA: selección de ítems con cantidad máxima permitida, motivo, acción y casilla de reingreso.
- Timeline de la devolución y total reembolsado.
- Confirmación destructiva para rechazar o reembolsar.

## 6. Permisos

| Clave | Estado | Roles |
|---|---|---|
| `clientes.ver` | existe | admin, ventas, soporte |
| `clientes.editar` | **nueva** | admin, ventas |
| `devoluciones.ver` | existe | admin, ventas, soporte |
| `devoluciones.editar` | **nueva** | admin, soporte |

## 7. Plan de tareas

### Semana 1

- [ ] Migración de contadores y normalización de teléfonos/emails.
- [ ] `clientes`, `cliente_detalle`, `cliente_guardar`, direcciones.
- [ ] Segmentos y filtros.
- [ ] Tabla y drawer de cliente.

### Semana 2

- [ ] `devolucion_crear` con validación de cantidades.
- [ ] `devolucion_transicion` con reingreso de stock y reembolso.
- [ ] Fusión de duplicados con auditoría.
- [ ] Lista y drawer de RMA.
- [ ] Exportación CSV.

## 8. Criterios de aceptación

1. La ficha muestra pedidos, gasto total, última compra y devoluciones reales.
2. Un cliente no se duplica al comprar con el mismo email o teléfono.
3. No se puede devolver más cantidad de la comprada.
4. Recibir una devolución reingresa stock exactamente una vez.
5. El reembolso solo se permite después de recibir.
6. Los segmentos coinciden con los umbrales configurados.
7. El pedido queda en `devuelto` y el pago en `reembolsado`.
8. Todo cambio queda en `auditoria`.

## 9. Pruebas

| Caso | Esperado |
|---|---|
| Devolver 2 de 1 unidad | `422` |
| Recibir dos veces | Segundo intento `422`, stock intacto |
| Reembolsar sin recibir | `422` |
| Devolución sobre pedido `pendiente_pago` | `422` |
| Fusión de duplicados | Pedidos y direcciones migran, auditoría con ambos IDs |
| Segmento VIP | Coincide con pedidos/gasto |
| Rol bodega intenta reembolsar | `403` |
| Cliente con email en mayúsculas | Se reutiliza el existente |

## 10. Riesgos

| Riesgo | Mitigación |
|---|---|
| Inflar stock con devoluciones repetidas | Estado `recibida` validado con `FOR UPDATE` |
| Clientes duplicados históricos | Detección por email/teléfono + fusión auditada |
| Contadores desincronizados | Recalculo por migración y actualización al entregar |
| Reembolso sin mercancía | Bloqueo hasta estado `recibida` |
| Datos personales | Acceso por rol, sin exponer en logs |

## 11. Despliegue

1. Ejecutar `004_crm_rma.sql` con backup previo.
2. Subir API y vistas.
3. Verificar contadores en 5 clientes reales.
4. Probar una devolución completa en un pedido de prueba.

**Rollback:** los contadores se pueden recalcular; las devoluciones conservan historial y no se borran.

## 12. Definición de terminado

`php -l` limpio, flujo de devolución probado en móvil, backup restaurado y los 8 criterios de aceptación cumplidos.
