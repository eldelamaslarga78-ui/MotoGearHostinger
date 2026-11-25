<?php
declare(strict_types=1);
/*
 * Pruebas de la lógica de negocio (sin escribir en la base de datos).
 *
 * Se ejecutan desde la raíz del repositorio con:  php tests/run.php
 * Esta carpeta NO se sube al servidor público (ver tools/subir.ps1).
 */

define('MG_APP', true);

$base = is_dir(__DIR__ . '/../api/src') ? __DIR__ . '/../api' : __DIR__;
require $base . '/src/Util.php';
require $base . '/src/Marketing.php';

// Nueva arquitectura: autoloader PSR-4 (App\ → api/src/).
require $base . '/src/Core/Autoloader.php';
(new \App\Core\Autoloader($base . '/src'))->register();

$ok = 0;
$fallos = [];

function afirma(bool $cond, string $nombre): void {
    global $ok, $fallos;
    if ($cond) { $ok++; } else { $fallos[] = $nombre; }
}

/** Devuelve el mensaje de UserError si la función lo lanza; null si no lanza. */
function lanza(callable $f): ?string {
    try { $f(); return null; }
    catch (\App\Core\UserError $e) { return $e->getMessage(); }
    catch (UserError $e) { return $e->getMessage(); }
}

/* ------------------------------ utilidades ------------------------------ */
afirma(s('  hola  ', 10) === 'hola', 'util: s() recorta espacios');
afirma(s('abcdef', 3) === 'abc', 'util: s() respeta el máximo');
afirma(slug('Cascos Integrales') === 'cascos-integrales', 'util: slug() normaliza');
afirma(req(true, 'x') === null, 'util: req() no lanza si la condición es verdadera');
afirma(lanza(fn() => req(false, 'msg')) === 'msg', 'util: req() lanza UserError con el mensaje');

/* ----------------------------- TOTP (2FA) -------------------------------
 * (migrado a App\Services\SeguridadService; se prueba en la sección final)
 */

/* -------------------------- cupones (reglas) ---------------------------- */
function cupon(array $extra = []): array {
    return array_merge([
        'codigo' => 'TEST', 'tipo' => 'pct', 'valor' => 10, 'pct' => 10, 'activo' => 1,
        'desde' => null, 'vence' => null, 'minimo' => 0, 'usos' => 0, 'usos_max' => null,
        'max_por_cliente' => null, 'primera_compra' => 0,
    ], $extra);
}

[$c, $e, $d] = mk_cupon(cupon(), 100000, 0, 0);
afirma($e === null && $d === 10000, 'cupon: porcentaje 10% sobre 100.000');
[$c, $e, $d] = mk_cupon(cupon(['pct' => 0, 'tipo' => 'pct', 'valor' => 10]), 99999, 0, 0);
afirma($d === 9999, 'cupon: porcentaje redondea hacia abajo');
[$c, $e, $d] = mk_cupon(cupon(['tipo' => 'fijo', 'valor' => 15000, 'pct' => 0]), 100000, 0, 0);
afirma($e === null && $d === 15000, 'cupon: monto fijo');
[$c, $e, $d] = mk_cupon(cupon(['tipo' => 'fijo', 'valor' => 15000, 'pct' => 0]), 10000, 0, 0);
afirma($d === 10000, 'cupon: monto fijo nunca supera el subtotal');
[$c, $e, $d] = mk_cupon(cupon(['tipo' => 'envio_gratis', 'valor' => 0, 'pct' => 0]), 100000, 0, 0);
afirma($e === null && $d === 0, 'cupon: envío gratis no descuenta el subtotal');
afirma(mk_cupon(cupon(['activo' => 0]), 100000, 0, 0)[1] === 'Cupón no válido', 'cupon: inactivo rechazado');
afirma(mk_cupon(cupon(['vence' => '2000-01-01']), 100000, 0, 0)[1] === 'Cupón vencido', 'cupon: vencido rechazado');
afirma(str_starts_with((string)mk_cupon(cupon(['minimo' => 200000]), 100000, 0, 0)[1], 'Compra mínima'), 'cupon: compra mínima');
afirma(mk_cupon(cupon(['usos_max' => 5, 'usos' => 5]), 100000, 0, 0)[1] === 'Cupón agotado', 'cupon: agotado rechazado');
afirma(mk_cupon(cupon(['primera_compra' => 1]), 100000, 0, 2)[1] === 'Este cupón es solo para tu primera compra', 'cupon: primera compra con historial');
afirma(mk_cupon(null, 100000, 0, 0)[1] === 'Cupón no válido', 'cupon: inexistente rechazado');

/* ---------------------- checkout: líneas y stock ------------------------ */
$P = [11 => ['nombre' => 'AGV', 'precio' => 2499000, 'categoria_id' => 'cascos']];
$V = [11 => ['M' => [['id' => 5, 'color' => '', 'sku' => 'MG-11-M', 'stock' => 3]]]];

