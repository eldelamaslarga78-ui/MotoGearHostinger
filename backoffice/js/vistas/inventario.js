/* Vista · Inventario (Fase 2): alertas de stock y libro mayor global. */

import { api } from '../api.js';
import { esc, numero } from '../formato.js';
import { panelError, vacio } from '../ui/skeleton.js';

const LIMITE = 30;

const estado = { q: '', pagina: 1 };

let raiz = null;
let relojBusqueda = null;

function cabecera() {
  return `<div class="barra-vista">
    <div class="titulo-bloque">
      <h2>Inventario</h2>
      <p class="nota">Alertas de stock y libro mayor de movimientos</p>
    </div>
  </div>`;
}

function alertaFila(a) {
  return `<a class="alerta" href="#/producto/${a.producto_id}">
    <span class="alerta-ico" aria-hidden="true">⚠</span>
    <span class="alerta-txt">
      <strong>${esc(a.producto)}</strong>
      <small>${esc(a.sku)} · ${esc(a.talla || '—')}${a.color ? ' ' + esc(a.color) : ''}</small>
    </span>
    <span class="alerta-n">${numero(a.stock)} / mín. ${numero(a.stock_minimo)}</span>
  </a>`;
}

function renderAlertas(alertas) {
  const c = raiz.querySelector('#inv-alertas');
  if (!alertas.length) {
    c.innerHTML = `<div class="estado-vacio"><strong>Sin alertas</strong><span>Ninguna variante está por debajo de su mínimo.</span></div>`;
    return;
  }
  c.innerHTML = `<div class="lista-alertas">${alertas.map(alertaFila).join('')}</div>`;
}

function movimientoFila(m) {
  const signo = m.cantidad > 0 ? '+' : '';
  return `<tr>
    <td data-etiqueta="Fecha">${esc(String(m.creado).slice(0, 16))}</td>
    <td data-etiqueta="Producto">${esc(m.producto)}<div class="sku">${esc(m.sku)} · ${esc(m.talla || '—')}${m.color ? ' ' + esc(m.color) : ''}</div></td>
    <td data-etiqueta="Tipo">${esc(m.tipo)}</td>
    <td class="num" data-etiqueta="Cantidad">${signo}${numero(m.cantidad)}</td>
    <td class="num" data-etiqueta="Resultante">${numero(m.stock_despues)}</td>
    <td data-etiqueta="Motivo">${esc(m.motivo || '—')}</td>
    <td data-etiqueta="Usuario">${esc(m.usuario || '—')}</td>
  </tr>`;
}

function renderMovimientos(d) {
  const c = raiz.querySelector('#inv-libro');
  if (!d.items.length) {
    c.innerHTML = vacio('Sin movimientos', 'Los ajustes de stock y las ventas aparecen aquí.');
    return;
  }
  const desde = (d.pagina - 1) * d.limite + 1;
  const hasta = Math.min(d.pagina * d.limite, d.total);
  c.innerHTML = `<div class="tabla-wrap"><table class="tabla">
    <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th class="num">Cantidad</th><th class="num">Resultante</th><th>Motivo</th><th>Usuario</th></tr></thead>
    <tbody>${d.items.map(movimientoFila).join('')}</tbody></table></div>
    <div class="paginacion">
      <span>${desde}–${hasta} de ${numero(d.total)}</span>
      <button class="btn sec mini" type="button" data-accion="ant" ${d.pagina <= 1 ? 'disabled' : ''}>Anterior</button>
      <button class="btn sec mini" type="button" data-accion="sig" ${d.pagina >= d.paginas ? 'disabled' : ''}>Siguiente</button>
    </div>`;
}

async function cargar() {
  const cuerpo = raiz.querySelector('#inv-cuerpo');
  try {
    const [alertas, movs] = await Promise.all([
      api('stock_alertas'),
      api('inventario', { query: { q: estado.q, pagina: estado.pagina, limite: LIMITE } }),
    ]);
    renderAlertas(alertas);
    renderMovimientos(movs);
  } catch (e) {
    cuerpo.innerHTML = panelError(esc(e.message));
  }
}

function conectar() {
  raiz.querySelector('#inv-q').addEventListener('input', (e) => {
    clearTimeout(relojBusqueda);
    relojBusqueda = setTimeout(() => {
      estado.q = e.target.value.trim();
      estado.pagina = 1;
      cargar();
    }, 250);
  });

  raiz.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-accion]');
    if (!btn) return;
    if (btn.dataset.accion === 'ant' && estado.pagina > 1) { estado.pagina--; cargar(); }
    if (btn.dataset.accion === 'sig') { estado.pagina++; cargar(); }
  });
}

export default {
  titulo: 'Inventario',
  permiso: 'inventario.ajustar',

  async montar(el) {
    raiz = el;
    estado.q = '';
    estado.pagina = 1;
    raiz.innerHTML = cabecera() + `
      <div class="herramientas">
        <label class="oculto-visual" for="inv-q">Buscar movimientos</label>
        <input class="campo buscador" id="inv-q" type="search" placeholder="Buscar por producto, SKU o motivo">
      </div>
      <div class="tarjeta">
        <div class="tarjeta-cab"><h3>Alertas de stock</h3><span class="nota">Por debajo del mínimo</span></div>
        <div id="inv-alertas"></div>
      </div>
      <div class="tarjeta tarjeta-gap">
        <div class="tarjeta-cab"><h3>Libro mayor</h3></div>
        <div id="inv-libro"></div>
      </div>
      <div id="inv-cuerpo"></div>`;
    conectar();
    await cargar();
  },

  desmontar() {
    clearTimeout(relojBusqueda);
    raiz = null;
  },
};
