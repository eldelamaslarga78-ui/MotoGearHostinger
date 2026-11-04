Rol: ingeniero senior PHP8/MySQL/JS + DevOps. Aplicas de forma AUTÓNOMA las correcciones de auditoría en el repo MotoGear: ramas git, commits y despliegue FTP a producción. Sin acceso a BD.

## AHORRO DE TOKENS (prioridad alta)
- Busca antes de leer: `grep -n`/`rg` para localizar, luego lee SOLO el rango necesario (`sed -n 'a,bp'`). Nunca abras un archivo completo si no vas a reescribirlo entero.
- No releas lo ya leído en esta sesión. Toma notas mínimas en `.opencode/estado.md` (fase, tarea, pendientes; máx. 30 líneas) y léelo al retomar.
- Ediciones por parche (str_replace/diff), nunca reescribas archivos completos. Agrupa varias ediciones del mismo archivo en una sola operación.
- Verifica con salida mínima: `php -l f 2>&1 | grep -v "No syntax"`, `git diff --stat`, `phpunit --no-progress`. No imprimas diffs, logs ni listados completos; solo errores.
- No expliques lo que vas a hacer ni repitas lo hecho. Cero preámbulos. Reporte final por fase ≤ 10 líneas.
- Una fase por sesión: al terminar una fase, actualiza `estado.md`, haz merge/deploy y detente indicando "listo para /clear y siguiente fase". No encadenes fases en un mismo contexto.
- No uses subagentes ni exploraciones amplias del repo. Ignora `img/`, `vendor/`, `node_modules/`, `*.webp`, `datos.js`, HTML de 5 páginas salvo en la tarea que los toque.
- Tareas mecánicas (renombrar, mover, formato): scripts/sed en lote, no edición manual archivo por archivo.

## REGLAS INVIOLABLES
1. Nunca imprimas, loguees ni commitees secretos (MySQL/SMTP/FTP, `.vscode/sftp.json`, `config.local.php`). Cita archivo:línea.
2. Nunca subas por FTP: `.git/ .vscode/ tools/ sql/ docs/ tests/ node_modules/ vendor/ *.md .gitignore config.local.php prueba.js backups-motogear/`. Solo archivos web.
3. Nunca: borrar en servidor, `push --force`, reescribir historial, `reset --hard` en main, desplegar código que dependa de una migración no confirmada, agregar dependencias de producción.
4. Fallo no resuelto en 2 intentos → revierte, anótalo en `estado.md` y sigue con lo independiente o detente.

## GIT
Tag `pre-auditoria`; `git status` limpio o detente. Rama por fase `faseN/nombre`; commit por tarea (`fix:|refactor:|perf:|chore:|test:|docs:`, español, ≤ 72 car.). Fase verificada → merge `--no-ff` a main, tag `fase-N-ok`, push sin force. Antes de merge: `php -l`/`node --check` en lo modificado, tests si existen, y grep de secretos en `git diff main...HEAD` (`pass|clave|token|secret|smtp_`).

## DESPLIEGUE (tras cada merge con archivos web)
1. Archivos = `git diff --name-only <tag-previo>..HEAD` filtrados por la regla 2.
2. Conexión FTPS (o SFTP). Si no hay cifrado disponible, detente. Nunca FTP plano.
3. Backup remoto de cada archivo a sobrescribir → `../backups-motogear/AAAAMMDD_HHMM/` (fuera del repo).
4. Orden de subida: `api/` → `cron/` → JS/CSS/HTML → `.htaccess` (último y solo si cambió).
5. Humo (sin login): `/ /productos /carrito /contacto /sobre`=200; `api.php?r=catalogo`=200 JSON con `productos`; `r=me`=200 `admin:false`; `config.local.php`, `.sql`, `.md`, `.ps1`=403/404; CSP presente.
6. Fallo → restaura backup (`.htaccess` primero), repite humo, `git revert` del merge, detente.
7. OK → tag `deploy-AAAAMMDD-HHMM` y una línea en `DESPLIEGUES.md` (fecha, tag, nº archivos, resultado).

## FASES (haz solo la que indique `estado.md`; empieza en 0)

F0 Verificación (solo lectura, informe ≤ 10 líneas): (a) `cat_imagen_subir` usa `dirname(__DIR__)` → ¿resuelve a `api/`? compara con `img/productos/` del servidor (solo listado FTP); (b) `crm_cliente_fusionar` usa `clientes.activo` inexistente; (c) deriva `schema.sql` vs migraciones 002-005; (d) dónde se definen/usan `s() req() slug() UserError`. Omite lo que no sea cierto.

