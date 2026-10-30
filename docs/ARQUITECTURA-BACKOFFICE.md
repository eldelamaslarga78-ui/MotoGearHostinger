# Arquitectura del Backoffice — MotoGear

> Documento técnico de diseño. Versión 1.0.
> Restricciones del proyecto: **HTML + CSS + PHP 8 nativo + JavaScript vanilla**, MySQL/MariaDB administrado con phpMyAdmin, hosting compartido **Hostinger Single**, sin Composer obligatorio, sin Node en producción, sin frameworks.

---

## 0. Contexto, restricciones y principios

### 0.1 Lo que el hosting impone

| Restricción | Consecuencia de diseño |
|---|---|
| Hosting compartido (Apache + PHP-FPM, sin procesos persistentes) | Nada de WebSockets, colas reales ni workers. Todo se resuelve con **peticiones HTTP + cron de hPanel**. |
| Sin Redis/Memcached | La caché vive en **MySQL** (tablas `cache_kv`, `kpi_snapshots`) o en archivos. |
| Sin Node en producción | El JS se sirve tal cual, sin build. Nada de bundlers ni transpilación. |
| Un solo sitio / recursos de CPU e I/O limitados | Consultas acotadas, índices desde el día uno, paginación **siempre en servidor**. |
| MySQL vía phpMyAdmin (sin migraciones automáticas) | El esquema se versiona como **`sql/schema.sql` + `sql/migraciones/NNN_*.sql`** aplicados a mano, con número de versión en `configuracion`. |
| Backups por hPanel | Export SQL semanal + copia de `img/` fuera del hosting. |

### 0.2 Principios rectores

1. **El servidor manda.** Precios, descuentos, impuestos, envío y stock se calculan y validan en PHP. El navegador solo *propone*.
2. **Snapshot inmutable.** Un pedido guarda copia del nombre, precio, SKU, talla y dirección del momento de la compra. Si mañana cambia el producto, la factura no cambia.
3. **Libro mayor de inventario.** El stock nunca se "edita a mano" sin dejar rastro: cada cambio es una fila en `movimientos_stock`. El stock actual es una columna cacheada que se puede reconstruir.
4. **Idempotencia.** Crear pedido, cobrar y generar guía deben poder repetirse sin duplicar (doble clic, reintento, webhook repetido).
5. **Permisos en el servidor, no en la interfaz.** Ocultar un botón es UX; el `403` es la seguridad.
6. **Borrado lógico.** `activo = 0` antes que `DELETE`. Un producto con pedidos no se borra nunca.
7. **Una sola fuente de verdad por dato.** Nada de precios duplicados en `datos.js`, HTML estático o `localStorage`.
8. **Sin dependencias que no puedas leer.** Si una librería no cabe en 200 líneas y no la entiendes, no entra.

### 0.3 Estado actual → destino

El esquema actual (`productos` + `producto_tallas`, `pedidos` + `pedido_items`, `admins`, `cupones`) cubre el 30 % del backoffice objetivo. La migración clave es **`producto_tallas` → `variantes`** (talla + color + SKU + stock mínimo). El resto son tablas nuevas que se añaden sin romper lo existente.

---

## 1. ARQUITECTURA DE DATOS

### 1.1 Mapa relacional

```
                          ┌─────────────┐
                          │  categorias │
                          └──────┬──────┘
                                 │ 1:N
┌──────────────┐  1:N   ┌────────┴────────┐  1:N   ┌──────────────────┐
│  proveedores │────────│    productos    │────────│ producto_imagenes│
└──────────────┘        └────────┬────────┘        └──────────────────┘
                                 │ 1:N
                          ┌──────┴──────┐  1:N   ┌────────────────────┐
                          │  variantes  │────────│ movimientos_stock  │
                          └──────┬──────┘        └────────────────────┘
                                 │ 1:N
                          ┌──────┴──────┐
                          │pedido_items │
                          └──────┬──────┘
                                 │ N:1
┌──────────┐  1:N   ┌────────────┴───┐  1:N   ┌──────────────┐
│ clientes │────────│    pedidos     │────────│ pedido_eventos│
└────┬─────┘        └───┬───┬───┬────┘        └──────────────┘
     │ 1:N              │   │   │
┌────┴──────────┐       │   │   └────────────┐
│cliente_direcc.│       │   │                │
└───────────────┘       │   │        ┌───────┴──────┐    ┌──────────────┐
                        │   │        │   facturas   │    │ devoluciones │
                        │   │        └──────────────┘    └──────┬───────┘
                        │   │                                   │
                   ┌────┴───┴──┐   ┌──────────┐   ┌─────────────┴──┐
                   │   pagos   │   │  envios  │   │devolucion_items│
                   └───────────┘   └────┬─────┘   └────────────────┘
                                        │
                              ┌─────────┴────────┐
                              │ transportadoras  │
                              └──────────────────┘

  SEGURIDAD                          MARKETING                      SISTEMA
┌──────────┐   N:M   ┌────────────┐   ┌─────────┐  ┌──────────────┐  ┌───────────────┐
│ usuarios │─────────│rol_permisos│   │ cupones │  │ promociones  │  │ configuracion │
└────┬─────┘         └─────┬──────┘   └─────────┘  └──────┬───────┘  └───────────────┘
     │ N:1                 │ N:1                          │ N:M
┌────┴─────┐        ┌──────┴──────┐              ┌────────┴────────┐
│  roles   │        │  permisos   │              │promocion_items  │
└──────────┘        └─────────────┘              └─────────────────┘
     │
     └──── 1:N ──► auditoria        ┌──────────┐ 1:N ┌───────────────┐
                                    │ carritos │─────│carrito_items  │
                                    └──────────┘     └───────────────┘
```

### 1.2 Convenciones (obligatorias en todo el esquema)

- **Nombres en español**, `snake_case`, tablas en plural: `productos`, `pedido_items`.
- **InnoDB** + `utf8mb4_unicode_ci` siempre. Sin MyISAM.
- **Dinero en `INT`** (pesos colombianos sin decimales). Porcentajes e impuestos en `DECIMAL(5,2)`.
- **Fechas `DATETIME`** en UTC (o `America/Bogota` fija y documentada; nunca mezclar). `TIMESTAMP` solo si necesitas conversión automática.
- **PK `INT UNSIGNED AUTO_INCREMENT`**; `BIGINT` para tablas de alto volumen (`movimientos_stock`, `visitas`, `auditoria`).
- **FK explícitas con `ON DELETE RESTRICT`** en todo lo que tenga valor histórico (`pedido_items`). `CASCADE` solo en hijos puramente dependientes (`producto_imagenes`).
- **`creado` / `actualizado`** en toda tabla de negocio.
- **Nada de `SELECT *` en el código** (evita romper al añadir columnas y facilita índices).

### 1.3 DDL por dominio

> El DDL completo, listo para importar en phpMyAdmin, está en **`sql/schema.sql`** (36 objetos, importado y probado en MariaDB 10.4: alta de producto con variantes, pedido, envío, factura, pago, cupón, RBAC, bloqueo de borrado con historial y rechazo de pedido duplicado).

#### 1.3.1 Catálogo

