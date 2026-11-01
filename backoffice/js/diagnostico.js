/* Modo diagnóstico: se activa solo con ?diagnostico=1.
 *
 * Sirve para ver en pantalla lo que normalmente solo aparece en las DevTools:
 * cada llamada a la API con su estado HTTP, el cuerpo de las respuestas que
 * fallan y los errores de JavaScript.
 *
 * No se carga si el parámetro no está presente: app.js lo importa en caliente.
 */

const MAXIMO = 60;
let lista = null;
let cuenta = null;
let entradas = 0;

function texto(valor) {
  if (typeof valor === 'string') return valor;
  try { return JSON.stringify(valor); } catch { return String(valor); }
}

function hora() {
  const d = new Date();
  const dos = (n) => String(n).padStart(2, '0');
  return dos(d.getHours()) + ':' + dos(d.getMinutes()) + ':' + dos(d.getSeconds());
}

function registrar(tipo, titulo, cuerpo = '') {
  if (!lista) return;
  const fila = document.createElement('li');
  fila.className = 'diag-item diag-' + tipo;

  const meta = document.createElement('div');
  meta.className = 'diag-meta';
  meta.textContent = hora() + ' · ' + titulo;
  fila.append(meta);

  if (cuerpo) {
    const detalle = document.createElement('pre');
    detalle.className = 'diag-cuerpo';
    detalle.textContent = cuerpo.slice(0, 800);
    fila.append(detalle);
  }

  lista.append(fila);
  entradas += 1;
  while (lista.children.length > MAXIMO) lista.firstElementChild.remove();
  if (cuenta) cuenta.textContent = String(entradas);
}

function copiar() {
  const salida = [...lista.querySelectorAll('li')]
    .map((li) => li.innerText.replace(/\s+/g, ' ').trim())
    .join('\n');
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(salida).then(
      () => registrar('ok', 'Diagnóstico copiado al portapapeles'),
      () => registrar('error', 'No se pudo copiar; selecciona el texto a mano'),
    );
  } else {
    registrar('error', 'El navegador no permite copiar; selecciona el texto a mano');
  }
}

function montar() {
  const panel = document.createElement('aside');
  panel.className = 'diag';
  panel.setAttribute('role', 'region');
  panel.setAttribute('aria-label', 'Diagnóstico de la API');

  const cab = document.createElement('header');
  cab.className = 'diag-cab';

  const titulo = document.createElement('strong');
  titulo.textContent = 'Diagnóstico';

  cuenta = document.createElement('span');
  cuenta.className = 'diag-cuenta';
  cuenta.textContent = '0';
  cuenta.title = 'Llamadas y errores registrados';

  const acciones = [
    ['copiar', 'Copiar'],
    ['limpiar', 'Limpiar'],
    ['cerrar', 'Cerrar'],
  ].map(([accion, etiqueta]) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'diag-btn';
    b.dataset.accion = accion;
    b.textContent = etiqueta;
    return b;
  });

  cab.append(titulo, cuenta, ...acciones);

  lista = document.createElement('ol');
  lista.className = 'diag-lista';

  panel.append(cab, lista);
  document.body.append(panel);

  panel.addEventListener('click', (e) => {
    const b = e.target.closest('[data-accion]');
    if (!b) return;
    if (b.dataset.accion === 'copiar') copiar();
    if (b.dataset.accion === 'limpiar') { lista.textContent = ''; entradas = 0; cuenta.textContent = '0'; }
    if (b.dataset.accion === 'cerrar') panel.remove();
  });
}

function espiarFetch() {
  const original = window.fetch.bind(window);

  window.fetch = async function espiado(...args) {
    const inicio = performance.now();
    const peticion = args[0];
    const url = typeof peticion === 'string' ? peticion : (peticion && peticion.url) || '';
    const metodo = (args[1] && args[1].method) || (peticion && peticion.method) || 'GET';
    const corto = url.replace(/^.*\/api\/api\.php/, 'api.php');

    let res;
    try {
      res = await original(...args);
    } catch (e) {
      registrar('error', metodo + ' ' + corto + ' → ' + (e && e.message ? e.message : 'fallo de red'));
      throw e;
    }

    const ms = Math.round(performance.now() - inicio);
    if (res.ok) {
      registrar('ok', metodo + ' ' + corto + ' → HTTP ' + res.status + ' · ' + ms + ' ms');
      return res;
    }

    let cuerpo = '';
    try {
      cuerpo = await res.clone().text();
    } catch { /* el cuerpo no era legible */ }
    registrar('error', metodo + ' ' + corto + ' → HTTP ' + res.status + ' · ' + ms + ' ms', cuerpo);
    return res;
  };
}

function espiarErrores() {
  window.addEventListener('error', (e) => {
    const lugar = e.filename ? ' (' + e.filename.split('/').pop() + ':' + e.lineno + ')' : '';
    registrar('error', 'Error de JS: ' + (e.message || 'desconocido') + lugar);
  });

  window.addEventListener('unhandledrejection', (e) => {
    const motivo = e.reason && e.reason.message ? e.reason.message : texto(e.reason);
    registrar('error', 'Promesa rechazada sin capturar: ' + motivo);
  });
}

export function iniciarDiagnostico() {
  if (document.querySelector('.diag')) return;
  montar();
  espiarFetch();
  espiarErrores();
  registrar('ok', 'Diagnóstico activo en esta pestaña');
}
