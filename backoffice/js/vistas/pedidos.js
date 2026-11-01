/* Vista · Pedidos (Fase 3): listado + drawer de detalle. */

import { api } from '../api.js';
import { toast } from '../ui/toast.js';
import { esc, money, numero, fechaHora } from '../formato.js';
import { panelError, vacio } from '../ui/skeleton.js';

const LIMITE = 20;

const ESTADOS = [
  ['', 'Todos'],
  ['pendiente_pago', 'Pendiente de pago'],
  ['confirmado', 'Confirmado'],
  ['en_preparacion', 'En preparación'],
  ['enviado', 'Enviado'],
  ['entregado', 'Entregado'],
  ['cancelado', 'Cancelado'],
];

const ETIQUETA = {
  nuevo: 'Nuevo', pendiente_pago: 'Pendiente de pago', confirmado: 'Confirmado',
  en_preparacion: 'En preparación', enviado: 'Enviado', entregado: 'Entregado',
  cancelado: 'Cancelado', devuelto: 'Devuelto',
};

const estado = { q: '', filtro: '', pagina: 1 };
let raiz = null;
let relojBusqueda = null;
let drawerId = null;
let detalle = null;

const chipEstado = (e) => `<span class="estado est-${esc(e)}">${esc(ETIQUETA[e] || e)}</span>`;

/* ------------------------------- listado -------------------------------- */

function cabecera() {
  return `<div class="barra-vista">
    <div class="titulo-bloque">
      <h2>Pedidos</h2>
      <p class="nota" id="pedidos-contador">Cargando…</p>
    </div>
  </div>`;
}

function filtros() {
  const segmentos = ESTADOS.map(([v, t]) =>
    `<button type="button" data-val="${v}" aria-pressed="${estado.filtro === v}">${t}</button>`).join('');
  return `<div class="herramientas">
    <label class="oculto-visual" for="pedidos-q">Buscar pedidos</label>
    <input class="campo buscador" id="pedidos-q" type="search" placeholder="Buscar por nombre, correo, teléfono o número" value="${esc(estado.q)}">
    <div class="segmentado" role="group" aria-label="Estado">${segmentos}</div>
  </div>`;
}

function fila(p) {
  return `<tr data-id="${p.id}" role="button" tabindex="0" class="fila-pedido">
    <td data-etiqueta="Pedido">#${p.id}</td>
    <td data-etiqueta="Cliente">${esc(p.cliente)}</td>
    <td data-etiqueta="Ciudad">${esc(p.ciudad || '—')}</td>
    <td class="num" data-etiqueta="Ítems">${numero(p.n_items)}</td>
    <td class="num" data-etiqueta="Total">${money(p.total)}</td>
    <td data-etiqueta="Estado">${chipEstado(p.estado)}</td>
    <td data-etiqueta="Fecha">${esc(String(p.creado).slice(0, 16))}</td>
  </tr>`;
}

function paginacion(d) {
  const desde = (d.pagina - 1) * d.limite + 1;
  const hasta = Math.min(d.pagina * d.limite, d.total);
  return `<div class="paginacion">
    <span>${d.total === 0 ? '0' : `${desde}–${hasta}`} de ${numero(d.total)}</span>
    <button class="btn sec mini" type="button" data-accion="ant" ${d.pagina <= 1 ? 'disabled' : ''}>Anterior</button>
    <button class="btn sec mini" type="button" data-accion="sig" ${d.pagina >= d.paginas ? 'disabled' : ''}>Siguiente</button>
  </div>`;
}

function cuerpoLista(d) {
  if (!d.items.length) return vacio('Sin pedidos', 'Ajusta la búsqueda o los filtros.');
  return `<div class="tabla-wrap"><table class="tabla">
    <thead><tr><th>#</th><th>Cliente</th><th>Ciudad</th><th class="num">Ítems</th><th class="num">Total</th><th>Estado</th><th>Fecha</th></tr></thead>
    <tbody>${d.items.map(fila).join('')}</tbody></table></div>${paginacion(d)}`;
}