```sql
CREATE TABLE categorias (
  id           VARCHAR(40)  NOT NULL PRIMARY KEY,
  nombre       VARCHAR(60)  NOT NULL,
  padre_id     VARCHAR(40)  NULL,
  orden        SMALLINT     NOT NULL DEFAULT 0,
  icono        VARCHAR(60)  NULL,
  activo       TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_cat_padre FOREIGN KEY (padre_id) REFERENCES categorias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE productos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sku            VARCHAR(40)  NOT NULL UNIQUE,       -- código interno único
  nombre         VARCHAR(120) NOT NULL,
  slug           VARCHAR(140) NOT NULL UNIQUE,       -- /productos/casco-integral-pro
  categoria_id   VARCHAR(40)  NOT NULL,
  marca          VARCHAR(60)  NOT NULL DEFAULT '',
  descr          VARCHAR(255) NOT NULL DEFAULT '',   -- descripción corta (tarjeta)
  descr_larga    TEXT         NULL,                  -- ficha del producto
  precio         INT UNSIGNED NOT NULL,              -- precio de venta actual (COP)
  precio_antes   INT UNSIGNED NULL,                  -- precio tachado (si hay oferta)
  costo          INT UNSIGNED NULL,                  -- para calcular margen
  iva_pct        DECIMAL(5,2) NOT NULL DEFAULT 19.00,
  peso_g         INT UNSIGNED NOT NULL DEFAULT 0,
  alto_mm        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ancho_mm       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  largo_mm       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  badge          VARCHAR(20)  NOT NULL DEFAULT '',
  badge_tipo     ENUM('','oferta') NOT NULL DEFAULT '',
  destacado      TINYINT(1)   NOT NULL DEFAULT 0,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,    -- borrado lógico
  meta_titulo    VARCHAR(70)  NULL,
  meta_desc      VARCHAR(160) NULL,
  creado         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_cat FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE RESTRICT,
  KEY idx_prod_cat (categoria_id, activo),
  KEY idx_prod_dest (destacado, activo),
  FULLTEXT KEY ft_prod (nombre, marca, descr)        -- búsqueda del panel
) ENGINE=InnoDB;

CREATE TABLE producto_imagenes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  producto_id INT UNSIGNED NOT NULL,
  ruta        VARCHAR(255) NOT NULL,                 -- ./img/productos/123/a1b2c3-800.webp
  alt         VARCHAR(160) NOT NULL DEFAULT '',      -- obligatorio en UI (accesibilidad/SEO)
  orden       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  principal   TINYINT(1)   NOT NULL DEFAULT 0,
  CONSTRAINT fk_img_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  KEY idx_img_prod (producto_id, orden)
) ENGINE=InnoDB;

-- Las tallas pasan a ser VARIANTES: talla + color + SKU propio + stock mínimo.
CREATE TABLE variantes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  producto_id   INT UNSIGNED NOT NULL,
  sku           VARCHAR(50)  NOT NULL UNIQUE,        -- MG-CAS-PRO-M-NEG
  talla         VARCHAR(10)  NOT NULL DEFAULT '',    -- '' = producto sin tallas
  color         VARCHAR(30)  NOT NULL DEFAULT '',
  color_hex     CHAR(7)      NULL,
  precio_extra  INT          NOT NULL DEFAULT 0,     -- +/- sobre productos.precio
  stock         INT          NOT NULL DEFAULT 0,     -- caché del libro mayor
  stock_minimo  INT UNSIGNED NOT NULL DEFAULT 3,     -- umbral de alerta
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_var (producto_id, talla, color),
  CONSTRAINT fk_var_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

> **Compatibilidad con el código actual:** `variantes(talla='', color='')` equivale exactamente a la fila `producto_tallas(talla='')` de hoy. La migración es copiar filas y añadir `sku`, `stock_minimo`, `color=''`.

#### 1.3.2 Inventario (libro mayor)

```sql
CREATE TABLE movimientos_stock (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  variante_id   INT UNSIGNED NOT NULL,
  tipo          ENUM('entrada','salida','ajuste','devolucion','perdida') NOT NULL,
  cantidad      INT NOT NULL,                        -- firmado: +10 entrada, -1 venta
  stock_despues INT NOT NULL,                        -- foto del stock tras el movimiento
  motivo        VARCHAR(120) NOT NULL DEFAULT '',
  ref_tipo      ENUM('pedido','devolucion','cancelacion','manual','importacion') NOT NULL,
  ref_id        BIGINT UNSIGNED NULL,
  usuario_id    INT UNSIGNED NULL,
  creado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mov_var FOREIGN KEY (variante_id) REFERENCES variantes(id) ON DELETE RESTRICT,
  KEY idx_mov_var (variante_id, creado),
  KEY idx_mov_ref (ref_tipo, ref_id)
) ENGINE=InnoDB;
```

**Regla:** `variantes.stock` solo se modifica dentro de una transacción que también inserta su movimiento. Un `UPDATE variantes SET stock=...` suelto es un bug.

```sql
-- Vista de alertas (la usa el dashboard y el cron)
CREATE VIEW v_stock_bajo AS
SELECT p.id AS producto_id, p.nombre, v.id AS variante_id, v.sku, v.talla, v.color,
       v.stock, v.stock_minimo
FROM variantes v
JOIN productos p ON p.id = v.producto_id
WHERE p.activo = 1 AND v.activo = 1 AND v.stock <= v.stock_minimo;
```

#### 1.3.3 Clientes (CRM)

```sql
CREATE TABLE clientes (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre           VARCHAR(100) NOT NULL,
  email            VARCHAR(120) NULL,
  telefono         VARCHAR(20)  NULL,
  tipo_doc         ENUM('','CC','CE','NIT','PP') NOT NULL DEFAULT '',
  documento        VARCHAR(20)  NULL,
  notas            TEXT NULL,
  acepta_marketing TINYINT(1) NOT NULL DEFAULT 0,
  total_pedidos    INT UNSIGNED NOT NULL DEFAULT 0,   -- desnormalizado (cron/trigger)
  total_gastado    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  primera_compra   DATETIME NULL,
  ultima_compra    DATETIME NULL,
  creado           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cli_email (email),
  UNIQUE KEY uq_cli_tel (telefono),
  KEY idx_cli_compra (ultima_compra)
) ENGINE=InnoDB;

CREATE TABLE cliente_direcciones (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id    INT UNSIGNED NOT NULL,
  alias         VARCHAR(40) NOT NULL DEFAULT 'Casa',
  destinatario  VARCHAR(100) NOT NULL,
  telefono      VARCHAR(20)  NOT NULL,
  departamento  VARCHAR(40)  NOT NULL,
  ciudad        VARCHAR(60)  NOT NULL,
  direccion     VARCHAR(200) NOT NULL,
  indicaciones  VARCHAR(200) NOT NULL DEFAULT '',
  principal     TINYINT(1)   NOT NULL DEFAULT 0,
  CONSTRAINT fk_dir_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  KEY idx_dir_cli (cliente_id)
) ENGINE=InnoDB;
```

**Identificación sin registro:** al crear un pedido, el servidor busca cliente por `email` o `telefono`; si no existe, lo crea. Así el CRM se llena solo, sin obligar al cliente a registrarse (menos fricción = más conversión).

#### 1.3.4 Pedidos

```sql
CREATE TABLE pedidos (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo           VARCHAR(14)  NOT NULL UNIQUE,     -- MG-000123 (visible al cliente)
  idempotencia     CHAR(36)     NULL UNIQUE,          -- UUID del checkout: evita duplicados
  cliente_id       INT UNSIGNED NULL,
  -- SNAPSHOT del comprador y del envío (inmutable)
  cliente_nombre   VARCHAR(100) NOT NULL,
  cliente_email    VARCHAR(120) NOT NULL DEFAULT '',
  cliente_tel      VARCHAR(20)  NOT NULL,
  envio_departamento VARCHAR(40) NOT NULL DEFAULT '',
  envio_ciudad     VARCHAR(60)  NOT NULL,
  envio_direccion  VARCHAR(200) NOT NULL,
  envio_indicaciones VARCHAR(200) NOT NULL DEFAULT '',
  -- Dinero
  subtotal         INT UNSIGNED NOT NULL,
  descuento        INT UNSIGNED NOT NULL DEFAULT 0,
  costo_envio      INT UNSIGNED NOT NULL DEFAULT 0,
  impuestos        INT UNSIGNED NOT NULL DEFAULT 0,
  total            INT UNSIGNED NOT NULL,
  cupon_codigo     VARCHAR(30)  NULL,
  -- Estado
  estado           ENUM('pendiente_pago','confirmado','en_preparacion','enviado','entregado','cancelado','devuelto')
                   NOT NULL DEFAULT 'pendiente_pago',
  pago_estado      ENUM('pendiente','pagado','rechazado','reembolsado') NOT NULL DEFAULT 'pendiente',
  metodo_pago      ENUM('contraentrega','transferencia','nequi','wompi','mercadopago') NOT NULL DEFAULT 'contraentrega',
  expira_pago      DATETIME NULL,                     -- cron cancela si no paga
  notas_cliente    VARCHAR(300) NOT NULL DEFAULT '',
  notas_internas   TEXT NULL,                         -- solo staff
  ip               VARCHAR(45) NOT NULL DEFAULT '',
  user_agent       VARCHAR(255) NOT NULL DEFAULT '',
  creado           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  entregado_en     DATETIME NULL,
  CONSTRAINT fk_ped_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
  KEY idx_ped_estado (estado, creado),
  KEY idx_ped_pago (pago_estado, expira_pago),
  KEY idx_ped_cli (cliente_id, creado),
  KEY idx_ped_fecha (creado)
) ENGINE=InnoDB;