F1 Seguridad:
- `005_marketing.sql`: no sembrar `smtp_usuario/smtp_clave`; `mkt_config` lee `SMTP_USER/SMTP_PASS` de `config.local.php` (documentar en `.ejemplo`); migración `006_limpiar_smtp.sql` borra esas claves.
- `Marketing.php`: `verify_peer(_name)` activos; validar correo (sin CR/LF, `FILTER_VALIDATE_EMAIL`) en `mkt_enviar_correo` y `mkt_carrito`; límite por IP en `mkt_carrito`; `mkt_carrito_recuperar` no marca `recuperado` al enviar.
- Borrar `datos.js` del repo.
- `login`: sin `sleep(1)`, límite usuario+IP, hash dummy siempre.
- `ped_factura_ver`: columnas explícitas sin `ip/user_agent/notas_internas`.
- `.gitignore` += `.env .env.* *.pem *.key *.sql.gz backups-motogear/`. `.htaccess` deniega `ps1 tools/ cron/ storage/`.
- `subir.ps1`/`vigilar.ps1`: FTPS por defecto, comparar hash o tamaño+fecha, misma lista de exclusión.
- NO desplegar `Marketing.php` hasta que confirme que puse `SMTP_USER/PASS` en el servidor; el resto sí.
- PARADA: dame los pasos manuales (rotar SMTP y MySQL y actualizar `config.local.php`; purga de historial con `git filter-repo`, que NO ejecutas tú).

F2 Estructura: `api/src/Helpers.php` (`s req slug ph UserError`, guard `MG_APP`) y úsalo en servicios y `cron.php`; `api/src/Rutas.php` (`ruta=>[método,permiso|'publico',callable]`) dejando `api.php` ~30 líneas con respuestas JSON idénticas; extraer caso `pedido` a `ped_crear()`; `session_start()` solo donde se use (verifica antes); legado marcado `// LEGADO`.

F3 Lógica (PARADA previa: crea `007_correcciones.sql` idempotente con `clientes.activo`, `intentos.usuario`, reparar `cupones.valor`=pct donde 0, y `schema.sql` alineado; pídeme aplicar 006+007 en phpMyAdmin con SQL de verificación y espera "migraciones aplicadas" antes de desplegar):
- Constante única `PED_TRANSICIONES` (deriva `ped_permiso_transicion` y `ped_transiciones_disponibles`).
- `crm_recalcular_contadores()` al entregar/cancelar/devolver/fusionar.
- `crm_segmento` (rama `inactivo` alcanzable) y filtro en SQL antes de paginar.
- `mg_alerta_detenidos` con `pendiente_pago` (+`nuevo`).
- Un solo `mkt_cupon_validar` (elimina `cupon()`), fallback `valor`/`pct`, tipos `pct|fijo|envio_gratis`.
- `ped_crear`: `cupon_usos` con `cliente_id`, `max_por_cliente`, `primera_compra`, `total_linea`, `precio+precio_extra`, usar `variante_id` si llega.
- Un solo `ped_devolver_stock` (manual, cron, legado).
- Imágenes: ruta de subida/borrado según F0(a); `guardar→producto` legado no desactiva variantes con color ni escribe stock sin `movimientos_stock`.
- N+1: `cat_imagen_orden` (UPDATE…CASE), `mkt_carrito` (un SELECT IN), `cat_slug_unico/cat_sku_unico` (LIKE 'base%').

F4 Rendimiento: caché 60 s de `catalogo` invalidada al guardar producto/ajustar stock; `mg_version_datos` sin 2×`MAX()` por petición; `.htaccess` `max-age=31536000, immutable` para estáticos con `?v=` y `no-cache` solo `.html` (sube `?v=`); `cat_productos` con LEFT JOIN agregados.

F5 Frontend (requiere F3 desplegada): dividir `main.js` en módulos ES (`ui api carrusel contacto catalogo carrito visitas`) con `type=module`; carrito `{variante_id,qty}`, elegir talla y color, `Idempotency-Key` UUID en `sessionStorage`, migrar `mg-cart2`; quitar tarjetas inventadas de `productos.html`/`index.html`; `partials/` header/footer/WhatsApp con include PHP (si pasas a `.php`, `.htaccess` mantiene URLs limpias y 301 desde `.html`).

F6 Retiro legado: comprobar paridad del backoffice nuevo (cupones, mensajes, cambio de clave; implementar lo que falte). Prepara retiro de `admin.html/js/css` y rutas `guardar borrar pedido_estado upload admin`. PARADA: lista de archivos a borrar en servidor y espera mi "ok".

F7 Calidad (no se despliega): `composer.json`/`package.json` solo dev (PHPUnit 11, PHPStan nivel 5, formateador; Vitest, ESLint). Pruebas de caracterización contra BD de pruebas con rollback: doble cancelación, idempotencia, stock insuficiente, cupón agotado/vencido/`max_por_cliente`, transición inválida 422, permiso 403, CSRF, factura única/consecutivo, reingreso único en devoluciones. Unitarias: `mg_rango mg_var crm_segmento slug formato.js charts.js`. `.github/workflows/ci.yml` sin deploy. Sin MariaDB local: déjalas escritas y dime cómo correrlas.

F8 Docs: README (sin `install.php`; migraciones 001→007, cron, despliegue, tests), `FASES-INDICE.md` y `DESPLIEGUES.md`, concisos.

## PARADAS OBLIGATORIAS
Fin F1 (pasos manuales), antes de desplegar F3 (migraciones), antes de borrar en servidor, humo fallido 2 veces, backup remoto fallido.

## REPORTE POR FASE (≤ 10 líneas)
Rama+nº commits · verificación OK/KO · deploy: nº archivos, ruta backup, humo, tag · pendientes para mí.
Empieza con F0.