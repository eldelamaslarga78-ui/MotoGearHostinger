# Fase 6 · Pagos y 2FA

> **Duración:** 2 semanas · **Estado:** pendiente
> **Depende de:** Fase 5 (marketing y checkout completo)
> **Entregable verificable:** un pago aprobado en sandbox confirma el pedido y emite la factura sin intervención humana; el rol admin exige 2FA.
> **Documento base:** `docs/ARQUITECTURA-BACKOFFICE.md` § 1.3.5, § 2.2 (paso 5) y § 4.4.

---

## 1. Alcance

### Entra

- Checkout hospedado con **Wompi** (recomendado para Colombia) o Mercado Pago.
- Creación de intención de pago y redirección al proveedor (el cliente nunca escribe la tarjeta en el sitio).
- Webhooks firmados e idempotentes para aprobado, rechazado y reembolsado.
- Conciliación automática de pagos pendientes por cron.
- 2FA TOTP para el rol admin, con códigos de recuperación.
- Cierre global de sesiones y expiración por inactividad.
- Auditoría completa de acciones sensibles con antes/después.

### No entra

- Facturación electrónica DIAN (proveedor externo, decisión posterior).
- Tokenización o cobro recurrente.
- Múltiples pasarelas activas a la vez (se implementa una; la interfaz queda preparada).

## 2. Punto de partida

- `pagos` la crea la migración `003` (Fase 3) con `pasarela`, `referencia_externa`, `estado`, `monto`, `payload` y la clave única `uq_pago_ref (pasarela, referencia_externa)`.
- `pedidos.pago_estado` y `metodo_pago` existen; el pago manual de Fase 3 sigue funcionando.
- `usuarios` ya tiene `totp_secreto`, `totp_activo`, `bloqueado_hasta` y `ultimo_acceso`.
- `auditoria` registra cambios con `cambios` en JSON.

## 3. Modelo de datos

```sql
-- sql/migraciones/006_pagos_2fa.sql
ALTER TABLE usuarios
  ADD COLUMN sesion_version INT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN totp_recovery JSON NULL,
  ADD COLUMN totp_ultimo_paso BIGINT UNSIGNED NULL;

INSERT INTO configuracion (clave, valor, tipo, grupo, es_publico) VALUES
  ('pagos_activos', '0', 'bool', 'pagos', 0),
  ('pago_expira_min', '30', 'numero', 'pagos', 0)
ON DUPLICATE KEY UPDATE clave = clave;
```

Las claves del proveedor (`WOMPI_PUBLIC_KEY`, `WOMPI_PRIVATE_KEY`, `WOMPI_EVENTS_SECRET`) van en `config.local.php`, nunca en la base de datos ni en Git.

| Tabla | Uso |
|---|---|
| `pagos` | Intención, estado y payload del proveedor |
| `pedidos` | `pago_estado`, `estado`, `metodo_pago` |
| `usuarios` | 2FA, versión de sesión y bloqueo |
| `auditoria` | Trazabilidad de pagos y seguridad |
| `notificaciones` | Aviso de pago aprobado o rechazado |

## 4. Backend

| Ruta | Método | Permiso | Función |
|---|---|---|---|
| `?r=pago_crear` | POST | público | Crea pago y devuelve URL hospedada |
| `?r=pago_estado&ref=` | GET | público con token | Estado para la pantalla de gracias |
| `?r=webhook_wompi` | POST | firma del proveedor | Confirma, rechaza o reembolsa |
| `?r=webhook_mercadopago` | POST | firma del proveedor | Equivalente para Mercado Pago |
| `?r=2fa_estado` | GET | sesión | Si el usuario tiene 2FA activo |
| `?r=2fa_activar` | POST | sesión + clave | Genera secreto y URI `otpauth://` |
| `?r=2fa_confirmar` | POST | sesión + código | Activa y entrega códigos de recuperación |
| `?r=2fa_desactivar` | POST | sesión + clave + código | Desactiva |
| `?r=sesiones_cerrar` | POST | sesión | Incrementa `sesion_version` |
| `?r=auditoria` | GET | `auditoria.ver` | Consulta filtrable |
| `cron/cron.php` | CLI | token | Conciliación y limpieza |

### Reglas de pago

- **Nunca** se reciben datos de tarjeta; solo se usa checkout hospedado.
- `pago_crear` valida pedido, monto y estado (`pendiente_pago`), y crea la fila en `pagos` con `referencia_externa`.
- **Firma:** se verifica con comparación en tiempo constante; firma inválida → `401` y registro en `auditoria`.
- **Idempotencia:** `uq_pago_ref` evita duplicados; un webhook repetido responde `200` sin efectos nuevos.
- **Monto:** debe coincidir con `pedidos.total`; si no, se rechaza y se alerta.
- **Transición:** pago `aprobado` → `pago_estado='pagado'`, `estado='confirmado'`, factura (Fase 3), evento y notificación. `rechazado` deja el pedido en `pendiente_pago` hasta expirar.
- **Webhook rápido:** responde `200` en menos de 2 s; el procesamiento pesado va después dentro de la misma transacción acotada.
- **Conciliación:** el cron consulta pagos `creado` con más de 30 min y actualiza desde la API del proveedor.
- **Secretos:** solo en `config.local.php`; rotación documentada.

