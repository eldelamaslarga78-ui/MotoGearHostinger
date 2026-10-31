# Fase 5 · Marketing

> **Duración:** 2 semanas · **Estado:** pendiente
> **Depende de:** Fase 4 (clientes y segmentos)
> **Entregable verificable:** promociones 2x1/volumen aplicadas en servidor, cupones con límites reales y recuperación automática de carritos medida.
> **Documento base:** `docs/ARQUITECTURA-BACKOFFICE.md` § 1.3.8 y § 2.2 (pasos 1 y 6).

---

## 1. Alcance

### Entra

- Motor de promociones declarativas: `nxm`, `volumen`, `combo`, `descuento_categoria`.
- Prioridad, vigencia y exclusividad entre promociones y cupones.
- Cupones avanzados: porcentaje, monto fijo y envío gratis; mínimo, vigencia, tope de usos, máximo por cliente, solo primera compra y acumulable.
- Registro de usos en `cupon_usos` (una fila por pedido).
- Carritos persistentes desde el primer paso del checkout.
- Detección de abandono a las 2 horas y correo de recuperación a las 24 horas.
- Enlace de recuperación con token y medición de conversión.
- Panel de resultados: carritos abandonados, recuperados e ingresos recuperados.

### No entra

- Campañas masivas a segmentos (se puede añadir después con el mismo SMTP).
- Programa de referidos.
- Cupones de un solo uso por código único generado en lote (mejora posterior).

## 2. Punto de partida

- `POST ?r=cupon` valida un cupón básico en el checkout.
- Cupones ya tienen `tipo`, `valor`, `minimo`, `desde`, `vence`, `usos_max`, `max_por_cliente`, `primera_compra`, `acumulable`.
- Tablas `promociones`, `promocion_items`, `carritos`, `carrito_items`, `cupon_usos` ya existen.
- El pedido se recalcula en servidor; el cliente nunca fija precios.

## 3. Modelo de datos

Sin tablas nuevas. Ajuste de configuración:

```sql
-- sql/migraciones/005_marketing.sql
INSERT INTO configuracion (clave, valor, tipo, grupo, es_publico) VALUES
  ('carrito_abandono_horas', '2',  'numero', 'marketing', 0),
  ('carrito_recordatorio_horas', '24', 'numero', 'marketing', 0),
  ('smtp_host', '', 'texto', 'correo', 0),
  ('smtp_puerto', '587', 'numero', 'correo', 0),
  ('smtp_usuario', '', 'texto', 'correo', 0),
  ('smtp_remitente', '', 'texto', 'correo', 0)
ON DUPLICATE KEY UPDATE clave = clave;
```

Las credenciales SMTP **no** van en `configuracion`: van en `config.local.php`.

| Tabla | Uso |
|---|---|
| `promociones`, `promocion_items` | Reglas y alcance |
| `cupones`, `cupon_usos` | Validación y registro de uso |
| `carritos`, `carrito_items` | Persistencia y abandono |
| `pedidos`, `pedido_items` | Conversión y descuentos por línea |
| `clientes` | Segmento y consentimiento |

## 4. Backend

| Ruta | Método | Permiso | Función |
|---|---|---|---|
| `?r=carrito` | POST | público | Crea/actualiza carrito por token |
| `?r=cupon_validar` | POST | público | Valida cupón y devuelve descuento |
| `?r=promociones` | GET | `promociones.ver` | Lista con vigencia y prioridad |
| `?r=promocion_guardar` | POST | `promociones.editar` | Alta/edición de regla |
| `?r=promocion_borrar` | POST | `promociones.borrar` | Desactiva o borra |
| `?r=carritos` | GET | `carritos.ver` | Abandonados con valor y antigüedad |
| `?r=carrito_recuperar` | POST | `carritos.ver` | Envía correo de recuperación |
| `?r=marketing_resumen` | GET | `dashboard.ver` | Recuperados e ingresos |
| `cron/cron.php` | CLI | token | Marca abandonos y envía recordatorios |

### Reglas de negocio

