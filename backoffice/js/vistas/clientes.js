/* Vista · Clientes (Fase 4): listado + ficha 360. */

import { api } from '../api.js';
import { toast } from '../ui/toast.js';
import { esc, money, numero } from '../formato.js';
import { panelError, vacio } from '../ui/skeleton.js';

const LIMITE = 20;
const SEGMENTOS = [
  ['', 'Todos'], ['sin_compras', 'Sin compras'], ['nuevo', 'Nuevo'],
  ['recurrente', 'Recurrente'], ['vip', 'VIP'], ['inactivo', 'Inactivo'],
];
const SEG_LABEL = { sin_compras: 'Sin compras', nuevo: 'Nuevo', recurrente: 'Recurrente', vip: 'VIP', inactivo: 'Inactivo' };

const estado = { q: '', segmento: '', pagina: 1 };
let raiz = null;
let relojBusqueda = null;
let drawerId = null;
let detalle = null;

function cabecera() {
  return `<div class="barra-vista"><div class="titulo-bloque"><h2>Clientes</h2><p class="nota" id="cli-contador">Cargando…</p></div></div>`;
}

function filtros() {
  const seg = SEGMENTOS.map(([v, t]) => `<button type="button" data-val="${v}" aria-pressed="${estado.segmento === v}">${t}</button>`).join('');
  return `<div class="herramientas">
    <label class="oculto-visual" for="cli-q">Buscar clientes</label>
    <input class="campo buscador" id="cli-q" type="search" placeholder="Nombre, correo, teléfono o documento" value="${esc(estado.q)}">
    <div class="segmentado" role="group">${seg}</div>
  </div>`;
}

function fila(c) {
  return `<tr class="fila-cliente" data-id="${c.id}" role="button" tabindex="0">
    <td data-etiqueta="Cliente">${esc(c.nombre)}${c.email ? `<div class="sku">${esc(c.email)}</div>` : ''}</td>
    <td data-etiqueta="Teléfono">${esc(c.telefono || '—')}</td>
    <td class="num" data-etiqueta="Pedidos">${numero(c.total_pedidos)}</td>
    <td class="num" data-etiqueta="Gasto">${money(c.total_gastado)}</td>
    <td data-etiqueta="Segmento"><span class="estado">${esc(SEG_LABEL[c.segmento] || c.segmento)}</span></td>
  </tr>`;
}

async function cargar() {
  const c = raiz.querySelector('#cli-cuerpo');
  c.innerHTML = '<div class="esqueleto"><div class="esqueleto-linea"></div></div>';
  try {
    const d = await api('clientes', { query: { q: estado.q, segmento: estado.segmento, pagina: estado.pagina, limite: LIMITE } });
    raiz.querySelector('#cli-contador').textContent = `${numero(d.total)} cliente${d.total === 1 ? '' : 's'}`;
    c.innerHTML = d.items.length
      ? `<div class="tabla-wrap"><table class="tabla"><thead><tr><th>Cliente</th><th>Teléfono</th><th class="num">Pedidos</th><th class="num">Gasto</th><th>Segmento</th></tr></thead><tbody>${d.items.map(fila).join('')}</tbody></table></div>`
      : vacio('Sin clientes', 'Los clientes aparecen al crear pedidos.');
  } catch (e) {
    c.innerHTML = panelError(esc(e.message));
  }
}

function abrir(id) {
  drawerId = id; detalle = null; renderDrawer();
  api('cliente_detalle', { query: { id } }).then((d) => { detalle = d; renderDrawer(); })
    .catch((e) => { raiz.querySelector('.drawer-cuerpo').innerHTML = panelError(esc(e.message)); });
}
function cerrar() { drawerId = null; detalle = null; raiz.querySelector('#drawer').innerHTML = ''; }