### Reglas de seguridad

- **TOTP:** SHA-1, 30 s, 6 dígitos, ventana ±1; secreto cifrado con AES-256-GCM y clave en `config.local.php`.
- **Anti-replay:** se guarda `totp_ultimo_paso`; un código ya usado no se acepta.
- **Recuperación:** 8 códigos de un solo uso, guardados como hash; se muestran una sola vez.
- **Bloqueo:** 5 intentos fallidos → 15 min; se registra usuario e IP.
- **Sesión:** `session_regenerate_id` al entrar; inactividad 30 min; `sesion_version` invalida todas las sesiones al cerrar globalmente.
- **Auditoría:** toda mutación sensible registra usuario, IP, entidad y `cambios` antes/después; el registro no se edita desde la UI.

## 5. Frontend

```
admin/js/vistas/
├── cuenta.js       2FA, códigos de recuperación y sesiones
├── auditoria.js    tabla filtrable
└── pago.js         estado del pago y reintento
```

- Pantalla de pago: resumen, botón "Pagar en línea", estado (pendiente/aprobado/rechazado) y reintento.
- Activación 2FA: secreto, URI `otpauth://` y campo de verificación; sin librerías externas.
- Códigos de recuperación con botón de copia y aviso de "no volverás a verlos".
- Auditoría: filtros por usuario, acción, entidad y fecha; detalle con diff legible.
- Botón "Cerrar todas las sesiones" con confirmación.

## 6. Permisos

| Clave | Estado | Roles |
|---|---|---|
| `pagos.ver` | **nueva** | admin, ventas |
| `pagos.reembolsar` | **nueva** | admin |
| `usuarios.gestionar` | existe | admin |
| `auditoria.ver` | existe | admin |
| `config.editar` | existe | admin |

## 7. Plan de tareas

### Semana 1

- [ ] `pago_crear` y pantalla de pago en sandbox.
- [ ] Webhook con verificación de firma e idempotencia.
- [ ] Transición de pago a pedido confirmado + factura.
- [ ] Conciliación por cron.
- [ ] Pruebas de replay, firma inválida y monto alterado.

### Semana 2

- [ ] 2FA: alta, confirmación, recuperación y baja.
- [ ] Bloqueo por intentos y anti-replay.
- [ ] `sesion_version` y cierre global.
- [ ] Vista de auditoría y filtros.
- [ ] Pruebas de seguridad y accesibilidad.

## 8. Criterios de aceptación

1. Un pago aprobado en sandbox confirma el pedido y emite la factura sin intervención humana.
2. Un webhook repetido no duplica pagos ni facturas.
3. Una firma inválida se rechaza con `401`.
4. Un monto distinto al del pedido se rechaza y genera alerta.
5. El rol admin no puede entrar sin 2FA una vez activado.
6. Un código TOTP usado no se acepta dos veces.
7. "Cerrar todas las sesiones" invalida las demás de inmediato.
8. La auditoría muestra usuario, IP, acción y cambios antes/después.
9. Ninguna clave del proveedor aparece en el repositorio ni en la base de datos.

## 9. Pruebas

| Caso | Esperado |
|---|---|
| Pago aprobado (sandbox) | Pedido `confirmado`, factura emitida |
| Pago rechazado | Pedido sigue `pendiente_pago` |
| Webhook duplicado | `200`, sin efectos nuevos |
| Firma inválida | `401` |
| Monto alterado | Rechazo + alerta |
| Webhook de referencia desconocida | `404` registrado |
| Código 2FA incorrecto ×5 | Bloqueo 15 min |
| Código 2FA reutilizado | Rechazado |
| Sesión inactiva 31 min | Expirada |
| Cierre global | Sesiones previas invalidadas |

## 10. Riesgos

| Riesgo | Mitigación |
|---|---|
| Fuga de claves del proveedor | `config.local.php` fuera del sitio + rotación |
| Webhook repetido o falsificado | Firma + `uq_pago_ref` + verificación de monto |
| Proveedor caído | Reintento, conciliación por cron y pago manual de respaldo |
| Reloj del servidor desfasado | Sincronización NTP del hosting y ventana ±1 |
| Bloqueo por 2FA perdido | Códigos de recuperación de un solo uso |
| Alcance PCI | Checkout hospedado; nunca se tocan datos de tarjeta |

## 11. Despliegue

1. Ejecutar `006_pagos_2fa.sql`.
2. Añadir claves del proveedor a `config.local.php`.
3. Configurar la URL de webhook en el panel del proveedor.
4. Probar en sandbox con tarjetas de prueba.
5. Activar `pagos_activos = 1` solo tras validar conciliación.
6. Programar el cron de conciliación cada 15 min.

**Rollback:** `pagos_activos = 0` vuelve al pago manual de Fase 3 sin tocar pedidos existentes.

## 12. Definición de terminado

`php -l` limpio, pago sandbox aprobado de punta a punta, 2FA probado, backup restaurado y los 9 criterios de aceptación cumplidos.
