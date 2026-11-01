/* Vista · Devoluciones (Fase 4): lista de RMA + gestión. */

import { api } from '../api.js';
import { toast } from '../ui/toast.js';
import { esc, money } from '../formato.js';
import { panelError, vacio } from '../ui/skeleton.js';

const ESTADOS = [
  ['', 'Todas'], ['solicitada', 'Solicitada'], ['aprobada', 'Aprobada'],
  ['rechazada', 'Rechazada'], ['recibida', 'Recibida'], ['reembolsada', 'Reembolsada'],
];
const MOTIVOS = [['talla', 'Talla'], ['defecto', 'Defecto'], ['no_corresponde', 'No corresponde'], ['arrepentimiento', 'Arrepentimiento'], ['otro', 'Otro']];

const estado = { filtro: '', pagina: 1 };
let raiz = null;
let drawerId = null;
let detalle = null;

function cabecera() {
  return `<div class="barra-vista"><div class="titulo-bloque"><h2>Devoluciones</h2><p class="nota" id="dev-contador">Cargando…</p></div></div>`;
}

function filtros() {
  const seg = ESTADOS.map(([v, t]) => `<button type="button" data-val="${v}" aria-pressed="${estado.filtro === v}">${t}</button>`).join('');
  return `<div class="herramientas"><div class="segmentado" role="group">${seg}</div></div>`;
}

function fila(d) {
  return `<tr class="fila-dev" data-id="${d.id}" role="button" tabindex="0">
    <td data-etiqueta="Devolución">#${d.id}</td>
    <td data-etiqueta="Pedido">#${d.pedido_id}</td>
    <td data-etiqueta="Cliente">${esc(d.cliente)}</td>
    <td data-etiqueta="Motivo">${esc(d.motivo)}</td>
    <td class="num" data-etiqueta="Monto">${money(d.monto)}</td>
    <td data-etiqueta="Estado">${esc(d.estado)}</td>
  </tr>`;
}

async function cargar() {
  const c = raiz.querySelector('#dev-cuerpo');
  c.innerHTML = '<div class="esqueleto"><div class="esqueleto-linea"></div></div>';
  try {
    const d = await api('devoluciones', { query: { estado: estado.filtro, pagina: estado.pagina, limite: 50 } });
    raiz.querySelector('#dev-contador').textContent = `${d.total} devolución${d.total === 1 ? '' : 'es'}`;
    c.innerHTML = d.items.length
      ? `<div class="tabla-wrap"><table class="tabla"><thead><tr><th>#</th><th>Pedido</th><th>Cliente</th><th>Motivo</th><th class="num">Monto</th><th>Estado</th></tr></thead><tbody>${d.items.map(fila).join('')}</tbody></table></div>`
      : vacio('Sin devoluciones');
  } catch (e) {
    c.innerHTML = panelError(esc(e.message));
  }
}

function abrir(id) {
  drawerId = id; detalle = null; renderDrawer();
  api('devoluciones', { query: { estado: '', pagina: 1, limite: 100 } }).then((d) => {
    detalle = d.items.find((x) => x.id === id) || null; renderDrawer();
  });
}
function cerrar() { drawerId = null; detalle = null; raiz.querySelector('#drawer').innerHTML = ''; }

const TRANSICIONES = { solicitada: ['aprobada', 'rechazada'], aprobada: ['recibida'], recibida: ['reembolsada'] };

function renderDrawer() {
  const d = raiz.querySelector('#drawer');
  if (!drawerId) { d.innerHTML = ''; return; }
  if (!detalle) { d.innerHTML = '<div class="drawer"><div class="drawer-cab"><h2>Cargando…</h2></div></div>'; return; }
  const botones = (TRANSICIONES[detalle.estado] || []).map((t) => `<button class="btn mini" type="button" data-accion="transicion" data-estado="${t}">${t}</button>`).join('');
  d.innerHTML = `<div class="drawer-velo" data-accion="cerrar"></div><div class="drawer">
    <div class="drawer-cab"><div class="titulo"><h2>Devolución #${detalle.id}</h2><p class="nota">Pedido #${detalle.pedido_id} · ${esc(detalle.cliente)}</p></div>
      <button class="icono-btn" data-accion="cerrar" aria-label="Cerrar">✕</button></div>
    <div class="drawer-cuerpo">
      <div class="tarjeta"><div class="tarjeta-cab"><h3>Detalle</h3></div>
        <div class="linea-dato"><span>Motivo</span><span>${esc(detalle.motivo)}</span></div>
        <div class="linea-dato"><span>Monto</span><span>${money(detalle.monto)}</span></div>
        <div class="linea-dato"><span>Estado</span><span>${esc(detalle.estado)}</span></div>
        <div class="linea-dato"><span>Creada</span><span>${esc(String(detalle.creado).slice(0, 16))}</span></div>
      </div>
    </div>
    <div class="drawer-pie">${botones}</div></div>`;
}

function conectar() {
  raiz.querySelector('.segmentado').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-val]'); if (!b) return;
    estado.filtro = b.dataset.val; estado.pagina = 1;
    raiz.querySelectorAll('.segmentado button').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    cargar();
  });
  raiz.querySelector('#dev-cuerpo').addEventListener('click', (e) => {
    const f = e.target.closest('.fila-dev'); if (f) abrir(Number(f.dataset.id));
  });
  raiz.querySelector('#drawer').addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-accion]'); if (!btn) return;
    if (btn.dataset.accion === 'cerrar') { cerrar(); return; }
    if (btn.dataset.accion === 'transicion') {
      try { await api('devolucion_transicion', { cuerpo: { id: drawerId, estado: btn.dataset.estado } }); toast('Estado actualizado'); cerrar(); cargar(); }
      catch (err) { toast(err.message, 'error'); }
    }
  });
}

export default {
  titulo: 'Devoluciones', permiso: 'devoluciones.ver',
  async montar(el) {
    raiz = el; estado.filtro = ''; estado.pagina = 1; drawerId = null;
    raiz.innerHTML = cabecera() + filtros() + '<div class="tarjeta" id="dev-cuerpo"></div><div id="drawer"></div>';
    conectar(); await cargar();
  },
  desmontar() { raiz = null; drawerId = null; detalle = null; },
};
