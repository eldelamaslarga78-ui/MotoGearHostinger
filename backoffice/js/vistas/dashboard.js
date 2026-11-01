/* Vista · Resumen (dashboard de la Fase 1). */

import { api } from '../api.js';
import { toast } from '../ui/toast.js';
import { linea, barras, dona } from '../ui/charts.js';
import { esqueletoKpis, panelError } from '../ui/skeleton.js';
import {
  esc, money, numero, pct, variacion, diaCorto, fechaHora, hoyBogota, fechaLarga,
} from '../formato.js';

const RANGOS = [
  { id: 'hoy', texto: 'Hoy' },
  { id: '7d', texto: '7 días' },
  { id: '30d', texto: '30 días' },
  { id: 'mes', texto: 'Este mes' },
  { id: 'otro', texto: 'Otro' },
];

const NOMBRE_ESTADO = {
  nuevo: 'Nuevo',
  pendiente_pago: 'Pendiente de pago',
  confirmado: 'Confirmado',
  en_preparacion: 'En preparación',
  enviado: 'Enviado',
  entregado: 'Entregado',
  cancelado: 'Cancelado',
  devuelto: 'Devuelto',
};

const estado = { rango: '7d', desde: '', hasta: '' };

let raiz = null;
let datos = null;
let alertas = null;
let recientes = null;
let ultimaCarga = 0;
let cargando = false;
let relojPoll = null;
let relojTexto = null;

/* ----------------------------- Fechas ---------------------------------- */

const aFecha = (iso) => {
  const [y, m, d] = String(iso).split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d));
};
const aIso = (f) => f.toISOString().slice(0, 10);
const sumarDias = (iso, n) => {
  const f = aFecha(iso);
  f.setUTCDate(f.getUTCDate() + n);
  return aIso(f);
};

function rangoDe(id) {
  const hoy = hoyBogota();
  switch (id) {
    case 'hoy':
      return { desde: hoy, hasta: hoy };
    case 'mes':
      return { desde: hoy.slice(0, 8) + '01', hasta: hoy };
    case 'otro':
      if (estado.desde && estado.hasta) {
        return estado.desde <= estado.hasta
          ? { desde: estado.desde, hasta: estado.hasta }
          : { desde: estado.hasta, hasta: estado.desde };
      }
      return { desde: sumarDias(hoy, -29), hasta: hoy };
    case '30d':
      return { desde: sumarDias(hoy, -29), hasta: hoy };
    case '7d':
    default:
      return { desde: sumarDias(hoy, -6), hasta: hoy };
  }
}

function textoPeriodo(desde, hasta) {
  if (desde === hasta) return fechaLarga(desde);
  return `${diaCorto(desde)}/${desde.slice(0, 4)} — ${diaCorto(hasta)}/${hasta.slice(0, 4)}`;
}

/* ---------------------------- Render ----------------------------------- */

function tarjetaKpi({ etiqueta, valor, pie = '', cambio = null, conCambio = true, destacado = false }) {
  let v = '';
  if (conCambio) {
    if (cambio === null || cambio === undefined) {
      v = '<span class="variacion igual">sin periodo previo</span>';
    } else if (cambio === 0) {
      v = '<span class="variacion igual">＝ 0,0 %</span>';
    } else if (cambio > 0) {
      v = `<span class="variacion sube">▲ ${esc(variacion(cambio))}</span>`;
    } else {
      v = `<span class="variacion baja">▼ ${esc(variacion(cambio))}</span>`;
    }
  }
  return `<article class="kpi${destacado ? ' destacado' : ''}">
    <p class="kpi-etiqueta">${esc(etiqueta)}</p>
    <p class="kpi-valor">${valor}</p>
    <p class="kpi-pie">${v}<span class="kpi-neutro">${esc(pie)}</span></p>
  </article>`;
}

