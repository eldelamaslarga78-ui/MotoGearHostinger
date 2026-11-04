/* Vista · Configuración: ajustes del carrito y datos del correo (sin secretos). */

import { api } from '../api.js';
import { toast } from '../ui/toast.js';
import { esc } from '../formato.js';
import { panelError } from '../ui/skeleton.js';

let raiz = null;

function cabecera() {
  return `<div class="barra-vista"><div class="titulo-bloque"><h2>Configuración</h2><p class="nota">Ajustes de la tienda</p></div></div>`;
}

function render(d) {
  const editables = d.editables.map((c) => `<div class="campo-bloque">
      <label for="cf-${esc(c.clave)}">${esc(c.etiqueta)}</label>
      <input class="campo" id="cf-${esc(c.clave)}" data-clave="${esc(c.clave)}" type="number" min="${c.min}" max="${c.max}" value="${esc(c.valor)}" required>
    </div>`).join('');
  const lectura = d.lectura.map((c) => `<div class="linea-dato"><span>${esc(c.clave)}</span><span>${esc(c.valor)}</span></div>`).join('');
  raiz.querySelector('#cf-cuerpo').innerHTML = `
    <form class="tarjeta-simple tarjeta-form" id="cf-form">
      <h3>Carrito y recuperación</h3>
      <div class="form-grid">${editables}</div>
      <div class="ficha-pie"><button class="btn mini" type="submit">Guardar</button></div>
    </form>
    <div class="tarjeta-simple tarjeta-form">
      <h3>Correo de la tienda</h3>
      <p class="nota">Solo lectura. La contraseña SMTP está en config.local.php y nunca se muestra aquí.</p>
      ${lectura}
    </div>`;
}

async function cargar() {
  try {
    render(await api('configuracion'));
  } catch (e) {
    raiz.querySelector('#cf-cuerpo').innerHTML = panelError(esc(e.message));
  }
}

function conectar() {
  raiz.addEventListener('submit', async (e) => {
    if (e.target.id !== 'cf-form') return;
    e.preventDefault();
    const data = {};
    e.target.querySelectorAll('[data-clave]').forEach((el) => { data[el.dataset.clave] = Number(el.value); });
    try {
      await api('configuracion_guardar', { cuerpo: { data } });
      toast('Configuración guardada');
      cargar();
    } catch (err) { toast(err.message, 'error'); }
  });
}

export default {
  titulo: 'Configuración',
  permiso: 'config.editar',
  async montar(el) {
    raiz = el;
    raiz.innerHTML = cabecera() + '<div id="cf-cuerpo"></div>';
    conectar();
    await cargar();
  },
  desmontar() { raiz = null; },
};