function renderDrawer() {
  const d = raiz.querySelector('#drawer');
  if (!drawerId) { d.innerHTML = ''; return; }
  if (!detalle) {
    d.innerHTML = `<div class="drawer"><div class="drawer-cab"><div class="titulo"><h2>Cargando…</h2></div></div><div class="drawer-cuerpo"><div class="esqueleto"><div class="esqueleto-linea"></div></div></div></div>`;
    return;
  }
  const cl = detalle.cliente;
  const dirs = detalle.direcciones.map((x) => `<div class="linea-dato"><span>${esc(x.alias)}</span><span>${esc(x.ciudad)}, ${esc(x.direccion)}${x.principal ? ' ★' : ''}</span></div>`).join('') || '<p class="nota">Sin direcciones.</p>';
  const peds = detalle.pedidos.map((p) => `<div class="linea-dato"><span>#${p.id} · ${esc(p.estado)}</span><span>${money(p.total)}</span></div>`).join('') || '<p class="nota">Sin pedidos.</p>';
  d.innerHTML = `<div class="drawer-velo" data-accion="cerrar"></div><div class="drawer">
    <div class="drawer-cab"><div class="titulo"><h2>${esc(cl.nombre)}</h2><p class="nota">${esc(cl.email || cl.telefono || '')} · ${esc(SEG_LABEL[cl.segmento] || cl.segmento)}</p></div>
      <button class="icono-btn" data-accion="cerrar" aria-label="Cerrar">✕</button></div>
    <div class="drawer-cuerpo">
      <div class="tarjeta"><div class="tarjeta-cab"><h3>Datos</h3></div>
        <div class="form-grid">
          <div class="campo-bloque"><label>Nombre</label><input class="campo" id="c-nombre" value="${esc(cl.nombre)}"></div>
          <div class="campo-bloque"><label>Email</label><input class="campo" id="c-email" value="${esc(cl.email || '')}"></div>
          <div class="campo-bloque"><label>Teléfono</label><input class="campo" id="c-telefono" value="${esc(cl.telefono || '')}"></div>
          <div class="campo-bloque"><label>Notas</label><textarea class="campo" id="c-notas">${esc(cl.notas || '')}</textarea></div>
          <div class="campo-bloque"><label><input type="checkbox" id="c-marketing" ${cl.acepta_marketing ? 'checked' : ''}> Acepta marketing</label></div>
        </div>
        <button class="btn mini" type="button" data-accion="guardar">Guardar datos</button>
      </div>
      <div class="tarjeta"><div class="tarjeta-cab"><h3>Direcciones</h3></div>${dirs}</div>
      <div class="tarjeta"><div class="tarjeta-cab"><h3>Pedidos</h3><span class="nota">Gasto: ${money(cl.total_gastado)}</span></div>${peds}</div>
      <div class="tarjeta"><div class="tarjeta-cab"><h3>Fusionar</h3></div>
        <div class="herramientas"><input class="campo" id="c-fusionar" type="number" placeholder="ID del cliente origen">
        <button class="btn sec mini" type="button" data-accion="fusionar">Fusionar en este cliente</button></div>
      </div>
    </div></div>`;
}

function conectar() {
  raiz.querySelector('#cli-q').addEventListener('input', (e) => {
    clearTimeout(relojBusqueda);
    relojBusqueda = setTimeout(() => { estado.q = e.target.value.trim(); estado.pagina = 1; cargar(); }, 250);
  });
  raiz.querySelector('.segmentado').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-val]'); if (!b) return;
    estado.segmento = b.dataset.val; estado.pagina = 1;
    raiz.querySelectorAll('.segmentado button').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    cargar();
  });
  raiz.querySelector('#cli-cuerpo').addEventListener('click', (e) => {
    const f = e.target.closest('.fila-cliente'); if (f) abrir(Number(f.dataset.id));
  });
  raiz.querySelector('#drawer').addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-accion]'); if (!btn) return;
    const a = btn.dataset.accion;
    if (a === 'cerrar') { cerrar(); return; }
    if (a === 'guardar') {
      try {
        await api('cliente_guardar', { cuerpo: { data: { id: drawerId, nombre: raiz.querySelector('#c-nombre').value, email: raiz.querySelector('#c-email').value, telefono: raiz.querySelector('#c-telefono').value, notas: raiz.querySelector('#c-notas').value, acepta_marketing: raiz.querySelector('#c-marketing').checked } } });
        toast('Cliente guardado'); cerrar(); cargar();
      } catch (err) { toast(err.message, 'error'); }
      return;
    }
    if (a === 'fusionar') {
      try {
        const origen = Number(raiz.querySelector('#c-fusionar').value || 0);
        await api('cliente_fusionar', { cuerpo: { origen, destino: drawerId } });
        toast('Clientes fusionados'); cerrar(); cargar();
      } catch (err) { toast(err.message, 'error'); }
      return;
    }
  });
}

export default {
  titulo: 'Clientes', permiso: 'clientes.ver',
  async montar(el) {
    raiz = el; estado.q = ''; estado.segmento = ''; estado.pagina = 1; drawerId = null;
    raiz.innerHTML = cabecera() + filtros() + '<div class="tarjeta" id="cli-cuerpo"></div><div id="drawer"></div>';
    conectar(); await cargar();
  },
  desmontar() { clearTimeout(relojBusqueda); raiz = null; drawerId = null; detalle = null; },
};