function panelAlertas(a) {
  const filas = [];

  if (a && a.stock) {
    const t = a.stock.total;
    filas.push(t > 0
      ? `<a class="alerta" href="#/productos"><span class="alerta-ico" aria-hidden="true">📦</span>
           <span class="alerta-txt"><strong>Stock bajo</strong><small>${esc(a.stock.items[0]?.nombre || '')}${a.stock.items.length > 1 ? ' y más' : ''}</small></span>
           <span class="alerta-n">${numero(t)}</span></a>`
      : `<div class="alerta nada"><span class="alerta-ico" aria-hidden="true">✓</span>
           <span class="alerta-txt"><strong>Stock al día</strong><small>Ninguna variante bajo el mínimo</small></span>
           <span class="alerta-n">0</span></div>`);
  }

  if (a && a.detenidos) {
    const t = a.detenidos.total;
    filas.push(t > 0
      ? `<a class="alerta" href="#/pedidos"><span class="alerta-ico" aria-hidden="true">⏳</span>
           <span class="alerta-txt"><strong>Pedidos detenidos</strong><small>Sin confirmar hace más de 24 h</small></span>
           <span class="alerta-n">${numero(t)}</span></a>`
      : `<div class="alerta nada"><span class="alerta-ico" aria-hidden="true">✓</span>
           <span class="alerta-txt"><strong>Sin pedidos detenidos</strong><small>Todo confirmado en menos de 24 h</small></span>
           <span class="alerta-n">0</span></div>`);
  }

  if (a && a.mensajes) {
    const t = a.mensajes.total;
    filas.push(t > 0
      ? `<a class="alerta" href="#/mensajes"><span class="alerta-ico" aria-hidden="true">💬</span>
           <span class="alerta-txt"><strong>Mensajes sin responder</strong><small>Formulario de contacto</small></span>
           <span class="alerta-n">${numero(t)}</span></a>`
      : `<div class="alerta nada"><span class="alerta-ico" aria-hidden="true">✓</span>
           <span class="alerta-txt"><strong>Bandeja al día</strong><small>Sin mensajes pendientes</small></span>
           <span class="alerta-n">0</span></div>`);
  }

  if (!filas.length) {
    return '<p class="nota">No tienes permisos para ver alertas.</p>';
  }
  return `<div class="lista-alertas">${filas.join('')}</div>`;
}

function tablaPedidos(items) {
  if (!items || !items.length) {
    return '<p class="nota">Todavía no hay pedidos.</p>';
  }
  const filas = items.map((p) => {
    const est = String(p.estado || '');
    return `<tr>
      <td data-etiqueta="Pedido"><strong>#${esc(p.id)}</strong></td>
      <td data-etiqueta="Cliente">${esc(p.cliente)}</td>
      <td data-etiqueta="Ciudad">${esc(p.ciudad || '—')}</td>
      <td data-etiqueta="Total" class="num">${money(p.total)}</td>
      <td data-etiqueta="Estado"><span class="estado est-${esc(est)}">${esc(NOMBRE_ESTADO[est] || est)}</span></td>
      <td data-etiqueta="Fecha">${esc(fechaHora(p.creado))}</td>
    </tr>`;
  }).join('');

  return `<div class="tabla-wrap"><table class="tabla">
    <thead><tr><th>Pedido</th><th>Cliente</th><th>Ciudad</th><th class="num">Total</th><th>Estado</th><th>Fecha</th></tr></thead>
    <tbody>${filas}</tbody>
  </table></div>`;
}

function partesEstados(mapa) {
  if (!mapa) return [];
  return Object.entries(mapa)
    .filter(([k]) => !['cancelado', 'devuelto'].includes(k))
    .map(([k, v]) => ({ etiqueta: NOMBRE_ESTADO[k] || k, valor: Number(v) }))
    .filter((p) => p.valor > 0);
}

