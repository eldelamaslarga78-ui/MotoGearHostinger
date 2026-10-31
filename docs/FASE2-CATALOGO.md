# Fase 2 · Catálogo

> **Duración:** 2–3 semanas · **Estado:** pendiente
> **Depende de:** Fase 1 (shell y cliente API)
> **Entregable verificable:** alta de un producto con 15 variantes en menos de 2 minutos, con imágenes optimizadas, libro de inventario y alertas de stock.
> **Documento base:** `docs/ARQUITECTURA-BACKOFFICE.md` § 1.3.1, § 1.3.2 y § 3.3.

---

## 1. Alcance

### Entra

- Listado de productos con búsqueda, filtros, orden y paginación en servidor.
- Detalle de producto con pestañas: General, Precios, Variantes, Inventario, Multimedia, Envío, SEO.
- Matriz talla × color con generación masiva de combinaciones y SKU automático.
- CRUD de variantes y activación/desactivación individual.
- Libro mayor de inventario visible y ajustes con motivo obligatorio.
- Multimedia: hasta 6 imágenes, 4 MB cada una, WebP en 3 tamaños y `alt` obligatorio.
- Alertas de stock bajo y acciones de reposición.
- Borrado lógico cuando el producto tiene pedidos.
- CRUD de categorías con jerarquía y orden.

### No entra

- Promociones y cupones avanzados (Fase 5).
- Importación/exportación CSV masiva (mejora posterior; el libro mayor ya la soporta con `ref_tipo='importacion'`).
- Multi-bodega (ruta de evolución).

## 2. Punto de partida

- `POST ?r=guardar` tipo `producto` crea producto + variantes en una sola operación.
- `POST ?r=borrar` tipo `producto` archiva si hay pedidos y borra si no.
- `POST ?r=upload` valida imagen (4 MB), la redimensiona a 1200 px y la guarda como un único WebP en `img/productos/`.
- `variantes`, `movimientos_stock` y `v_stock_bajo` existen desde la Fase 0/1.

## 3. Modelo de datos

La Fase 0 no creó `producto_imagenes`: la migración de esta fase la crea y luego migra la imagen principal.

```sql
-- sql/migraciones/002_catalogo.sql
-- 1. Tabla de multimedia (no existe en producción)
CREATE TABLE IF NOT EXISTS producto_imagenes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  producto_id INT UNSIGNED NOT NULL,
  ruta        VARCHAR(255) NOT NULL,
  alt         VARCHAR(160) NOT NULL DEFAULT '',
  orden       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  principal   TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_img_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  KEY idx_img_prod (producto_id, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Imagen principal actual -> producto_imagenes
INSERT INTO producto_imagenes (producto_id, ruta, alt, orden, principal)
SELECT p.id, p.img, p.nombre, 0, 1
FROM productos p
WHERE p.img <> '' AND NOT EXISTS (
  SELECT 1 FROM producto_imagenes i WHERE i.producto_id = p.id
);

-- 3. SKU faltantes en variantes
UPDATE variantes SET sku = CONCAT('MG-VAR-', id) WHERE sku = '' OR sku IS NULL;
```

| Tabla | Campos usados |
|---|---|
| `productos` | `sku`, `slug`, `precio`, `precio_antes`, `costo`, `iva_pct`, `peso_g`, dimensiones, `badge`, `destacado`, `activo`, SEO |
| `producto_imagenes` | `ruta`, `alt`, `orden`, `principal` |
| `variantes` | `sku`, `talla`, `color`, `color_hex`, `precio_extra`, `stock`, `stock_minimo`, `activo` |
| `movimientos_stock` | `tipo`, `cantidad`, `stock_despues`, `motivo`, `ref_tipo`, `usuario_id` |

**Regla intacta:** `variantes.stock` solo cambia dentro de una transacción que inserta su movimiento. Nunca un `UPDATE stock` suelto.

## 4. Backend

| Ruta | Método | Permiso | Función |
|---|---|---|---|
| `?r=productos` | GET | `productos.ver` | Lista paginada con filtros y orden |
| `?r=producto_detalle&id=` | GET | `productos.ver` | Producto + variantes + imágenes + resumen |
| `?r=producto_guardar` | POST | `productos.editar` | Alta/edición con validación y auditoría |
| `?r=producto_archivar` | POST | `productos.borrar` | Archiva o elimina según historial |
| `?r=variantes_generar` | POST | `productos.editar` | Crea la matriz talla × color |
| `?r=variante_guardar` | POST | `productos.editar` | Edita una variante (sin stock) |
| `?r=stock_ajustar` | POST | `inventario.ajustar` | Ajuste con motivo y movimiento |
| `?r=inventario` | GET | `productos.ver` | Libro mayor filtrable por variante |
| `?r=stock_alertas` | GET | `productos.ver` | Variantes bajo mínimo |
| `?r=imagen_subir` | POST | `productos.editar` | WebP en 3 tamaños |
| `?r=imagen_orden` | POST | `productos.editar` | Reordena y define principal |
| `?r=imagen_borrar` | POST | `productos.editar` | Borra archivo y fila |

### Reglas de negocio

