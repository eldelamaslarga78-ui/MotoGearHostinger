/* Vista · Mensajes de contacto (migrada del panel anterior). */

import { api } from '../api.js';
import { toast } from '../ui/toast.js';
import { esc, numero } from '../formato.js';
import { panelError, vacio } from '../ui/skeleton.js';

const LIMITE = 20;
const estado = { pagina: 1 };
let raiz = null;

function cabecera() {
  return `<div class="barra-vista"><div class="titulo-bloque"><h2>Mensajes</h2><p class="nota" id="msg-contador">Cargando…</p></div></div>`;
}

function tarjeta(m) {
  const tel = m.telefono ? ` · ${esc(m.telefono)}` : '';
  return `<article class="tarjeta-simple" data-id="${m.id}">
    <div class="tarjeta-cab"><h3>${esc(m.nombre)}</h3><span class="nota">${esc(String(m.creado).slice(0, 16))}</span></div>
    <p class="nota">${esc(m.asunto || 'Sin asunto')} · ${esc(m.email)}${tel}</p>
    <p>${esc(m.mensaje)}</p>
    <div class="ficha-pie">
      <a class="btn sec mini" href="mailto:${esc(m.email)}?subject=${encodeURIComponent('Re: ' + (m.asunto || 'Tu mensaje a MotoGear'))}">Responder</a>
      <button class="btn mini" type="button" data-accion="borrar" data-id="${m.id}">Eliminar</button>
    </div>
  </article>`;
}

function paginacion(d) {
  return `<div class="paginacion">
    <span>${numero(d.total)} mensajes</span>
    <button class="btn sec mini" type="button" data-accion="ant" ${d.pagina <= 1 ? 'disabled' : ''}>Anterior</button>
    <button class="btn sec mini" type="button" data-accion="sig" ${d.pagina >= d.paginas ? 'disabled' : ''}>Siguiente</button>
  </div>`;
}

async function cargar() {
  const c = raiz.querySelector('#msg-cuerpo');
  c.innerHTML = '<div class="esqueleto"><div class="esqueleto-linea"></div><div class="esqueleto-linea"></div></div>';
  try {
    const d = await api('mensajes', { query: { pagina: estado.pagina, limite: LIMITE } });
    raiz.querySelector('#msg-contador').textContent = `${numero(d.total)} mensaje${d.total === 1 ? '' : 's'}`;
    c.innerHTML = d.items.length
      ? `<div class="lista-mensajes">${d.items.map(tarjeta).join('')}</div>${paginacion(d)}`
      : vacio('Sin mensajes', 'Los mensajes del formulario de contacto aparecen aquí.');
  } catch (e) {
    c.innerHTML = panelError(esc(e.message));
  }
}

function conectar() {
  raiz.querySelector('#msg-cuerpo').addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-accion]');
    if (!btn) return;
    const a = btn.dataset.accion;
    if (a === 'ant' && estado.pagina > 1) { estado.pagina--; cargar(); return; }
    if (a === 'sig') { estado.pagina++; cargar(); return; }
    if (a === 'borrar') {
      if (!confirm('¿Eliminar este mensaje? No se puede deshacer.')) return;
      try {
        await api('borrar', { cuerpo: { tipo: 'mensaje', id: btn.dataset.id } });
        toast('Mensaje eliminado');
        cargar();
      } catch (err) { toast(err.message, 'error'); }
    }
  });
}

export default {
  titulo: 'Mensajes',
  permiso: 'mensajes.ver',
  async montar(el) {
    raiz = el; estado.pagina = 1;
    raiz.innerHTML = cabecera() + '<div id="msg-cuerpo"></div>';
    conectar();
    await cargar();
  },
  desmontar() { raiz = null; },
};