function pintarDatos() {
  const caja = raiz.querySelector('#dash-datos');
  if (!caja) return;

  const a = datos?.actual || {};
  const ant = datos?.anterior || {};
  const v = datos?.variacion || {};
  const serie = a.serie || [];
  const rango = datos?.rango || rangoDe(estado.rango);

  const kpis = `<div class="kpis">
    ${tarjetaKpi({ etiqueta: 'Ingresos', valor: money(a.ingresos), pie: `vs. ${money(ant.ingresos)}`, cambio: v.ingresos, destacado: true })}
    ${tarjetaKpi({ etiqueta: 'Pedidos', valor: numero(a.pedidos), pie: `vs. ${numero(ant.pedidos)}`, cambio: v.pedidos })}
    ${tarjetaKpi({ etiqueta: 'Ticket promedio', valor: a.ticket === null || a.ticket === undefined ? '—' : money(a.ticket), pie: `vs. ${ant.ticket ? money(ant.ticket) : '—'}`, cambio: v.ticket })}
    ${tarjetaKpi({ etiqueta: 'Conversión', valor: a.conversion === null || a.conversion === undefined ? '—' : pct(a.conversion), pie: a.visitas ? `${numero(a.visitas)} visitas` : 'sin visitas registradas', cambio: v.conversion })}
    ${tarjetaKpi({ etiqueta: 'Por despachar', valor: numero(a.por_despachar), pie: 'pedidos activos ahora', conCambio: false })}
  </div>`;

  const graficos = `<div class="rejilla-graficos">
    <section class="tarjeta col-8">
      <div class="tarjeta-cab"><h3>Ingresos por día</h3><span class="nota">${esc(textoPeriodo(rango.desde, rango.hasta))}</span></div>
      ${linea({
        serie,
        campo: 'ingresos',
        titulo: 'Ingresos diarios del periodo',
        descripcion: `Ingresos confirmados por día entre ${rango.desde} y ${rango.hasta}.`,
        tipo: 'money',
      })}
    </section>

    <section class="tarjeta col-4">
      <div class="tarjeta-cab"><h3>Pedidos por estado</h3></div>
      ${dona({
        partes: partesEstados(datos?.estados),
        titulo: 'Distribución de pedidos por estado',
        descripcion: 'Cantidad de pedidos activos e históricos agrupados por estado.',
        centro: 'pedidos',
      })}
    </section>

    <section class="tarjeta col-8">
      <div class="tarjeta-cab"><h3>Pedidos y visitas</h3><span class="nota">La conversión sale de aquí</span></div>
      ${barras({
        serie,
        campos: [
          { campo: 'pedidos', etiqueta: 'Pedidos', clase: 'g-barra' },
          { campo: 'visitas', etiqueta: 'Visitas', clase: 'g-barra-2' },
        ],
        titulo: 'Pedidos y visitas por día',
        descripcion: 'Comparación diaria entre pedidos creados y sesiones únicas registradas.',
      })}
    </section>

    <section class="tarjeta col-4">
      <div class="tarjeta-cab"><h3>Alertas</h3><a class="nota" href="#/pedidos">Ver pedidos</a></div>
      ${panelAlertas(alertas)}
    </section>

    <section class="tarjeta col-12">
      <div class="tarjeta-cab"><h3>Últimos pedidos</h3><span class="nota">Los 8 más recientes</span></div>
      ${tablaPedidos(recientes?.items)}
    </section>
  </div>`;

  caja.innerHTML = kpis + graficos;
  actualizarTexto();
}

/* ---------------------------- Carga ------------------------------------ */

function actualizarTexto() {
  const t = raiz?.querySelector('#actualizado');
  if (!t || !ultimaCarga) return;
  const s = Math.max(0, Math.round((Date.now() - ultimaCarga) / 1000));
  t.textContent = s < 5 ? 'Actualizado ahora' : `Actualizado hace ${s} s`;
}