async function cargarLista() {
  if (!raiz) return;
  const c = raiz.querySelector('#pedidos-cuerpo');
  c.innerHTML = '<div class="esqueleto"><div class="esqueleto-linea"></div><div class="esqueleto-linea"></div></div>';
  try {
    const d = await api('pedidos', { query: { q: estado.q, estado: estado.filtro, pagina: estado.pagina, limite: LIMITE } });
    c.innerHTML = cuerpoLista(d);
    raiz.querySelector('#pedidos-contador').textContent = `${numero(d.total)} pedido${d.total === 1 ? '' : 's'}`;
  } catch (e) {
    c.innerHTML = panelError(esc(e.message));
  }
}

/* -------------------------------- drawer -------------------------------- */

function abrirDrawer(id) {
  drawerId = id;
  detalle = null;
  renderDrawer();
  (async () => {
    try {
      detalle = await api('pedido_detalle', { query: { id } });
      renderDrawer();
    } catch (e) {
      raiz.querySelector('.drawer-cuerpo').innerHTML = panelError(esc(e.message));
    }
  })();
}

function cerrarDrawer() {
  drawerId = null;
  detalle = null;
  raiz.querySelector('#drawer').innerHTML = '';
}

function drawerCabecera() {
  if (!detalle) return `<div class="drawer-cab"><div class="titulo"><h2>Cargando…</h2></div><button class="icono-btn" data-accion="cerrar" aria-label="Cerrar">✕</button></div>`;
  const p = detalle.pedido;
  return `<div class="drawer-cab">
    <div class="titulo"><h2>Pedido #${p.id}</h2><p class="nota">${esc(p.cliente_nombre)} · ${money(p.total)}</p></div>
    ${chipEstado(p.estado)}
    <button class="icono-btn" data-accion="cerrar" aria-label="Cerrar">✕</button>
  </div>`;
}

function seccionResumen() {
  const p = detalle.pedido;
  return `<div class="tarjeta">
    <div class="tarjeta-cab"><h3>Resumen</h3></div>
    <div class="linea-dato"><span>Cliente</span><span>${esc(p.cliente_nombre)}</span></div>
    <div class="linea-dato"><span>Correo</span><span>${esc(p.cliente_email || '—')}</span></div>
    <div class="linea-dato"><span>Teléfono</span><span>${esc(p.cliente_tel || '—')}</span></div>
    <div class="linea-dato"><span>Dirección</span><span>${esc(p.envio_ciudad || '')}${p.envio_direccion ? ', ' + esc(p.envio_direccion) : ''}</span></div>
    <div class="linea-dato"><span>Subtotal</span><span>${money(p.subtotal)}</span></div>
    <div class="linea-dato"><span>Descuento</span><span>${money(p.descuento)}</span></div>
    <div class="linea-dato"><span>Total</span><span><strong>${money(p.total)}</strong></span></div>
    <div class="linea-dato"><span>Pago</span><span>${esc(p.pago_estado)} · ${esc(p.metodo_pago)}</span></div>
    <div class="linea-dato"><span>Cupón</span><span>${esc(p.cupon_codigo || '—')}</span></div>
  </div>`;
}

function seccionItems() {
  const filas = detalle.items.map((i) => `<tr>
    <td data-etiqueta="Producto">${esc(i.nombre)}<div class="sku">${esc(i.sku)} · ${esc(i.talla || '—')}${i.color ? ' ' + esc(i.color) : ''}</div></td>
    <td class="num" data-etiqueta="Cantidad">${numero(i.cantidad)}</td>
    <td class="num" data-etiqueta="Precio">${money(i.precio)}</td>
    <td class="num" data-etiqueta="Línea">${money(i.total_linea)}</td></tr>`).join('');
  return `<div class="tarjeta"><div class="tarjeta-cab"><h3>Ítems</h3></div>
    <div class="tabla-wrap"><table class="tabla">
      <thead><tr><th>Producto</th><th class="num">Cant.</th><th class="num">Precio</th><th class="num">Línea</th></tr></thead>
      <tbody>${filas}</tbody></table></div></div>`;
}

