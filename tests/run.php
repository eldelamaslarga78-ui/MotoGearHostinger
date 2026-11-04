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
require $base . '/src/Seguridad.php';
require $base . '/src/Pedidos.php';
require $base . '/src/Checkout.php';

$ok = 0;
$fallos = [];

function afirma(bool $cond, string $nombre): void {
    global $ok, $fallos;
    if ($cond) { $ok++; } else { $fallos[] = $nombre; }
}

/** Devuelve el mensaje de UserError si la función lo lanza; null si no lanza. */
function lanza(callable $f): ?string {
    try { $f(); return null; } catch (UserError $e) { return $e->getMessage(); }
}

/* ------------------------------ utilidades ------------------------------ */
afirma(s('  hola  ', 10) === 'hola', 'util: s() recorta espacios');
afirma(s('abcdef', 3) === 'abc', 'util: s() respeta el máximo');
afirma(slug('Cascos Integrales') === 'cascos-integrales', 'util: slug() normaliza');
afirma(req(true, 'x') === null, 'util: req() no lanza si la condición es verdadera');
afirma(lanza(fn() => req(false, 'msg')) === 'msg', 'util: req() lanza UserError con el mensaje');

/* ----------------------------- TOTP (2FA) ------------------------------- */
$secreto = random_bytes(20);
afirma(totp_base32_decode(totp_base32_encode($secreto)) === $secreto, 'totp: base32 ida y vuelta');
$codigo = totp_codigo($secreto);
afirma(totp_verificar($secreto, $codigo), 'totp: acepta el código vigente');
afirma(!totp_verificar($secreto, $codigo === '000000' ? '111111' : '000000'), 'totp: rechaza un código incorrecto');
afirma(!totp_verificar($secreto, '12345'), 'totp: rechaza un código de longitud incorrecta');

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

[$sub, $lineas, $lp] = chk_validar_lineas([['id' => 11, 'qty' => 2, 'talla' => 'M']], $P, $V);
afirma($sub === 4998000, 'checkout: subtotal = precio x cantidad');
afirma(count($lineas) === 1 && count($lp) === 1, 'checkout: una línea por ítem');
afirma(lanza(fn() => chk_validar_lineas([['id' => 99, 'qty' => 1, 'talla' => 'M']], $P, $V)) === 'Un producto de tu carrito ya no está disponible', 'checkout: producto inexistente');
afirma(lanza(fn() => chk_validar_lineas([['id' => 11, 'qty' => 0, 'talla' => 'M']], $P, $V)) === 'Cantidad no válida', 'checkout: cantidad 0 rechazada');
afirma(str_starts_with((string)lanza(fn() => chk_validar_lineas([['id' => 11, 'qty' => 1, 'talla' => 'XXL']], $P, $V)), 'Elige una talla válida'), 'checkout: talla inexistente');
afirma(str_starts_with((string)lanza(fn() => chk_validar_lineas([['id' => 11, 'qty' => 4, 'talla' => 'M']], $P, $V)), 'Stock insuficiente'), 'checkout: stock insuficiente');
afirma(lanza(fn() => chk_validar_lineas([['id' => 11, 'qty' => 2, 'talla' => 'M'], ['id' => 11, 'qty' => 2, 'talla' => 'M']], $P, $V)) !== null, 'checkout: sumar líneas no supera el stock');

/* -------------------------- checkout: datos ----------------------------- */
$valido = ['nombre' => 'Juan Perez', 'telefono' => '3001234567', 'ciudad' => 'Bogota', 'direccion' => 'Calle 1 #2-3', 'email' => 'a@b.co'];
afirma(chk_validar_datos($valido)['mail'] === 'a@b.co', 'checkout: datos válidos');
afirma(lanza(fn() => chk_validar_datos(array_merge($valido, ['web' => 'bot']))) === 'Solicitud rechazada', 'checkout: campo trampa rellenado');
afirma(lanza(fn() => chk_validar_datos(array_merge($valido, ['nombre' => 'Jo']))) === 'Escribe tu nombre completo', 'checkout: nombre corto');
afirma(lanza(fn() => chk_validar_datos(array_merge($valido, ['telefono' => '12']))) === 'Teléfono no válido', 'checkout: teléfono inválido');
afirma(lanza(fn() => chk_validar_datos(array_diff_key($valido, ['email' => 1]))) !== null, 'checkout: correo obligatorio');
afirma(lanza(fn() => chk_validar_datos(array_merge($valido, ['email' => 'no-es-correo']))) !== null, 'checkout: correo con formato inválido');

/* ----------------------- máquina de estados ----------------------------- */
afirma(ped_permiso_transicion('pendiente_pago', 'confirmado') === 'pedidos.editar', 'estados: confirmar requiere pedidos.editar');
afirma(ped_permiso_transicion('pendiente_pago', 'cancelado') === 'pedidos.cancelar', 'estados: cancelar requiere pedidos.cancelar');
afirma(ped_permiso_transicion('pendiente_pago', 'entregado') === null, 'estados: no se puede saltar a entregado');
afirma(ped_permiso_transicion('entregado', 'cancelado') === null, 'estados: un pedido entregado no se cancela');
afirma(ped_permiso_transicion('enviado', 'entregado') === 'pedidos.editar', 'estados: enviado -> entregado');

/* ------------------------------ resultado ------------------------------- */
$total = $ok + count($fallos);
echo "Pruebas: $total  OK: $ok  Fallos: " . count($fallos) . "\n";
foreach ($fallos as $f) echo "  - FALLA: $f\n";
exit($fallos ? 1 : 0);