[$sub, $lineas, $lp] = \App\Services\CheckoutService::validarLineas([['id' => 11, 'qty' => 2, 'talla' => 'M']], $P, $V);
afirma($sub === 4998000, 'checkout: subtotal = precio x cantidad');
afirma(count($lineas) === 1 && count($lp) === 1, 'checkout: una línea por ítem');
afirma(lanza(fn() => \App\Services\CheckoutService::validarLineas([['id' => 99, 'qty' => 1, 'talla' => 'M']], $P, $V)) === 'Un producto de tu carrito ya no está disponible', 'checkout: producto inexistente');
afirma(lanza(fn() => \App\Services\CheckoutService::validarLineas([['id' => 11, 'qty' => 0, 'talla' => 'M']], $P, $V)) === 'Cantidad no válida', 'checkout: cantidad 0 rechazada');
afirma(str_starts_with((string)lanza(fn() => \App\Services\CheckoutService::validarLineas([['id' => 11, 'qty' => 1, 'talla' => 'XXL']], $P, $V)), 'Elige una talla válida'), 'checkout: talla inexistente');
afirma(str_starts_with((string)lanza(fn() => \App\Services\CheckoutService::validarLineas([['id' => 11, 'qty' => 4, 'talla' => 'M']], $P, $V)), 'Stock insuficiente'), 'checkout: stock insuficiente');
afirma(lanza(fn() => \App\Services\CheckoutService::validarLineas([['id' => 11, 'qty' => 2, 'talla' => 'M'], ['id' => 11, 'qty' => 2, 'talla' => 'M']], $P, $V)) !== null, 'checkout: sumar líneas no supera el stock');

/* -------------------------- checkout: datos ----------------------------- */
$valido = ['nombre' => 'Juan Perez', 'telefono' => '3001234567', 'ciudad' => 'Bogota', 'direccion' => 'Calle 1 #2-3', 'email' => 'a@b.co'];
afirma(\App\Services\CheckoutService::validarDatos($valido)['mail'] === 'a@b.co', 'checkout: datos válidos');
afirma(lanza(fn() => \App\Services\CheckoutService::validarDatos(array_merge($valido, ['web' => 'bot']))) === 'Solicitud rechazada', 'checkout: campo trampa rellenado');
afirma(lanza(fn() => \App\Services\CheckoutService::validarDatos(array_merge($valido, ['nombre' => 'Jo']))) === 'Escribe tu nombre completo', 'checkout: nombre corto');
afirma(lanza(fn() => \App\Services\CheckoutService::validarDatos(array_merge($valido, ['telefono' => '12']))) === 'Teléfono no válido', 'checkout: teléfono inválido');
afirma(lanza(fn() => \App\Services\CheckoutService::validarDatos(array_diff_key($valido, ['email' => 1]))) !== null, 'checkout: correo obligatorio');
afirma(lanza(fn() => \App\Services\CheckoutService::validarDatos(array_merge($valido, ['email' => 'no-es-correo']))) !== null, 'checkout: correo con formato inválido');

/* ----------------------- máquina de estados ----------------------------- */
afirma(\App\Services\PedidoService::permisoTransicion('pendiente_pago', 'confirmado') === 'pedidos.editar', 'estados: confirmar requiere pedidos.editar');
afirma(\App\Services\PedidoService::permisoTransicion('pendiente_pago', 'cancelado') === 'pedidos.cancelar', 'estados: cancelar requiere pedidos.cancelar');
afirma(\App\Services\PedidoService::permisoTransicion('pendiente_pago', 'entregado') === null, 'estados: no se puede saltar a entregado');
afirma(\App\Services\PedidoService::permisoTransicion('entregado', 'cancelado') === null, 'estados: un pedido entregado no se cancela');
afirma(\App\Services\PedidoService::permisoTransicion('enviado', 'entregado') === 'pedidos.editar', 'estados: enviado -> entregado');

/* ------------- nueva arquitectura: Core + Servicios (fase 1) ------------ */
// Stub de auditoría: en pruebas no hay base de datos. En producción `auditar`
// existe en db.php; aquí la definimos como no-op solo si no está cargada.
if (!function_exists('auditar')) { function auditar() {} }