function seccionPago() {
  const p = detalle.pedido;
  let accion = '';
  if (p.estado === 'pendiente_pago') {
    accion = `<div class="herramientas">
      <select class="campo" id="pg-metodo">
        <option value="transferencia">Transferencia</option><option value="nequi">Nequi</option>
        <option value="efectivo">Efectivo</option><option value="otro">Otro</option>
      </select>
      <button class="btn mini" type="button" data-accion="pagar">Registrar pago</button>
    </div>`;
  }
  const filas = detalle.pagos.map((g) => `<div class="linea-dato"><span>${esc(g.pasarela)}</span><span>${money(g.monto)} · ${esc(g.estado)}</span></div>`).join('') || '<p class="nota">Sin pagos registrados.</p>';
  return `<div class="tarjeta"><div class="tarjeta-cab"><h3>Pago</h3></div>${filas}${accion}</div>`;
}

function seccionEnvio() {
  const e = detalle.envio;
  let form = '';
  if (['en_preparacion', 'confirmado'].includes(detalle.pedido.estado)) {
    const opciones = detalle.transportadoras.map((t) => `<option value="${t.id}">${esc(t.nombre)}</option>`).join('');
    form = `<div class="herramientas">
      <select class="campo" id="env-trans">${opciones}</select>
      <input class="campo" id="env-guia" placeholder="Número de guía">
      <button class="btn mini" type="button" data-accion="guia">Despachar</button>
    </div>`;
  }
  const info = e ? `<div class="linea-dato"><span>Guía</span><span>${esc(e.guia || '—')}</span></div>
    <div class="linea-dato"><span>Transportadora</span><span>${esc(e.transportadora || '—')}</span></div>
    <div class="linea-dato"><span>Tracking</span><span>${e.tracking_url ? `<a href="${esc(e.tracking_url)}" target="_blank" rel="noopener">Ver</a>` : '—'}</span></div>`
    : '<p class="nota">Sin envío.</p>';
  return `<div class="tarjeta"><div class="tarjeta-cab"><h3>Envío</h3></div>${info}${form}</div>`;
}

function seccionEventos() {
  const filas = detalle.eventos.map((ev) => `<div class="paso">
    <span class="punto"></span>
    <div><strong>${esc(ETIQUETA[ev.despues] || ev.despues)}</strong>
    <small>${esc(ev.usuario || 'Sistema')} · ${esc(String(ev.creado).slice(0, 16))}</small>
    ${ev.nota ? `<div class="nota">${esc(ev.nota)}</div>` : ''}</div></div>`).join('');
  return `<div class="tarjeta"><div class="tarjeta-cab"><h3>Historial</h3></div><div class="timeline">${filas}</div></div>`;
}

function seccionNota() {
  const n = detalle.pedido.notas_internas || '';
  return `<div class="tarjeta"><div class="tarjeta-cab"><h3>Nota interna</h3></div>
    <textarea class="campo" id="nota-interna" placeholder="Notas para el equipo…">${esc(n)}</textarea>
    <button class="btn sec mini" type="button" data-accion="guardar-nota">Guardar nota</button></div>`;
}

function pieAcciones() {
  const p = detalle.pedido;
  const botones = [];
  for (const h of detalle.transiciones) {
    botones.push(`<button class="btn mini" type="button" data-accion="transicion" data-estado="${esc(h)}">${esc(ETIQUETA[h] || h)}</button>`);
  }
  if (p.pago_estado === 'pagado' && !detalle.factura && p.estado !== 'pendiente_pago') {
    botones.push(`<button class="btn mini" type="button" data-accion="factura">Emitir factura</button>`);
  }
  if (detalle.factura) {
    botones.push(`<a class="btn sec mini" href="#/factura/${p.id}">Ver factura</a>`);
  }
  return botones.join('');
}