- **SKU único** en producto y variante. Patrón de variante: `MG-<CAT3>-<PROD3>-<TALLA>-<COLOR3>`; si choca, sufijo numérico.
- **Slug** autogenerado y editable; único; sin acentos ni espacios.
- **Precios en enteros**; `precio_extra` puede ser negativo pero el total de la variante nunca menor a 0.
- **Stock inicial** de una variante nueva se registra como movimiento `entrada` con `ref_tipo='manual'`.
- **Ajuste de stock** exige `motivo` (mínimo 5 caracteres) y registra `stock_despues`; no admite stock resultante negativo.
- **Archivar** (`activo = 0`) desactiva también sus variantes. No se borra un producto con `pedido_items`.
- **Generar matriz** no duplica combinaciones existentes (`uq_var`); informa cuántas creó y cuántas omitió.
- **Imágenes:** máximo 6 por producto, `alt` obligatorio, primera = principal; se guardan en `img/productos/<producto_id>/` como `-400`, `-800` y `-1600.webp`.
- **Concurrencia:** ajustes de stock con `SELECT … FOR UPDATE` sobre la variante.

## 5. Frontend

```
admin/js/vistas/
├── productos.js        tabla, filtros, paginación
├── producto.js         ficha con 7 pestañas
└── ui/
    ├── matriz.js       editor talla × color
    └── dropzone.js     multimedia con reordenamiento
```

- Tabla densa: cabecera sticky, filas de 44 px, selección múltiple, menú `⋯`, chips de filtros y vistas guardadas.
- Ficha: 8 columnas centrales + rail derecho sticky con estado, resumen (stock total, variantes activas, margen, envío) y vista previa con el CSS de la tienda.
- Matriz: celdas de stock editables, hover con SKU, acciones "aplicar a todas", "copiar precio extra", "activar/desactivar".
- Inventario: tabla con tipo, cantidad, stock resultante, motivo, usuario y fecha; botón "Ajustar stock".
- Multimedia: drag & drop, reordenar, marcar principal, borrar con confirmación.
- SEO: contadores de 70/160 caracteres y vista previa del resultado de Google.
- Zona peligrosa: archivar/eliminar con confirmación escribiendo el nombre del producto.

## 6. Permisos

| Clave | Estado | Roles |
|---|---|---|
| `productos.ver` | existe | admin, ventas, bodega |
| `productos.editar` | existe | admin, bodega |
| `productos.borrar` | existe | admin |
| `categorias.editar` / `.borrar` | existe | admin |
| `inventario.ajustar` | **nueva** | admin, bodega |

## 7. Plan de tareas

### Semana 1

- [ ] Migración `002_catalogo.sql` y respaldo previo.
- [ ] Endpoints `productos`, `producto_detalle`, `producto_guardar`, `producto_archivar`.
- [ ] Validaciones de SKU, slug, precios y categoría.
- [ ] Tabla de productos con filtros y paginación.
- [ ] Ficha General + Precios + SEO.

### Semana 2

- [ ] `variantes_generar`, `variante_guardar` y matriz visual.
- [ ] `stock_ajustar`, `inventario` y `stock_alertas`.
- [ ] Multimedia: 3 tamaños, orden, principal, límites.
- [ ] Pestaña Envío y resumen del rail.
- [ ] Alertas de stock en dashboard (enlazar con Fase 1).

### Semana 3 (colchón)

- [ ] Pruebas de concurrencia y de archivo con historial.
- [ ] Ajuste de consultas y índices con catálogo real.
- [ ] Accesibilidad y móvil.

## 8. Criterios de aceptación

1. Crear un producto con 15 variantes (5 tallas × 3 colores) toma menos de 2 minutos.
2. El SKU de cada variante es único y se genera sin intervención manual.
3. El stock mostrado coincide siempre con la suma del libro mayor.
4. Un ajuste sin motivo es rechazado con `422`.
5. No se puede dejar stock negativo.
6. Un producto con pedidos se archiva, nunca se borra.
7. Subir 6 imágenes de 4 MB funciona; la séptima se rechaza.
8. La tabla responde en menos de 1 s con 1.000 productos.
9. Las alertas de stock bajo coinciden con `v_stock_bajo`.

## 9. Pruebas

| Caso | Esperado |
|---|---|
| SKU duplicado | `422` con mensaje claro |
| Slug duplicado | Se sugiere sufijo o `422` |
| Generar matriz dos veces | No duplica; informa omitidas |
| Ajuste `-999` sobre stock 3 | Rechazado |
| Ajuste válido | Movimiento + `stock_despues` correcto |
| Producto con pedidos → borrar | `activo=0` y variantes inactivas |
| Imagen 5 MB | `422` |
| Imagen sin `alt` | `422` |
| Archivo no imagen disfrazado | `422` |
| Rol `soporte` intenta editar | `403` |
| POST sin CSRF | Rechazado |

## 10. Riesgos

| Riesgo | Mitigación |
|---|---|
| GD sin memoria con fotos grandes | Límite 4 MB y redimensionado antes de generar tamaños |
| Ediciones simultáneas de stock | `FOR UPDATE` + libro mayor |
| Catálogo grande y filtros lentos | Índices existentes, paginación y `FULLTEXT` |
| Huérfanos de imagen al borrar | Borrar archivo y fila en la misma operación |
| Pérdida de la imagen antigua | `producto_imagenes` conserva `productos.img` durante la transición |

## 11. Despliegue

1. Backup de base de datos e `img/productos/`.
2. Ejecutar `sql/migraciones/002_catalogo.sql` en phpMyAdmin.
3. Subir `api/` y `admin/js/vistas/`.
4. Verificar 3 productos existentes: imágenes, variantes y stock.
5. Probar alta completa y ajuste de stock.

**Rollback:** las columnas nuevas son compatibles; `productos.img` sigue existiendo. Restaurar backup si la migración de imágenes falla.

## 12. Definición de terminado

`php -l` limpio, flujos probados en móvil, backup restaurado en entorno de prueba y los 9 criterios de aceptación cumplidos.