final class UsuarioRepoFake implements \App\Repositories\Contracts\UsuarioRepositoryInterface {
    /** @var array<int,array<string,mixed>> */
    public array $usuarios;
    public function __construct(array $usuarios = []) { $this->usuarios = $usuarios; }
    public function porUsuario(string $usuario): ?array {
        foreach ($this->usuarios as $u) { if (($u['usuario'] ?? '') === $usuario) return $u; }
        return null;
    }
    public function porId(int $id): ?array { return $this->usuarios[$id] ?? null; }
    public function guardarTotp(int $id, ?string $secreto, bool $activo): void {
        if (!isset($this->usuarios[$id])) { $this->usuarios[$id] = ['usuario' => 'u' . $id]; }
        $this->usuarios[$id]['totp_secreto'] = $secreto;
        $this->usuarios[$id]['totp_activo'] = $activo ? 1 : 0;
    }
    public function tocarUltimoAcceso(int $id): void { $this->usuarios[$id]['ultimo_acceso'] = time(); }
    public function intentosRecientes(string $ip): int { return 0; }
    public function registrarIntento(string $ip): void {}
    public function limpiarIntentos(string $ip): void {}
}

afirma(class_exists('\App\Services\SeguridadService'), 'core: autoloader carga App\\Services\\SeguridadService');

$c = new \App\Core\Container();
$c->set('reloj', fn() => 'ok');
afirma($c->get('reloj') === 'ok', 'core: container resuelve una fábrica');
$c->singleton('contador', fn() => new \stdClass());
afirma($c->get('contador') === $c->get('contador'), 'core: container singleton devuelve la misma instancia');
afirma($c->make(\App\Core\Texto::class) instanceof \App\Core\Texto, 'core: container auto-resuelve una clase sin dependencias');
$c->singleton(\App\Repositories\Contracts\UsuarioRepositoryInterface::class, fn() => new UsuarioRepoFake());
afirma($c->make(\App\Services\SeguridadService::class) instanceof \App\Services\SeguridadService, 'core: container auto-resuelve una clase con dependencias');

afirma(\App\Core\Texto::s('  hola  ', 10) === 'hola', 'core: Texto::s recorta espacios');
afirma(\App\Core\Texto::slug('Cascos Integrales') === 'cascos-integrales', 'core: Texto::slug normaliza');
afirma(\App\Core\Sql::ph(3) === '?,?,?', 'core: Sql::ph genera placeholders');
afirma((new \App\Core\UserError('x')) instanceof \Exception, 'core: UserError extiende Exception');

// TOTP canónico (clase) y delegación de los helpers globales.
$secreto2 = random_bytes(20);
afirma(\App\Services\SeguridadService::base32Decodificar(\App\Services\SeguridadService::base32Codificar($secreto2)) === $secreto2, 'servicio: base32 ida y vuelta');
$codigo2 = \App\Services\SeguridadService::codigo($secreto2);
afirma(\App\Services\SeguridadService::verificar($secreto2, $codigo2), 'servicio: acepta el código vigente');
afirma(!\App\Services\SeguridadService::verificar($secreto2, $codigo2 === '000000' ? '111111' : '000000'), 'servicio: rechaza código incorrecto');

// Sesión en memoria (abstracción de $_SESSION).
$datosSesion = [];
$sesion = new \App\Core\Sesion($datosSesion);
$sesion->set('uid', 5);
afirma($sesion->get('uid') === 5, 'sesion: set/get funciona');
$sesion->remove('uid');
afirma($sesion->get('uid') === null, 'sesion: remove elimina');
$sesion->set('a', 1); $sesion->clear();
afirma($sesion->get('a') === null, 'sesion: clear vacía');

// Ciclo de vida del 2FA con repositorio en memoria.
$fake = new UsuarioRepoFake([7 => ['usuario' => 'Admin_MG', 'totp_secreto' => null, 'totp_activo' => 0]]);
$seg = new \App\Services\SeguridadService($fake);
$gen = $seg->generar(7);
afirma(str_starts_with($gen['url'], 'otpauth://totp/MotoGear:Admin_MG'), 'seguridad: generar devuelve la URI otpauth');
$secretoGuardado = $fake->usuarios[7]['totp_secreto'];
afirma(is_string($secretoGuardado) && strlen($secretoGuardado) === 20, 'seguridad: generar guarda un secreto de 20 bytes');
afirma($seg->estado(7)['activo'] === false, 'seguridad: estado inicial inactivo');
$seg->activar(7, \App\Services\SeguridadService::codigo((string)$secretoGuardado));
afirma($fake->usuarios[7]['totp_activo'] === 1, 'seguridad: activar marca el 2FA como activo');
afirma($seg->estado(7)['activo'] === true, 'seguridad: estado activo tras activar');
$seg->desactivar(7);
afirma($fake->usuarios[7]['totp_secreto'] === null && $fake->usuarios[7]['totp_activo'] === 0, 'seguridad: desactivar limpia secreto y estado');

/* ------------------------------ resultado ------------------------------- */
$total = $ok + count($fallos);
echo "Pruebas: $total  OK: $ok  Fallos: " . count($fallos) . "\n";
foreach ($fallos as $f) echo "  - FALLA: $f\n";
exit($fallos ? 1 : 0);