- **Evaluación en servidor** al crear el pedido; el navegador solo muestra una estimación.
- **Orden de cálculo:** promociones (por `prioridad` ascendente) → cupón → envío → total. Nunca negativo.
- **Exclusividad:** si una promoción tiene `exclusiva = 1`, el cupón no se aplica y se informa por qué.
- **`nxm`:** lleva N, paga M (`{"lleva":2,"paga":1}`); se aplica a unidades del mismo producto o categoría según `promocion_items`.
- **`volumen`:** desde N unidades, porcentaje sobre las líneas elegibles.
- **`combo`:** requiere al menos un ítem de cada producto/categoría listada.
- **`descuento_categoria`:** porcentaje sobre categorías seleccionadas.
- **Cupones:** `primera_compra` exige cero pedidos entregados; `max_por_cliente` cuenta `cupon_usos`; `usos_max` se valida dentro de la transacción.
- **Carrito:** token UUID en `localStorage`; se crea al capturar email en el paso 1; 2 h sin actividad → `abandonado`.
- **Recordatorio:** a las 24 h, máximo uno; `recordatorios = 1`; enlace con token; rebote o baja de marketing lo cancela.
- **Conversión:** si se crea un pedido con el mismo `token`, el carrito pasa a `convertido` y se enlaza `pedido_id`.
- **Consentimiento:** solo se envía correo a quien tenga `acepta_marketing = 1` o haya iniciado el checkout (base legítima), con enlace de baja.
- **Duplicados:** un carrito por token; `uq_ci (carrito_id, variante_id)` suma cantidades.

## 5. Frontend

```
admin/js/vistas/
├── promociones.js   lista + constructor de reglas
├── cupones.js       CRUD y métricas de uso
└── carritos.js      abandonados y recuperación
```

- Constructor de promoción: tipo, vigencia, prioridad, productos/categorías y beneficio, con vista previa del descuento sobre un ejemplo.
- Cupones: código, tipo, valor, mínimo, topes, primera compra, acumulable y barra de uso.
- Carritos: email, valor, horas de inactividad, ítems y botón "Enviar recuperación".
- Resumen: abandonados, recuperados, tasa e ingresos recuperados.
- Todo con estados de carga/vacío/error y permisos por botón.

## 6. Permisos

| Clave | Estado | Roles |
|---|---|---|
| `cupones.ver` / `.editar` / `.borrar` | existe | admin, ventas |
| `promociones.ver` | **nueva** | admin, ventas |
| `promociones.editar` | **nueva** | admin, ventas |
| `promociones.borrar` | **nueva** | admin |
| `carritos.ver` | **nueva** | admin, ventas |

## 7. Plan de tareas

### Semana 1

- [ ] Motor de promociones con prioridad y exclusividad.
- [ ] `cupon_validar` con todos los límites y `cupon_usos`.
- [ ] CRUD de promociones y cupones.
- [ ] Pruebas de cálculo con combinaciones.

### Semana 2

- [ ] `carrito` público y persistencia por token.
- [ ] Cron de abandono y recordatorio.
- [ ] SMTP con SPF/DKIM y plantilla de correo.
- [ ] Panel de carritos y métricas de recuperación.
- [ ] Pruebas de envío real y de baja.

## 8. Criterios de aceptación

1. Un 2x1 descuenta exactamente una unidad y queda reflejado en `pedido_items.descuento`.
2. Una promoción exclusiva impide el cupón e informa el motivo.
3. Un cupón agotado o vencido se rechaza con `422`.
4. `max_por_cliente` y `primera_compra` se respetan.
5. Un carrito inactivo 2 h pasa a `abandonado`.
6. El correo de recuperación se envía una sola vez y llega a la bandeja de entrada.
7. Un pedido con el token del carrito lo marca `convertido`.
8. El panel muestra recuperados e ingresos recuperados reales.

## 9. Pruebas

| Caso | Esperado |
|---|---|
| 2x1 con 3 unidades | Paga 2 |
| Cupón sobre promoción exclusiva | Rechazado con motivo |
| Cupón vencido | `422` |
| Cupón con tope alcanzado | `422` |
| `primera_compra` con historial | `422` |
| Carrito sin email | No se marca abandonado |
| Recordatorio duplicado | No se reenvía |
| Token de otro cliente | `403` |
| Envío sin consentimiento | Se bloquea salvo checkout iniciado |

## 10. Riesgos

| Riesgo | Mitigación |
|---|---|
| Abuso de descuentos | Todo se recalcula en servidor y se audita |
| Correos a spam | SMTP autenticado, SPF/DKIM y baja visible |
| Carritos basura | Rate limit por IP y token |
| Promociones contradictorias | Prioridad + exclusividad + simulación previa |
| Costo de evaluar reglas | Pocas reglas activas, evaluación en memoria por pedido |

## 11. Despliegue

1. Ejecutar `005_marketing.sql`.
2. Configurar SMTP en `config.local.php` y verificar SPF/DKIM.
3. Subir API, cron y vistas.
4. Probar un correo real a una cuenta propia.
5. Activar el cron cada 10 min.

**Rollback:** desactivar promociones y el cron; los carritos no afectan pedidos existentes.

## 12. Definición de terminado

`php -l` limpio, correo real recibido, flujos probados en móvil, backup restaurado y los 8 criterios de aceptación cumplidos.