async function cargar(silencioso = false) {
  if (cargando) return;
  cargando = true;

  const caja = raiz.querySelector('#dash-datos');
  if (!silencioso && caja) caja.innerHTML = esqueletoKpis();
  raiz.querySelector('#refrescar')?.setAttribute('disabled', '');

  const r = rangoDe(estado.rango);
  estado.desde = r.desde;
  estado.hasta = r.hasta;

  try {
    const [kp, al, pr] = await Promise.all([
      api('kpis', { query: { desde: r.desde, hasta: r.hasta } }),
      api('alertas'),
      api('pedidos_recientes', { query: { limite: 8 } }).catch(() => ({ items: [] })),
    ]);

    datos = kp;
    alertas = al;
    recientes = pr;
    ultimaCarga = Date.now();
    pintarDatos();
  } catch (e) {
    if (e.estado !== 401 && caja) {
      caja.innerHTML = panelError(e.message || 'Error inesperado.', 'reintentar');
    } else if (!silencioso) {
      toast(e.message, 'error');
    }
  } finally {
    cargando = false;
    raiz.querySelector('#refrescar')?.removeAttribute('disabled');
  }
}

function marcarRango(id) {
  estado.rango = id;
  raiz.querySelectorAll('[data-rango]').forEach((b) => {
    b.setAttribute('aria-pressed', String(b.dataset.rango === id));
  });
  const fechas = raiz.querySelector('#rango-fechas');
  if (fechas) fechas.hidden = id !== 'otro';
  if (id !== 'otro') {
    raiz.querySelector('#desde').value = '';
    raiz.querySelector('#hasta').value = '';
  }
}

function barra() {
  const hoy = hoyBogota();
  const botones = RANGOS.map((r) => (
    `<button type="button" data-rango="${r.id}" aria-pressed="${r.id === estado.rango}">${r.texto}</button>`
  )).join('');

  return `<div class="barra-vista">
    <div class="titulo-bloque">
      <h2>Resumen general</h2>
      <p class="nota">Zona horaria America/Bogota · dinero en COP</p>
    </div>

    <div class="segmentado" role="group" aria-label="Periodo">${botones}</div>

    <div id="rango-fechas" class="rango-fechas" ${estado.rango === 'otro' ? '' : 'hidden'}>
      <label class="oculto-visual" for="desde">Desde</label>
      <input id="desde" type="date" max="${hoy}" value="${esc(estado.desde || '')}">
      <label class="oculto-visual" for="hasta">Hasta</label>
      <input id="hasta" type="date" max="${hoy}" value="${esc(estado.hasta || '')}">
    </div>

    <button id="refrescar" class="btn sec mini" type="button">Actualizar</button>
    <span id="actualizado" class="actualizado" aria-live="polite">Cargando…</span>
  </div>
  <div id="dash-datos">${esqueletoKpis()}</div>`;
}

function conectarEventos() {
  raiz.addEventListener('click', (e) => {
    const b = e.target.closest('[data-rango]');
    if (b) {
      marcarRango(b.dataset.rango);
      cargar();
      return;
    }
    if (e.target.closest('#refrescar')) {
      cargar();
      return;
    }
    if (e.target.closest('[data-accion="reintentar"]')) {
      cargar();
    }
  });

  raiz.addEventListener('change', (e) => {
    if (e.target.id === 'desde' || e.target.id === 'hasta') {
      estado.desde = raiz.querySelector('#desde').value;
      estado.hasta = raiz.querySelector('#hasta').value;
      if (estado.desde && estado.hasta) cargar();
    }
  });
}

/* --------------------------- Ciclo de vida ------------------------------ */

export default {
  titulo: 'Resumen',
  permiso: 'dashboard.ver',

  async montar(el) {
    raiz = el;
    raiz.innerHTML = barra();
    conectarEventos();
    await cargar();

    // Polling solo con la pestaña visible.
    relojPoll = setInterval(() => {
      if (document.visibilityState === 'visible') cargar(true);
    }, 60000);

    relojTexto = setInterval(actualizarTexto, 5000);

    document.addEventListener('visibilitychange', alVolver);
  },

  desmontar() {
    clearInterval(relojPoll);
    clearInterval(relojTexto);
    document.removeEventListener('visibilitychange', alVolver);
    relojPoll = relojTexto = null;
    raiz = null;
    datos = alertas = recientes = null;
    ultimaCarga = 0;
  },
};

function alVolver() {
  if (document.visibilityState === 'visible' && raiz) cargar(true);
}
