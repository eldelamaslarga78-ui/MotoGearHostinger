/* Vista · Auditoría: historial de cambios (solo lectura). */

import { api } from '../api.js';
import { esc, fechaHora, numero } from '../formato.js';
import { panelError, vacio } from '../ui/skeleton.js';

const LIMITE = 30;
const estado = { entidad: '', pagina: 1 };
let raiz = null;

function cabecera() {
  return `<div class="barra-vista"><div class="titulo-bloque"><h2>Auditoría</h2><p class="nota">Quién cambió qué y cuándo</p></div></div>`;
}

function filtro(entidades) {
  const opciones = entidades.map((x) => `<option value="${esc(x)}"${estado.entidad === x ? ' selected' : ''}>${esc(x)}</option>`).join('');
  return `<div class="herramientas">
    <label class="oculto-visual" for="au-entidad">Entidad</label>
    <select class="campo" id="au-entidad"><option value="">Todas las entidades</option>${opciones}</select>
  </div>`;
}

function resumenCambios(c) {
  if (!c) return '—';
  return esc(Object.entries(c).map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`).join(' · '));
}

async function cargar() {
  const c = raiz.querySelector('#au-cuerpo');
  c.innerHTML = '<div class="esqueleto"><div class="esqueleto-linea"></div><div class="esqueleto-linea"></div></div>';
  try {
    const d = await api('auditoria', { query: { entidad: estado.entidad, pagina: estado.pagina, limite: LIMITE } });
    raiz.querySelector('#au-filtro').innerHTML = filtro(d.entidades);
    raiz.querySelector('#au-filtro select').addEventListener('change', (e) => { estado.entidad = e.target.value; estado.pagina = 1; cargar(); });
    if (!d.items.length) { c.innerHTML = vacio('Sin registros'); return; }
    const filas = d.items.map((r) => `<tr>
      <td data-etiqueta="Fecha">${esc(fechaHora(r.creado))}</td>
      <td data-etiqueta="Usuario">${esc(r.usuario)}</td>
      <td data-etiqueta="Acción">${esc(r.accion)}</td>
      <td data-etiqueta="Entidad">${esc(r.entidad)}${r.entidad_id ? ' #' + esc(r.entidad_id) : ''}</td>
      <td data-etiqueta="Cambios">${resumenCambios(r.cambios)}</td>
      <td data-etiqueta="IP">${esc(r.ip || '—')}</td>
    </tr>`).join('');
    c.innerHTML = `<div class="tabla-wrap"><table class="tabla">
      <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Entidad</th><th>Cambios</th><th>IP</th></tr></thead>
      <tbody>${filas}</tbody></table></div>
      <div class="paginacion">
        <span>${numero(d.total)} registros</span>
        <button class="btn sec mini" type="button" data-accion="ant" ${d.pagina <= 1 ? 'disabled' : ''}>Anterior</button>
        <button class="btn sec mini" type="button" data-accion="sig" ${d.pagina >= d.paginas ? 'disabled' : ''}>Siguiente</button>
      </div>`;
  } catch (e) {
    c.innerHTML = panelError(esc(e.message));
  }
}

function conectar() {
  raiz.querySelector('#au-cuerpo').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-accion]');
    if (!btn) return;
    if (btn.dataset.accion === 'ant' && estado.pagina > 1) { estado.pagina--; cargar(); }
    if (btn.dataset.accion === 'sig') { estado.pagina++; cargar(); }
  });
}

export default {
  titulo: 'Auditoría',
  permiso: 'auditoria.ver',
  async montar(el) {
    raiz = el; estado.entidad = ''; estado.pagina = 1;
    raiz.innerHTML = cabecera() + '<div id="au-filtro"></div><div class="tarjeta" id="au-cuerpo"></div>';
    conectar();
    await cargar();
  },
  desmontar() { raiz = null; },
};
