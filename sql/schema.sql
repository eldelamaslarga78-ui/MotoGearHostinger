-- ============================================================================
-- MotoGear · Esquema base del backoffice
-- Requiere MariaDB 10.4+ / MySQL 8.0+ · InnoDB · utf8mb4
-- Validado importando en MariaDB 10.4 (Hostinger usa MariaDB 10.x).
-- Importar en phpMyAdmin sobre una base vacía.
-- ============================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
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
  sku            VARCHAR(40)  NOT NULL UNIQUE,       -- cÃ³digo interno Ãºnico
  nombre         VARCHAR(120) NOT NULL,
  slug           VARCHAR(140) NOT NULL UNIQUE,       -- /productos/casco-integral-pro
  categoria_id   VARCHAR(40)  NOT NULL,
  marca          VARCHAR(60)  NOT NULL DEFAULT '',
  descr          VARCHAR(255) NOT NULL DEFAULT '',   -- descripciÃ³n corta (tarjeta)
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
  activo         TINYINT(1)   NOT NULL DEFAULT 1,    -- borrado lÃ³gico
  meta_titulo    VARCHAR(70)  NULL,
  meta_desc      VARCHAR(160) NULL,
  creado         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_cat FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE RESTRICT,
  KEY idx_prod_cat (categoria_id, activo),
  KEY idx_prod_dest (destacado, activo),
  FULLTEXT KEY ft_prod (nombre, marca, descr)        -- bÃºsqueda del panel
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

CREATE TABLE variantes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  producto_id   INT UNSIGNED NOT NULL,
  sku           VARCHAR(50)  NOT NULL UNIQUE,        -- MG-CAS-PRO-M-NEG
  talla         VARCHAR(10)  NOT NULL DEFAULT '',    -- '' = producto sin tallas
  color         VARCHAR(30)  NOT NULL DEFAULT '',
  color_hex     CHAR(7)      NULL,
  precio_extra  INT          NOT NULL DEFAULT 0,     -- +/- sobre productos.precio
  stock         INT          NOT NULL DEFAULT 0,     -- cachÃ© del libro mayor
  stock_minimo  INT UNSIGNED NOT NULL DEFAULT 3,     -- umbral de alerta
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_var (producto_id, talla, color),
  CONSTRAINT fk_var_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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

CREATE VIEW v_stock_bajo AS
SELECT p.id AS producto_id, p.nombre, v.id AS variante_id, v.sku, v.talla, v.color,
       v.stock, v.stock_minimo
FROM variantes v
JOIN productos p ON p.id = v.producto_id
WHERE p.activo = 1 AND v.activo = 1 AND v.stock <= v.stock_minimo;

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

CREATE TABLE pedidos (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo           VARCHAR(14)  NOT NULL UNIQUE,     -- MG-000123 (visible al cliente)
  idempotencia     CHAR(36)     NULL UNIQUE,          -- UUID del checkout: evita duplicados
  cliente_id       INT UNSIGNED NULL,
  -- SNAPSHOT del comprador y del envÃ­o (inmutable)
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
  variante_id   INT UNSIGNED NULL,                    -- NULL si el producto se borrÃ³
  producto_id   INT UNSIGNED NULL,
  nombre        VARCHAR(120) NOT NULL,                -- snapshot
  sku           VARCHAR(50)  NOT NULL DEFAULT '',     -- snapshot
  talla         VARCHAR(10)  NOT NULL DEFAULT '',
  color         VARCHAR(30)  NOT NULL DEFAULT '',
  precio        INT UNSIGNED NOT NULL,                -- snapshot del precio unitario
  costo         INT UNSIGNED NOT NULL DEFAULT 0,      -- snapshot del costo (margen histÃ³rico)
  cantidad      SMALLINT UNSIGNED NOT NULL,
  descuento     INT UNSIGNED NOT NULL DEFAULT 0,      -- descuento de lÃ­nea (promos 2x1)
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

CREATE TABLE pagos (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pedido_id          INT UNSIGNED NOT NULL,
  pasarela           ENUM('manual','transferencia','nequi','wompi','mercadopago','contraentrega') NOT NULL,
  referencia_externa VARCHAR(100) NULL,              -- id de la pasarela
  estado             ENUM('creado','aprobado','rechazado','reembolsado','error') NOT NULL DEFAULT 'creado',
  monto              INT UNSIGNED NOT NULL,
  payload            JSON NULL,                       -- respuesta cruda (auditorÃ­a)
  creado             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pago_ref (pasarela, referencia_externa),  -- â† idempotencia de webhooks
  CONSTRAINT fk_pago_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE transportadoras (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre         VARCHAR(60) NOT NULL,
  activo         TINYINT(1)  NOT NULL DEFAULT 1,
  tracking_url   VARCHAR(200) NOT NULL DEFAULT '',   -- https://â€¦/{guia}
  api_driver     VARCHAR(30) NOT NULL DEFAULT 'manual', -- manual | coordinadora | servientrega | envÃ­a
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

CREATE TABLE promociones (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(80) NOT NULL,
  tipo       ENUM('nxm','volumen','combo','descuento_categoria') NOT NULL,
  prioridad  TINYINT UNSIGNED NOT NULL DEFAULT 10,    -- menor = se evalÃºa primero
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
  sesion    CHAR(32) NOT NULL,                        -- hash de sesiÃ³n, sin datos personales
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

SET FOREIGN_KEY_CHECKS = 1;