function renderDrawer() {
  const d = raiz.querySelector('#drawer');
  if (!drawerId) { d.innerHTML = ''; return; }
  d.innerHTML = drawerCabecera()
    + (detalle ? `<div class="drawer-cuerpo">${seccionResumen()}${seccionItems()}${seccionPago()}${seccionEnvio()}${seccionEventos()}${seccionNota()}</div>
      <div class="drawer-pie">${pieAcciones()}</div>` : `<div class="drawer-cuerpo"><div class="esqueleto"><div class="esqueleto-linea"></div><div class="esqueleto-linea"></div></div></div>`);
}

/* -------------------------------- acciones ------------------------------ */

async function accionDrawer(accion, extra = {}) {
  try {
    if (accion === 'pagar') {
      const metodo = raiz.querySelector('#pg-metodo').value;
      await api('pedido_pago', { cuerpo: { id: drawerId, metodo } });
      toast('Pago registrado');
    } else if (accion === 'transicion') {
      await api('pedido_transicion', { cuerpo: { id: drawerId, estado: extra.estado } });
      toast('Estado actualizado');
    } else if (accion === 'guia') {
      const transportadora_id = Number(raiz.querySelector('#env-trans').value || 0);
      const guia = raiz.querySelector('#env-guia').value.trim();
      await api('guia_guardar', { cuerpo: { id: drawerId, transportadora_id, guia } });
      toast('Envío registrado');
    } else if (accion === 'factura') {
      const r = await api('factura_emitir', { cuerpo: { id: drawerId } });
      toast(`Factura ${r.numero} emitida`);
    } else if (accion === 'guardar-nota') {
      await api('pedido_nota', { cuerpo: { id: drawerId, nota: raiz.querySelector('#nota-interna').value } });
      toast('Nota guardada');
    }
    detalle = await api('pedido_detalle', { query: { id: drawerId } });
    renderDrawer();
    cargarLista();
  } catch (e) {
    toast(e.message, 'error');
  }
}

/* -------------------------------- eventos ------------------------------- */

function conectar() {
  raiz.querySelector('#pedidos-q').addEventListener('input', (e) => {
    clearTimeout(relojBusqueda);
    relojBusqueda = setTimeout(() => { estado.q = e.target.value.trim(); estado.pagina = 1; cargarLista(); }, 250);
  });

  raiz.querySelector('.segmentado').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-val]');
    if (!b) return;
    estado.filtro = b.dataset.val;
    estado.pagina = 1;
    raiz.querySelectorAll('.segmentado button').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    cargarLista();
  });

  raiz.querySelector('#pedidos-cuerpo').addEventListener('click', (e) => {
    if (e.target.closest('[data-accion="ant"]')) { estado.pagina--; cargarLista(); return; }
    if (e.target.closest('[data-accion="sig"]')) { estado.pagina++; cargarLista(); return; }
    const fila = e.target.closest('.fila-pedido');
    if (fila) abrirDrawer(Number(fila.dataset.id));
  });

  raiz.querySelector('#drawer').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-accion]');
    if (!btn) return;
    const a = btn.dataset.accion;
    if (a === 'cerrar') { cerrarDrawer(); return; }
    if (a === 'factura' || a === 'pagar' || a === 'guia' || a === 'guardar-nota') { accionDrawer(a); return; }
    if (a === 'transicion') { accionDrawer('transicion', { estado: btn.dataset.estado }); return; }
  });
}

/* ------------------------------ ciclo de vida --------------------------- */

export default {
  titulo: 'Pedidos',
  permiso: 'pedidos.ver',

  async montar(el) {
    raiz = el;
    estado.q = '';
    estado.filtro = '';
    estado.pagina = 1;
    drawerId = null;
    raiz.innerHTML = cabecera() + filtros() + '<div class="tarjeta" id="pedidos-cuerpo"></div><div id="drawer"></div>';
    conectar();
    await cargarLista();
  },

  desmontar() {
    clearTimeout(relojBusqueda);
    raiz = null;
    drawerId = null;
    detalle = null;
  },
};