CREATE TABLE pedido_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pedido_id     INT UNSIGNED NOT NULL,
  variante_id   INT UNSIGNED NULL,                    -- NULL si el producto se borró
  producto_id   INT UNSIGNED NULL,
  nombre        VARCHAR(120) NOT NULL,                -- snapshot
  sku           VARCHAR(50)  NOT NULL DEFAULT '',     -- snapshot
  talla         VARCHAR(10)  NOT NULL DEFAULT '',
  color         VARCHAR(30)  NOT NULL DEFAULT '',
  precio        INT UNSIGNED NOT NULL,                -- snapshot del precio unitario
  costo         INT UNSIGNED NOT NULL DEFAULT 0,      -- snapshot del costo (margen histórico)
  cantidad      SMALLINT UNSIGNED NOT NULL,
  descuento     INT UNSIGNED NOT NULL DEFAULT 0,      -- descuento de línea (promos 2x1)
  total_linea   INT UNSIGNED NOT NULL,
  CONSTRAINT fk_item_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_var FOREIGN KEY (variante_id) REFERENCES variantes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_item_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE RESTRICT,
  KEY idx_item_ped (pedido_id),
  KEY idx_item_prod (producto_id)
) ENGINE=InnoDB;

CREATE TABLE pedido_eventos (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pedido_id      INT UNSIGNED NOT NULL,
  estado_anterior VARCHAR(20) NULL,
  estado_nuevo   VARCHAR(20) NOT NULL,
  nota           VARCHAR(300) NOT NULL DEFAULT '',
  usuario_id     INT UNSIGNED NULL,                  -- NULL = sistema/cron/webhook
  creado         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ev_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  KEY idx_ev_ped (pedido_id, creado)
) ENGINE=InnoDB;
```

#### 1.3.5 Pagos

```sql
CREATE TABLE pagos (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pedido_id          INT UNSIGNED NOT NULL,
  pasarela           ENUM('manual','transferencia','nequi','wompi','mercadopago','contraentrega') NOT NULL,
  referencia_externa VARCHAR(100) NULL,              -- id de la pasarela
  estado             ENUM('creado','aprobado','rechazado','reembolsado','error') NOT NULL DEFAULT 'creado',
  monto              INT UNSIGNED NOT NULL,
  payload            JSON NULL,                       -- respuesta cruda (auditoría)
  creado             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pago_ref (pasarela, referencia_externa),  -- ← idempotencia de webhooks
  CONSTRAINT fk_pago_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

> **Credenciales de pasarela nunca en la base de datos.** Van en `config.local.php` fuera de `public_html` o en una tabla con cifrado AES-256-GCM usando una clave del sistema de archivos.

#### 1.3.6 Envíos y logística

```sql
CREATE TABLE transportadoras (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre         VARCHAR(60) NOT NULL,
  activo         TINYINT(1)  NOT NULL DEFAULT 1,
  tracking_url   VARCHAR(200) NOT NULL DEFAULT '',   -- https://…/{guia}
  api_driver     VARCHAR(30) NOT NULL DEFAULT 'manual', -- manual | coordinadora | servientrega | envía
  config         JSON NULL
) ENGINE=InnoDB;

CREATE TABLE envios (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pedido_id       INT UNSIGNED NOT NULL UNIQUE,
  transportadora_id SMALLINT UNSIGNED NULL,
  guia            VARCHAR(60) NULL,
  estado          ENUM('pendiente','generada','en_transito','entregado','devuelto') NOT NULL DEFAULT 'pendiente',
  peso_g          INT UNSIGNED NOT NULL DEFAULT 0,
  costo           INT UNSIGNED NOT NULL DEFAULT 0,
  fecha_despacho  DATETIME NULL,
  fecha_entrega   DATETIME NULL,
  CONSTRAINT fk_env_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  CONSTRAINT fk_env_trans FOREIGN KEY (transportadora_id) REFERENCES transportadoras(id) ON DELETE SET NULL,
  KEY idx_env_estado (estado)
) ENGINE=InnoDB;

CREATE TABLE zonas_envio (
  id     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE zonas_cobertura (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  zona_id       SMALLINT UNSIGNED NOT NULL,
  departamento  VARCHAR(40) NOT NULL,
  ciudad        VARCHAR(60) NOT NULL DEFAULT '',     -- '' = todo el departamento
  UNIQUE KEY uq_cob (departamento, ciudad),
  CONSTRAINT fk_cob_zona FOREIGN KEY (zona_id) REFERENCES zonas_envio(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE tarifas_envio (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  zona_id        SMALLINT UNSIGNED NOT NULL,
  desde_peso_g   INT UNSIGNED NOT NULL DEFAULT 0,
  hasta_peso_g   INT UNSIGNED NOT NULL,
  precio         INT UNSIGNED NOT NULL,
  gratis_desde   INT UNSIGNED NULL,                   -- ej. gratis sobre $200.000
  CONSTRAINT fk_tar_zona FOREIGN KEY (zona_id) REFERENCES zonas_envio(id) ON DELETE CASCADE,
  KEY idx_tar_zona (zona_id, desde_peso_g)
) ENGINE=InnoDB;
```

#### 1.3.7 Facturación

```sql
CREATE TABLE facturas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pedido_id   INT UNSIGNED NOT NULL UNIQUE,
  prefijo     VARCHAR(4)  NOT NULL DEFAULT 'MG',
  numero      INT UNSIGNED NOT NULL,
  numero_completo VARCHAR(20) NOT NULL UNIQUE,        -- MG-000123
  fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  subtotal    INT UNSIGNED NOT NULL,
  descuento   INT UNSIGNED NOT NULL DEFAULT 0,
  impuestos   INT UNSIGNED NOT NULL DEFAULT 0,        -- IVA
  total       INT UNSIGNED NOT NULL,
  estado      ENUM('emitida','anulada') NOT NULL DEFAULT 'emitida',
  cufe        VARCHAR(120) NULL,                      -- solo si hay proveedor DIAN
  CONSTRAINT fk_fac_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE RESTRICT,
  KEY idx_fac_fecha (fecha)
) ENGINE=InnoDB;
```

**Nota de arquitecto:** la facturación electrónica DIAN **no se implementa en PHP puro sobre hosting compartido** (requiere firma XML, certificado digital, resolución y transmisión a la DIAN). Camino recomendado: (a) hoy, numeración interna consecutiva + vista imprimible que el navegador guarda como PDF; (b) cuando el volumen lo exija, integrar un proveedor habilitado (Factus, Alegra, Siigo) vía cURL y guardar `cufe`. La tabla ya está preparada para ambos.

#### 1.3.8 Marketing y promociones

```sql
CREATE TABLE cupones (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo         VARCHAR(30) NOT NULL UNIQUE,
  tipo           ENUM('pct','fijo','envio_gratis') NOT NULL DEFAULT 'pct',
  valor          INT UNSIGNED NOT NULL,               -- 10 (=10 %) o 20000 ($20.000)
  minimo         INT UNSIGNED NOT NULL DEFAULT 0,
  desde          DATETIME NULL,
  vence          DATETIME NULL,
  usos_max       INT UNSIGNED NULL,
  usos           INT UNSIGNED NOT NULL DEFAULT 0,
  max_por_cliente TINYINT UNSIGNED NULL,
  primera_compra TINYINT(1) NOT NULL DEFAULT 0,
  acumulable     TINYINT(1) NOT NULL DEFAULT 0,
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  creado         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE cupon_usos (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cupon_id    INT UNSIGNED NOT NULL,
  pedido_id   INT UNSIGNED NOT NULL,
  cliente_id  INT UNSIGNED NULL,
  monto       INT UNSIGNED NOT NULL,
  creado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_uso (cupon_id, pedido_id),
  CONSTRAINT fk_uso_cup FOREIGN KEY (cupon_id) REFERENCES cupones(id) ON DELETE CASCADE,
  CONSTRAINT fk_uso_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Promociones: reglas declarativas para 2x1, volumen (3x2, "lleve 5 pague 4"), combos.
CREATE TABLE promociones (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(80) NOT NULL,
  tipo       ENUM('nxm','volumen','combo','descuento_categoria') NOT NULL,
  prioridad  TINYINT UNSIGNED NOT NULL DEFAULT 10,    -- menor = se evalúa primero
  desde      DATETIME NULL,
  hasta      DATETIME NULL,
  condiciones JSON NOT NULL,   -- {"min_items":2,"categorias":["guantes"],"segmento":"nuevo"}
  beneficios  JSON NOT NULL,   -- {"paga":1,"lleva":2} | {"desde":3,"pct":15}
  activa     TINYINT(1) NOT NULL DEFAULT 1,
  exclusiva  TINYINT(1) NOT NULL DEFAULT 1,           -- no se acumula con cupones
  creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE promocion_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  promocion_id INT UNSIGNED NOT NULL,
  producto_id  INT UNSIGNED NULL,
  categoria_id VARCHAR(40) NULL,
  UNIQUE KEY uq_pi (promocion_id, producto_id, categoria_id),
  CONSTRAINT fk_pi_prom FOREIGN KEY (promocion_id) REFERENCES promociones(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE carritos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token         CHAR(36) NOT NULL UNIQUE,
  cliente_id    INT UNSIGNED NULL,
  email         VARCHAR(120) NULL,                    -- se captura en el paso 1 del checkout
  telefono      VARCHAR(20) NULL,
  cupon_codigo  VARCHAR(30) NULL,
  subtotal      INT UNSIGNED NOT NULL DEFAULT 0,
  estado        ENUM('activo','abandonado','recuperado','convertido') NOT NULL DEFAULT 'activo',
  recordatorios TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ultima_actividad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pedido_id     INT UNSIGNED NULL,
  creado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_car_estado (estado, ultima_actividad),
  KEY idx_car_email (email)
) ENGINE=InnoDB;

CREATE TABLE carrito_items (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  carrito_id  BIGINT UNSIGNED NOT NULL,
  variante_id INT UNSIGNED NOT NULL,
  cantidad    SMALLINT UNSIGNED NOT NULL,
  precio      INT UNSIGNED NOT NULL,                  -- foto del precio al agregar
  CONSTRAINT fk_ci_car FOREIGN KEY (carrito_id) REFERENCES carritos(id) ON DELETE CASCADE,
  CONSTRAINT fk_ci_var FOREIGN KEY (variante_id) REFERENCES variantes(id) ON DELETE CASCADE,
  UNIQUE KEY uq_ci (carrito_id, variante_id)
) ENGINE=InnoDB;
```

#### 1.3.9 Devoluciones (RMA)

```sql
CREATE TABLE devoluciones (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pedido_id  INT UNSIGNED NOT NULL,
  motivo     ENUM('talla','defecto','no_corresponde','arrepentimiento','otro') NOT NULL,
  descripcion VARCHAR(500) NOT NULL DEFAULT '',
  estado     ENUM('solicitada','aprobada','rechazada','recibida','reembolsada') NOT NULL DEFAULT 'solicitada',
  monto      INT UNSIGNED NOT NULL DEFAULT 0,
  usuario_id INT UNSIGNED NULL,
  creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dev_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE RESTRICT,
  KEY idx_dev_estado (estado, creado)
) ENGINE=InnoDB;

CREATE TABLE devolucion_items (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  devolucion_id  INT UNSIGNED NOT NULL,
  pedido_item_id INT UNSIGNED NOT NULL,
  cantidad       SMALLINT UNSIGNED NOT NULL,
  accion         ENUM('reembolso','cambio') NOT NULL DEFAULT 'reembolso',
  reingresa_stock TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_di_dev FOREIGN KEY (devolucion_id) REFERENCES devoluciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_di_item FOREIGN KEY (pedido_item_id) REFERENCES pedido_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

#### 1.3.10 Seguridad y RBAC

```sql
CREATE TABLE roles (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre      VARCHAR(40) NOT NULL UNIQUE,            -- admin | bodega | ventas | soporte
  descripcion VARCHAR(120) NOT NULL DEFAULT '',
  es_sistema  TINYINT(1) NOT NULL DEFAULT 0           -- 'admin' no se puede borrar
) ENGINE=InnoDB;

CREATE TABLE permisos (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clave       VARCHAR(40) NOT NULL UNIQUE,            -- productos.editar, pedidos.cancelar
  grupo       VARCHAR(30) NOT NULL,
  descripcion VARCHAR(120) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

CREATE TABLE rol_permisos (
  rol_id     SMALLINT UNSIGNED NOT NULL,
  permiso_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (rol_id, permiso_id),
  CONSTRAINT fk_rp_rol FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE usuarios (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(80)  NOT NULL,
  usuario       VARCHAR(40)  NOT NULL UNIQUE,
  email         VARCHAR(120) NOT NULL UNIQUE,
  clave_hash    VARCHAR(255) NOT NULL,                -- password_hash(PASSWORD_DEFAULT)
  rol_id        SMALLINT UNSIGNED NOT NULL,
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  totp_secreto  VARBINARY(64) NULL,                   -- 2FA opcional (cifrado)
  totp_activo   TINYINT(1)   NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME NULL,
  ultimo_acceso DATETIME NULL,
  creado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_usr_rol FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE auditoria (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NULL,
  accion     VARCHAR(40) NOT NULL,                    -- producto.editar, pedido.cancelar
  entidad    VARCHAR(30) NOT NULL,
  entidad_id BIGINT UNSIGNED NULL,
  cambios    JSON NULL,                               -- {"precio":{"antes":1199000,"ahora":999000}}
  ip         VARCHAR(45) NOT NULL DEFAULT '',
  creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aud_usr (usuario_id, creado),
  KEY idx_aud_ent (entidad, entidad_id)
) ENGINE=InnoDB;
```

> `admins` e `intentos` actuales se reemplazan por `usuarios` + `intentos_login` (con `usuario` además de `ip`, para no bloquear a todo el equipo por culpa de una IP compartida).

#### 1.3.11 Sistema, KPIs y tráfico

```sql
CREATE TABLE configuracion (
  clave      VARCHAR(60) NOT NULL PRIMARY KEY,
  valor      TEXT NULL,
  tipo       ENUM('texto','numero','bool','json') NOT NULL DEFAULT 'texto',
  grupo      VARCHAR(30) NOT NULL DEFAULT 'general',
  es_publico TINYINT(1) NOT NULL DEFAULT 0,           -- se puede exponer al frontend
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE cache_kv (
  clave     VARCHAR(80) NOT NULL PRIMARY KEY,
  valor     MEDIUMTEXT NOT NULL,
  expira    DATETIME NOT NULL,
  KEY idx_cache_exp (expira)
) ENGINE=InnoDB;

CREATE TABLE visitas (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fecha     DATE NOT NULL,
  sesion    CHAR(32) NOT NULL,                        -- hash de sesión, sin datos personales
  ruta      VARCHAR(120) NOT NULL DEFAULT '',
  referrer  VARCHAR(255) NOT NULL DEFAULT '',
  utm_source VARCHAR(60) NOT NULL DEFAULT '',
  utm_medium VARCHAR(60) NOT NULL DEFAULT '',
  utm_campaign VARCHAR(60) NOT NULL DEFAULT '',
  dispositivo ENUM('movil','escritorio','tablet') NOT NULL DEFAULT 'escritorio',
  ip_hash   CHAR(64) NOT NULL DEFAULT '',
  KEY idx_vis_fecha (fecha),
  KEY idx_vis_ses (sesion)
) ENGINE=InnoDB;

CREATE TABLE kpi_snapshots (
  fecha          DATE NOT NULL PRIMARY KEY,
  ingresos       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  pedidos        INT UNSIGNED NOT NULL DEFAULT 0,
  ticket         INT UNSIGNED NOT NULL DEFAULT 0,
  visitas        INT UNSIGNED NOT NULL DEFAULT 0,
  conversion     DECIMAL(5,2) NOT NULL DEFAULT 0,
  carritos_creados INT UNSIGNED NOT NULL DEFAULT 0,
  actualizado    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE notificaciones (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NULL,                       -- NULL = para todos los admins
  tipo       ENUM('pedido','stock','pago','mensaje','sistema') NOT NULL,
  titulo     VARCHAR(120) NOT NULL,
  cuerpo     VARCHAR(300) NOT NULL DEFAULT '',
  url        VARCHAR(200) NOT NULL DEFAULT '',
  leida      TINYINT(1) NOT NULL DEFAULT 0,
  creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_not_usr (usuario_id, leida, creado)
) ENGINE=InnoDB;
```

#### 1.3.12 Migración desde el esquema actual

| Actual | Destino | Acción |
|---|---|---|
| `admins` | `usuarios` + `roles` | Copiar filas con `rol_id = admin`; `clave_hash` se conserva tal cual (mismo algoritmo). |
| `producto_tallas` | `variantes` | Copiar `producto_id, talla, stock`; `color=''`, `sku` autogenerado, `stock_minimo=3`. |
| `productos.img` | `producto_imagenes` | Insertar como imagen `principal=1`; conservar `img` como columna de compatibilidad durante la transición. |
| `pedidos.cliente/telefono/...` | `pedidos.cliente_*` + `clientes` | Crear/relacionar clientes por email o teléfono; renombrar columnas. |
| `cupones.usos` | `cupones.usos` + `cupon_usos` | Mantener contador; empezar a registrar usos nuevos. |
| `intentos` | `intentos_login` | Añadir columna `usuario`. |

**Orden de despliegue seguro:** crear tablas nuevas → migrar datos → desplegar código que lee de las nuevas → mantener tablas viejas 1 semana como respaldo → borrar. Nunca al revés.

### 1.4 Índices y rendimiento

| Consulta del panel | Índice que la sostiene |
|---|---|
| Pedidos por estado, últimos primero | `idx_ped_estado (estado, creado)` |
| Pedidos de un cliente | `idx_ped_cli (cliente_id, creado)` |
| Ítems de un pedido | `idx_item_ped (pedido_id)` |
| Productos por categoría activos | `idx_prod_cat (categoria_id, activo)` |
| Alertas de stock | `v_stock_bajo` (usa `variantes.stock/stock_minimo`, índice en `producto_id`) |
| Movimientos de una variante | `idx_mov_var (variante_id, creado)` |
| Búsqueda de productos | `FULLTEXT ft_prod` → `MATCH … AGAINST (… IN BOOLEAN MODE)` |
| Carritos abandonados | `idx_car_estado (estado, ultima_actividad)` |
| Webhook repetido | `uq_pago_ref` |

**KPIs sin castigar la base:** el dashboard no hace `SUM()` sobre todo `pedidos`. Un cron cada 10 min calcula el día anterior en `kpi_snapshots`; el rango "hoy" se consulta en vivo (pocas filas) y se cachea 60 s en `cache_kv`.

---

## 2. FLUJO DE TRABAJO — Ciclo de vida del pedido

### 2.1 Diagrama de estados

```
                        ┌──────────────────┐
   cliente hace clic ──►│ pendiente_pago   │◄── cron: expira_pago vencido
   "Realizar pedido"    └────────┬─────────┘        │
                                 │ pago aprobado    │ cancelado
                                 │ (admin o webhook)│ (+ stock devuelto)
                        ┌────────▼─────────┐        │
                        │   confirmado     │        │
                        └────────┬─────────┘        │
                                 │ picking          │
                        ┌────────▼─────────┐        │
                        │ en_preparación   │────────┤
                        └────────┬─────────┘        │
                                 │ guía generada    │
                        ┌────────▼─────────┐        │
                        │     enviado      │────────┤
                        └────────┬─────────┘        │
                                 │ transportadora   │
                        ┌────────▼─────────┐        │
                        │    entregado     │        │
                        └────────┬─────────┘        │
                                 │ RMA              │
                        ┌────────▼─────────┐        │
                        │    devuelto      │◄───────┘
                        └──────────────────┘
```

### 2.2 Paso a paso técnico

**1) Navegación y carrito (frontend).**
`main.js` guarda `{variante_id, cantidad}` en `localStorage` (nunca precios). Al entrar al checkout y escribir el correo, un `POST /api?r=carrito` crea/actualiza `carritos` con `token` y `carrito_items` — así se pueden medir abandonos aunque el cliente nunca compre.

**2) Envío del pedido (checkout).**
El formulario hace `POST /api?r=pedido` con cabecera `Idempotency-Key: <uuid>` (generado una vez en `sessionStorage`).

**3) Validación y recálculo (servidor, sin excepciones).**
`api/api.php` → caso `pedido`:
1. Rate limit por IP (`< 10`/hora) + honeypot (`web`).
2. Validar campos y normalizar teléfono.
3. Recalcular **todo**: precios desde `variantes`/`productos`, promociones activas, cupón, costo de envío por zona y peso, IVA.
4. Si el `subtotal` enviado por el cliente no coincide con el calculado, **se ignora el del cliente** (solo se registra en un log como intento de manipulación).

**4) Transacción de creación.**
```
BEGIN;
  SELECT … FROM variantes WHERE id IN (…) FOR UPDATE;      -- bloquea filas
  validar stock por variante;
  INSERT pedidos (estado='pendiente_pago', expira_pago = NOW()+2h, idempotencia);
  INSERT pedido_items (…)                                  -- snapshots
  UPDATE variantes SET stock = stock - cantidad;
  INSERT movimientos_stock (tipo='salida', ref_tipo='pedido');
  INSERT pedido_eventos (NULL → 'pendiente_pago');
  INSERT cupon_usos / UPDATE cupones SET usos = usos+1;
  INSERT notificaciones ('pedido', para rol ventas);
COMMIT;
```
Si el `INSERT` de `pedidos` choca con `uq idempotencia`, se responde `200` con el pedido existente (doble clic resuelto).

**5) Confirmación de pago.**
- **Manual (hoy):** el cliente envía comprobante por WhatsApp; el admin marca "Pago recibido" → `pago_estado='pagado'`, `estado='confirmado'`, se emite la factura y se registra `pedido_eventos`.
- **Pasarela (fase posterior):** el checkout pide un `POST /api?r=pago_crear` → PHP llama por cURL a Wompi/MercadoPago y devuelve la URL de pago *hosted* (el cliente nunca pone la tarjeta en tu servidor → menos alcance PCI). La pasarela llama a `POST /api/webhook/wompi`, que **verifica la firma**, busca por `referencia_externa` (idempotente), actualiza `pagos` y transiciona el pedido.

**6) Cron de mantenimiento (hPanel, cada 5–10 min).**
- Pedidos `pendiente_pago` con `expira_pago < NOW()` → `cancelado` + stock devuelto + `cupon_usos` revertido.
- Carritos `activo` sin actividad 2 h → `abandonado`; a las 24 h → correo de recuperación (`recordatorios = 1`).
- Recalcular `kpi_snapshots` del día anterior.
- Limpiar `cache_kv` vencida e `intentos_login` viejos.

**7) Preparación (bodega).**
El rol `bodega` ve solo pedidos `confirmado`. "Iniciar preparación" → `en_preparacion`; el panel muestra la lista de picking con SKU, talla, color y ubicación. Si falta stock físico, se registra `ajuste` negativo con motivo y se avisa al cliente.

**8) Despacho.**
"Cargar guía" → `INSERT envios (transportadora_id, guia)` → `estado='enviado'` → correo/WhatsApp con URL de tracking (plantilla `tracking_url` reemplazando `{guia}`). El pedido pasa a `enviado` automáticamente al guardar la guía.

**9) Entrega.**
Manual (el admin marca "Entregado") o por webhook de la transportadora. Se guarda `entregado_en`, se actualiza `clientes.ultima_compra` y se dispara la automatización postventa (reseña a los 3 días).

**10) Cancelación y devolución.**
- **Antes de enviar:** `cancelado` → devolución de stock **una sola vez** (se valida el estado anterior dentro de la transacción) + `movimientos_stock` de entrada.
- **Después de entregar:** no se cancela; se abre `devoluciones` (RMA). Al pasar a `recibida`, si `reingresa_stock = 1` se devuelve inventario; al pasar a `reembolsada` se marca `pagos.estado='reembolsado'` y `pedidos.estado='devuelto'`.

### 2.3 Matriz de transiciones (permisos y efectos)

| Desde | Hacia | Quién | Efectos secundarios |
|---|---|---|---|
| — | `pendiente_pago` | cliente | Reserva stock, evento, notificación |
| `pendiente_pago` | `confirmado` | ventas / webhook | Pago, **emite factura**, correo de confirmación |
| `pendiente_pago` | `cancelado` | ventas / cron | Devuelve stock, revierte cupón |
| `confirmado` | `en_preparacion` | bodega | Asigna responsable, imprime picking |
| `en_preparacion` | `enviado` | bodega | Crea `envios` + guía, correo con tracking |
| `enviado` | `entregado` | bodega / webhook | `entregado_en`, postventa |
| `enviado` | `devuelto` | soporte | Abre RMA |
| `entregado` | `devuelto` | soporte (solo con RMA aprobado) | Reingresa stock si aplica |
| `cancelado` | — | nadie | **Terminal.** No se reactiva (se crea pedido nuevo) |

Toda transición: `BEGIN` → validar estado actual con `FOR UPDATE` → `UPDATE pedidos` → `INSERT pedido_eventos` → efectos → `COMMIT`. Nunca un `UPDATE … SET estado` suelto.

---

## 3. UI/UX y wireframes (en texto)

### 3.1 Shell de la aplicación

```
┌──────────────┬──────────────────────────────────────────────────────────────┐
│  SIDEBAR     │ TOPBAR 64px                                                  │
│  248px       │  ☰  Inicio / Pedidos / #MG-000123     ⌘K buscar   🔔 3  ⚙  👤│
│  (colapsable ├──────────────────────────────────────────────────────────────┤
│   a 64px)    │                                                              │
│              │  CONTENIDO  (máx. 1440px, grid 12 col, gutter 24px)          │
│  Dashboard   │                                                              │
│  Pedidos  3  │                                                              │
│  Productos   │                                                              │
│  Inventario  │                                                              │
│  Clientes    │                                                              │
│  Marketing   │                                                              │
│  Contenido   │                                                              │
│  ─────────   │                                                              │
│  Ajustes     │                                                              │
│  Auditoría   │                                                              │
└──────────────┴──────────────────────────────────────────────────────────────┘
```

- Sidebar oscura (`#1a1a1a`) con acento rojo `#880000` (coherente con la tienda), ítem activo con barra de 3 px y fondo 8 % blanco. Contadores en pastilla (`Pedidos 3` = pendientes de despachar).
- Topbar clara, `position: sticky`, con búsqueda global que abre un command palette (`⌘K` / `/`): escribe "casco" o "#123" o un teléfono y salta directo.
- Debajo de 1024 px el sidebar pasa a overlay; debajo de 768 px las tablas se convierten en tarjetas apiladas y el panel queda **operativo pero de lectura** (avisar: "para editar usa escritorio").

**Tokens** (`admin.css`, `:root`) — ya existen, extender:

```css
:root{
  --rojo:#880000; --rojo-hover:#aa0000; --gris:#d4d4d4;
  --fondo:#f6f6f7; --superficie:#fff; --borde:#e5e5e7;
  --texto:#161618; --texto-2:#5c5c63; --texto-3:#8a8a92;
  --ok:#1e7e34; --warn:#b26a00; --error:#c62828; --info:#1d4ed8;
  --radio:10px; --sombra:0 1px 2px rgba(0,0,0,.06),0 8px 24px rgba(0,0,0,.06);
  --fila:44px;              /* densidad cómoda; 36px en modo compacto */
}
```

### 3.2 Dashboard de control

```
┌─ Filtros ───────────────────────────────────────────────────────────────────┐
│ [Hoy] [7 días] [30 días] [Mes actual] [Personalizado]   vs periodo anterior │
│                                            Actualizado hace 12 s  ↻  ⬇ CSV  │
└─────────────────────────────────────────────────────────────────────────────┘

┌───────────┬───────────┬───────────┬───────────┬─────────────────────────────┐
│ INGRESOS  │ PEDIDOS   │ TICKET    │ CONVERSIÓN│  POR DESPACHAR              │
│ $12.480.000│    87     │ $143.448  │   2,4 %   │  6 pedidos  →  [Ver]        │
│ ▲ 12,3 %  │ ▲ 8,1 %   │ ▼ 2,0 %   │ ▲ 0,3 pp  │  ⚠ 2 con pago pendiente     │
│ ╱╲╱‾╲╱     │ ╱‾╲╱╲‾    │ ‾╲╱╲╱‾    │ ╱‾╱╲╱      │                             │
└───────────┴───────────┴───────────┴───────────┴─────────────────────────────┘

┌─ Ingresos y pedidos (8 col) ──────────────┬─ Embudo y estados (4 col) ──────┐
│  $                                        │   ● nuevo        12             │
│  │        ▂    ▅                           │   ● confirmado    8             │
│  │   ▃   ▅ █  █ ▇                          │   ● preparación   4             │
│  │  ▅ █ █ █ █ █ █                          │   ● enviado       7             │
│  └──────────────────────                  │   ● entregado    56             │
│   lun  mar  mié  jue  vie  sáb  dom       │   ─────────────────────         │
│   [Ingresos ▉]  [Pedidos ─]               │   Visitas  3.640                │
│                                            │   Carritos   310                │
│                                            │   Pedidos     87   (2,4 %)      │
└────────────────────────────────────────────┴─────────────────────────────────┘

┌─ Últimos pedidos (8 col) ─────────────────┬─ Alertas (4 col) ───────────────┐
│ #MG-000123  Ana Ruiz     $289.000  ●nuevo │ ⚠ 3 variantes bajo mínimo        │
│ #MG-000122  J. Pérez     $1.199.000 ●env.  │   Guante LS2 M · 2/3  [Reponer] │
│ #MG-000121  M. Gómez     $ 89.000  ●entr. │ ⏱ 2 pagos pendientes > 2 h       │
│ …                                         │ 📦 1 pedido sin mover 48 h       │
│                           [Ver todos →]   │ 💬 4 mensajes sin responder      │
└───────────────────────────────────────────┴─────────────────────────────────┘

┌─ Top productos (6) ───────────┬─ Top categorías (3) ──┬─ Cupones (3) ────────┐
│ Casco Integral  32 uds ▉▉▉▉▉ │ Cascos     45 %       │ MOTO10  18 usos      │
│ Guante Racing   21 uds ▉▉▉   │ Guantes    28 %       │ BIENVENIDA 6 usos    │
└───────────────────────────────┴───────────────────────┴──────────────────────┘
```

**Decisiones de UX:**

- **Jerarquía:** primero "¿cuánto vendí?", después "¿qué hago ahora?" (alertas), al final "contexto" (top productos). El operario abre el panel y en 3 segundos sabe si hay algo urgente.
- **Comparación siempre visible:** cada KPI muestra variación vs. periodo anterior con flecha y color semántico (verde/rojo). El color nunca es el único indicador: hay flecha y texto.
- **Conversión real:** requiere contar visitas. Se mide con un `POST /api?r=visita` (beacon de 1×1 px o `navigator.sendBeacon`) desde `main.js`. Sin eso, "conversión" sería un número inventado.
- **Actualización:** no hay WebSockets. Polling cada 60 s de `?r=kpis&desde=<timestamp>` que devuelve solo lo que cambió; el indicador "Actualizado hace X s" es honesto y clickeable.
- **Gráficos sin librerías:** SVG generado con JS (helper de ~80 líneas). Barras = `<rect>`, líneas = `<polyline>`, dona = `<circle>` con `stroke-dasharray`, tooltips = `<div>` posicionado con `getBoundingClientRect()`. Ventaja: cero dependencias, accesible (`role="img"` + `<desc>`), responsive por `viewBox`, y respeta `prefers-reduced-motion`.
- **Estados:** esqueleto gris con `shimmer` al cargar; vacío con ilustración + una acción concreta ("Aún no hay pedidos · Compartí tu tienda"); error con botón "Reintentar". Nunca un panel en blanco.

### 3.3 Detalle de producto

```
┌ ← Productos   Casco Integral Pro  MG-CAS-PRO  [● Activo]        ┌──────────┐
│                                          [Ver en tienda ↗]     │ ESTADO   │
├──────────────────────────────────────────┬────────────────────  │ ○ Borrador
│ [General] [Precios] [Variantes] [Invent.]│ [Multimedia] [Envío]  │ ● Activo
│ [SEO]                                    │                      │ ○ Archivado
│                                          │                      │
│  Nombre*      [Casco Integral Pro      ] │ VISIBILIDAD          │
│  Slug         [/casco-integral-pro     ] │ ☑ Destacado          │
│  Categoría*   [Cascos            ▾]      │ ☑ Visible en tienda  │
│  Marca        [Nolan                   ] │ Etiqueta [Nuevo ▾]   │
│  Descripción corta (tarjeta)             │                      │
│  [Certificación DOT/ECE…            ] 42/255                     │
│  Descripción larga (ficha)               │ RESUMEN              │
│  ┌────────────────────────────────────┐  │ Stock total      38  │
│  │ B  I  •  ≡  🔗                     │  │ Variantes activas 9  │
│  │ …                                  │  │ Margen           41% │
│  └────────────────────────────────────┘  │ Envío       $12.000  │
│  ☑ Mostrar en "Más vendidos"             │                      │
│                                          │ VISTA PREVIA          │
│  ⚠ Zona peligrosa: [Archivar] [Eliminar]│ ┌──────────────────┐ │
│    (Eliminar solo si no tiene pedidos)   │ │ [img] Nuevo      │ │
│                                          │ │ Casco Integral   │ │
│                                          │ │ $1.199.000       │ │
│                                          │ └──────────────────┘ │
│                                          │ Última edición:      │
│                                          │ Ana · hace 2 días    │
└──────────────────────────────────────────┴──────────────────────┘
        ▲ barra inferior sticky: [Descartar]        [Guardar cambios]
```

**Pestañas del panel central (8 columnas):**

| Pestaña | Contenido |
|---|---|
| **General** | Nombre, slug autogenerado (editable), categoría, marca, descripciones, destacado, zona peligrosa. |
| **Precios** | Precio venta, precio antes (tachado), costo, **margen calculado en vivo**, IVA. Si `precio_antes > precio`, se muestra automáticamente como oferta. |
| **Variantes** | **Matriz talla × color**: filas = tallas, columnas = colores, celdas = stock editable. Botón "Generar combinaciones" (tallas S–XXL × 3 colores = 15 variantes en un clic). Acciones masivas: "aplicar stock a todas", "copiar precio extra", "activar/desactivar". Cada celda muestra el SKU al hacer hover. |
| **Inventario** | Libro mayor filtrable (`entradas`, `salidas`, `ajustes`, `devoluciones`), fecha, usuario y motivo. Botón "Ajustar stock" con formulario que exige motivo (auditoría). |
| **Multimedia** | Dropzone con arrastrar y soltar, reordenable, primera imagen = principal, `alt` obligatorio, conversión a WebP y 3 tamaños (400/800/1600 px) en el servidor, límite 6 imágenes, 4 MB por archivo. |
| **Envío** | Peso y dimensiones (alimentan el cálculo de tarifas), clase de envío. |
| **SEO** | Meta título (70), meta descripción (160) con contadores, y **vista previa del resultado en Google** en vivo. |

**Rail derecho (4 columnas, sticky):** estado, visibilidad, resumen calculado, vista previa real con el CSS de la tienda, historial de cambios. Todo lo que el operario necesita para decidir sin cambiar de pantalla.

**Patrones transversales:**

- **Tabla densa:** cabecera sticky, filas de 44 px (36 en modo compacto), hover con fondo suave, selección múltiple con checkbox, acciones en un menú `⋯`, paginación server-side (`?pagina=2&orden=creado&dir=desc`), filtros como *chips* removibles y **vistas guardadas** ("Mis pendientes", "Pagos por verificar").
- **Drawer en vez de página** para pedidos y clientes: panel lateral de 560 px que conserva la tabla de fondo (no se pierde el contexto ni el scroll).
- **Modales nativos:** `<dialog>` con `showModal()` — foco atrapado y `Esc` gratis.
- **Confirmaciones destructivas:** escribir el nombre del producto o `CANCELAR` para habilitar el botón. Nunca un `confirm()` genérico.
- **Toasts** con `role="status"` / `role="alert"` (ya implementado) y acción "Deshacer" cuando sea reversible.
- **Formularios:** validación al salir del campo (no en cada tecla), errores junto al campo con `aria-describedby`, guardado con `Ctrl+S`, aviso al salir con cambios sin guardar (`beforeunload`).
- **Atajos:** `g p` productos, `g d` dashboard, `/` buscar, `?` ayuda con la lista.
- **Accesibilidad:** contraste AA (el rojo `#880000` sobre blanco cumple), foco visible (ya existe en `style.css:734`), objetivos táctiles ≥ 40 px, `aria-live` en contadores, todo operable con teclado.

---

## 4. RECOMENDACIÓN TECNOLÓGICA

### 4.1 Stack dentro de tus restricciones (recomendado)

| Capa | Elección | Justificación |
|---|---|---|
| **Frontend** | HTML5 semántico + CSS moderno (Grid, Flex, custom properties, `clamp()`) + **JS ES2022 nativo con módulos** | Cero build, cero dependencias, carga instantánea en hosting compartido. `type="module"` permite dividir `admin.js` en archivos legibles sin bundler. |
| **Componentes UI** | **Web Components** nativos (`<tabla-datos>`, `<panel-drawer>`, `<campo-moneda>`) + `<dialog>` | Reutilización real sin framework. Encapsulación con Shadow DOM opcional (solo donde aporte). |
| **Gráficos** | SVG generado por JS (helper propio de ~80 líneas) | Sin Chart.js (80 KB) ni CDN externo. Accesible y con el look exacto de la marca. |
| **Backend** | **PHP 8.2+ nativo**, `declare(strict_types=1)`, PDO MySQL, front controller + router | Es lo que el hosting ofrece y lo que ya dominas. PHP 8.2 con OPcache es sobradamente rápido para este volumen. |
| **Organización PHP** | Namespaces + `spl_autoload_register` (sin Composer), capas: `Controlador → Servicio → Repositorio` | Testeable y ordenado. Composer opcional: si algún día lo necesitas, se añade sin reescribir. |
| **Base de datos** | **MySQL 8 / MariaDB 10.6** (el del plan), InnoDB, `utf8mb4`, FKs, vistas y `FULLTEXT` | Ya lo tienes; phpMyAdmin para operar. Sin ORM: SQL explícito y revisable. |
| **Caché** | Tabla `cache_kv` + archivos en `storage/cache/` | No hay Redis en el plan. 60 s de caché en KPIs y catálogo elimina el 90 % de la carga. |
| **Tareas programadas** | **Cron de hPanel** cada 5–10 min → `php /home/uXXXX/cron/cron.php` | Único mecanismo fiable de "background" en shared. El script se protege con token y `php_sapi_name() === 'cli'`. |
| **Correo** | SMTP de Hostinger + **PHPMailer** (2 archivos, sin Composer) o cliente SMTP propio | `mail()` nativo termina en spam. SMTP autenticado con SPF/DKIM del dominio es la diferencia entre "correo" y "correo que llega". |
| **Pagos (Colombia)** | **Wompi** (Bancolombia) o **Mercado Pago Checkout Pro**, con **checkout hospedado** | El cliente paga en la página del proveedor: nunca tocas datos de tarjeta → no necesitas PCI-DSS. Integración: cURL + webhook firmado. |
| **Logística** | Drivers por transportadora (`manual` primero, APIs de Coordinadora/Servientrega después) | Empezar cargando la guía a mano es correcto: la API requiere contrato y volumen. La interfaz `api_driver` deja la puerta abierta. |
| **Facturación** | Numeración interna + vista imprimible → PDF del navegador; proveedor DIAN después | Ver nota en 1.3.7. |
| **Imágenes** | GD (ya disponible) + WebP + 3 tamaños + `srcset` | Sin servicios externos ni coste por transformación. |
| **CDN / WAF** | **Cloudflare Free** delante del dominio | Cachea estáticos, filtra bots, da HTTPS y reglas básicas gratis. |
| **Analítica** | Tabla `visitas` propia + (opcional) GA4 | La conversión interna no depende de cookies de terceros. |
| **Backups** | hPanel + export SQL semanal automático a tu PC/Drive | Regla 3-2-1 básica. |
| **Monitoreo** | `error_log` + tabla `auditoria` + UptimeRobot (gratis) | Sin APM en shared; con esto detectas el 95 % de los problemas. |
| **Desarrollo** | VS Code + SFTP/SSH, `php -l`, ESLint (solo para ti, no en producción), Git | Sin pipelines: `git push` + subida por SFTP. |

### 4.2 Lo que NO usar en este plan

| Evitar | Motivo |
|---|---|
| React, Vue, Angular, Svelte | Requieren build y Node; contradicen tu restricción y suman complejidad sin beneficio a esta escala. |
| Laravel / Symfony | Excelentes, pero pesados para shared y te obligan a Composer + estructura completa. |
| Node/Express como backend | Hostinger Single no ejecuta procesos Node persistentes. |
| WebSockets / Socket.io | Sin procesos persistentes. Usa polling (60 s) o SSE con cuidado. |
| Redis, RabbitMQ, colas reales | No hay servicios. Cola = tabla `jobs` + cron. |
| ORMs pesados (Doctrine, Eloquent) | SQL explícito con PDO es más rápido de depurar en phpMyAdmin. |
| Docker / Kubernetes | No aplica. |
| Librerías de gráficos por CDN | Dependencia externa, peso y riesgo de caída; tu SVG propio gana. |

### 4.3 Estructura de carpetas propuesta

```
public_html/
├── index.html · productos.html · carrito.html · contacto.html · sobre.html
├── style.css · productos.css · carrito.css · contacto.css · sobre.css
├── main.js
├── img/                        (estáticos + uploads)
├── admin/                      ← backoffice
│   ├── index.html               → shell SPA-like (una sola página, vistas por hash)
│   ├── admin.css
│   └── js/
│       ├── app.js               arranque, router por hash, sesión
│       ├── api.js               fetch + CSRF + manejo de errores
│       ├── ui/                  tabla-datos.js, drawer.js, modal.js, toast.js, charts.js
│       └── vistas/              dashboard.js, pedidos.js, productos.js, clientes.js, …
├── api/
│   ├── index.php                front controller (router ?r= o /api/v1/…)
│   ├── config.local.php         ← FUERA del repo y del webroot si se puede
│   └── src/
│       ├── Db.php · Sesion.php · Rbac.php · Csrf.php · Validador.php
│       ├── Catalogo/            ProductoRepo.php, VarianteRepo.php, InventarioServicio.php
│       ├── Pedidos/             PedidoServicio.php, EstadoMaquina.php, FacturaServicio.php
│       ├── Clientes/ · Marketing/ · Envios/ · Seguridad/
│       └── webhooks/            wompi.php, mercadopago.php, transportadora.php
├── cron/
│   └── cron.php                 tareas programadas (solo CLI)
├── sql/
│   ├── schema.sql
│   └── migraciones/             001_variantes.sql, 002_rbac.sql, …
└── storage/                     cache/ · logs/ · facturas/  (denegado por .htaccess)
```

### 4.4 Seguridad del backoffice (checklist mínimo)

1. **RBAC en el servidor:** cada ruta declara el permiso requerido (`pedidos.cancelar`, `productos.editar`). La UI oculta lo que no corresponde, pero el `403` es la garantía.
2. **CSRF** por cabecera `X-CSRF` en todo `POST/PUT/DELETE` (ya implementado, mantener).
3. **2FA TOTP** para el rol admin: 100 líneas de PHP puro (Base32 + `hash_hmac('sha1')`), sin dependencias. Opcional pero recomendado.
4. **Sesión:** `HttpOnly`, `Secure`, `SameSite=Lax`, `session_regenerate_id` al entrar, expiración por inactividad (30 min), registro de IP/UA, botón "cerrar todas las sesiones".
5. **Contraseñas:** `password_hash(PASSWORD_DEFAULT)`, mínimo 12 caracteres, bloqueo exponencial por usuario+IP (no solo IP), y comparación de hash dummy cuando el usuario no existe (evita enumeración por tiempo).
6. **Auditoría** de todo cambio sensible: quién, qué, antes/después, IP.
7. **Cabeceras:** CSP (`default-src 'self'`), `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, HSTS, `Permissions-Policy`.
8. **`display_errors = Off`** en producción, `error_log` fuera del webroot.
9. **Backups probados**: un backup que nunca se restauró no es un backup.
10. **Secretos** en `config.local.php` (idealmente un nivel arriba de `public_html`), nunca en Git, nunca en la base de datos en claro.

### 4.5 Ruta de evolución (si el negocio crece)

| Señal | Siguiente paso |
|---|---|
| > 300 pedidos/mes o cron que no alcanza | VPS + PHP-FPM + Redis + workers (manteniendo el mismo PHP nativo). |
| Equipo > 5 personas o necesidades de reporting avanzadas | Migrar backend a Laravel (tu SQL y tu lógica se reutilizan casi tal cual). |
| Catálogo > 5.000 productos con filtros complejos | Meilisearch/Typesense como buscador externo. |
| Facturación electrónica obligatoria | Proveedor DIAN habilitado vía API. |
| Multi-sucursal / multi-bodega | Añadir `bodegas` + `stock_bodega` al libro mayor (ya está preparado para eso). |

**Regla de oro:** no adoptes ninguna de estas hasta que el dolor sea real. La arquitectura propuesta aguanta cómodamente el primer año de operación en el plan actual.

---

## 5. Plan de implementación por fases

| Fase | Alcance | Entregable verificable |
|---|---|---|
| **0 · Cimientos** (1 sem) | Secretos fuera del repo, `schema.sql`, migración a `variantes`, cabeceras de seguridad, RBAC base | El panel actual sigue funcionando leyendo de `variantes`; login con roles. |
| **1 · Shell + Dashboard** (2 sem) | Shell, router, KPIs, gráficos SVG, alertas, notificaciones | Dashboard con datos reales y comparación de periodos. |
| **2 · Catálogo** (2–3 sem) | CRUD productos, matriz de variantes, multimedia, libro de inventario, alertas de stock | Alta de un producto con 15 variantes en < 2 min. |
| **3 · Pedidos** (2 sem) | Máquina de estados, drawer, picking, guías, factura imprimible, cron de expiración | Pedido de punta a punta sin tocar la base de datos. |
| **4 · CRM y RMA** (2 sem) | Ficha de cliente, historial, segmentos, devoluciones | Devolución completa reingresando stock. |
| **5 · Marketing** (2 sem) | Promociones 2x1/volumen, cupones avanzados, carritos abandonados con correo | Recuperación automática de carrito medida. |
| **6 · Pagos y 2FA** (2 sem) | Wompi/Mercado Pago + webhooks, 2FA, auditoría completa | Pago aprobado sin intervención humana. |

Cada fase cierra con: `php -l` en todos los archivos, prueba de los flujos críticos en móvil, y un backup restaurado en un entorno de prueba.

## 6. Riesgos y mitigaciones

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Límite de CPU/I-O del plan Single | Dashboard lento en horas pico | Caché de KPIs 60 s, `kpi_snapshots`, índices, paginación server-side. |
| Sin procesos en segundo plano | Correos y expiraciones tardías | Cron de hPanel cada 5 min + idempotencia (repetir no rompe nada). |
| Venta sin stock por concurrencia | Cliente molesto, reembolso | `FOR UPDATE` en la transacción + libro mayor + alerta de stock mínimo. |
| Excel como fuente de verdad | Inventario inconsistente | Importación CSV controlada que genere `movimientos_stock` con `ref_tipo='importacion'`. |
| Credenciales filtradas | Pérdida total | Rotación, secretos fuera del repo, 2FA, auditoría, backups. |
| Crecimiento del hosting | Caídas | Plan de evolución (4.5) definido desde hoy; el esquema no cambia al migrar. |
