/* Vista · Marketing: promociones, cupones y carritos (Fase 5). */

import { api } from '../api.js';
import { toast } from '../ui/toast.js';
import { esc, money, numero, fechaHora } from '../formato.js';
import { panelError, vacio } from '../ui/skeleton.js';

let raiz = null;
let pestaña = 'promociones';
let cuponesCache = [];

const TABS = [['promociones', 'Promociones'], ['cupones', 'Cupones'], ['carritos', 'Carritos']];

function cabecera() {
  return `<div class="barra-vista"><div class="titulo-bloque"><h2>Marketing</h2><p class="nota">Promociones, cupones y carritos abandonados</p></div></div>`;
}

function tabs() {
  return `<div class="ficha-tabs" role="tablist">${TABS.map(([k, t]) =>
    `<button class="ficha-tab" type="button" data-accion="tab" data-tab="${k}" aria-selected="${pestaña === k}">${t}</button>`).join('')}</div>`;
}

/* ------------------------------ promociones ----------------------------- */
async function verPromociones() {
  const c = raiz.querySelector('#mk-cuerpo');
  c.innerHTML = '<div class="esqueleto"><div class="esqueleto-linea"></div></div>';
  try {
    const d = await api('promociones');
    const filas = d.map((p) => `<tr>
      <td data-etiqueta="Nombre">${esc(p.nombre)}</td>
      <td data-etiqueta="Tipo">${esc(p.tipo)}</td>
      <td class="num" data-etiqueta="Prioridad">${numero(p.prioridad)}</td>
      <td data-etiqueta="Estado">${p.activa ? '<span class="estado est-activo">Activa</span>' : '<span class="estado est-inactivo">Inactiva</span>'}</td>
    </tr>`).join('');
    c.innerHTML = filas
      ? `<div class="tabla-wrap"><table class="tabla"><thead><tr><th>Nombre</th><th>Tipo</th><th class="num">Prioridad</th><th>Estado</th></tr></thead><tbody>${filas}</tbody></table></div>`
      : vacio('Sin promociones', 'Crea una para automatizar descuentos.');
  } catch (e) { c.innerHTML = panelError(esc(e.message)); }
}

async function verCupones() {
  const c = raiz.querySelector('#mk-cuerpo');
  c.innerHTML = `<div class="tarjeta-simple tarjeta-form">
    <div class="tarjeta-cab"><h3>Nuevo cupón</h3></div>
    <div class="form-grid">
      <div class="campo-bloque"><label>Código</label><input class="campo" id="cp-codigo" placeholder="EJEMPLO10"></div>
      <div class="campo-bloque"><label>Tipo</label><select class="campo" id="cp-tipo"><option value="pct">Porcentaje</option><option value="fijo">Monto fijo</option><option value="envio_gratis">Envío gratis</option></select></div>
      <div class="campo-bloque"><label>Valor</label><input class="campo" id="cp-valor" type="number" min="0"></div>
      <div class="campo-bloque"><label>Compra mínima</label><input class="campo" id="cp-minimo" type="number" min="0" value="0"></div>
      <div class="campo-bloque"><label>Vence (YYYY-MM-DD)</label><input class="campo" id="cp-vence"></div>
      <div class="campo-bloque"><label>Tope de usos</label><input class="campo" id="cp-usos" type="number" min="0"></div>
      <div class="campo-bloque"><label><input type="checkbox" id="cp-activo" checked> Activo</label></div>
    </div>
    <button class="btn mini" type="button" data-accion="cupon-crear">Crear cupón</button>
  </div>
  <div id="mk-cupones-lista"></div>`;
  const lista = raiz.querySelector('#mk-cupones-lista');
  try {
    const r = await api('admin');
    cuponesCache = r.cupones || [];
    lista.innerHTML = cuponesCache.length
      ? `<div class="tabla-wrap"><table class="tabla"><thead><tr><th>Código</th><th>Tipo</th><th class="num">Valor</th><th class="num">Usos</th><th>Estado</th><th></th></tr></thead><tbody>${cuponesCache.map((cp) => `<tr>
        <td data-etiqueta="Código">${esc(cp.codigo)}</td>
        <td data-etiqueta="Tipo">${esc(cp.tipo || 'pct')}</td>
        <td class="num" data-etiqueta="Valor">${esc(cp.tipo === 'fijo' ? money(cp.valor) : cp.tipo === 'envio_gratis' ? 'Envío gratis' : (cp.valor || cp.pct) + '%')}</td>
        <td class="num" data-etiqueta="Usos">${numero(cp.usos)}${cp.usos_max ? '/' + numero(cp.usos_max) : ''}</td>
        <td data-etiqueta="Estado">${Number(cp.activo) ? '<span class="estado est-activo">Activo</span>' : '<span class="estado est-inactivo">Inactivo</span>'}</td>
        <td data-etiqueta="Acciones">
          <button class="btn sec mini" type="button" data-accion="cupon-alternar" data-codigo="${esc(cp.codigo)}">${Number(cp.activo) ? 'Desactivar' : 'Activar'}</button>
          <button class="btn mini" type="button" data-accion="cupon-borrar" data-codigo="${esc(cp.codigo)}">Eliminar</button>
        </td>
      </tr>`).join('')}</tbody></table></div>`
      : vacio('Sin cupones');
  } catch (e) { lista.innerHTML = panelError(esc(e.message)); }
}

async function alternarCupon(codigo) {
  const cp = cuponesCache.find((x) => x.codigo === codigo);
  if (!cp) return;
  try {
    await api('guardar', { cuerpo: { tipo: 'cupon', data: {
      codigo: cp.codigo, tipo: cp.tipo || 'pct', valor: cp.valor ?? cp.pct, pct: cp.pct,
      minimo: cp.minimo, vence: cp.vence || '', usos_max: cp.usos_max,
      max_por_cliente: cp.max_por_cliente, primera_compra: !!Number(cp.primera_compra), activo: !Number(cp.activo),
    } } });
    toast(Number(cp.activo) ? 'Cupón desactivado' : 'Cupón activado');
    verCupones();
  } catch (err) { toast(err.message, 'error'); }
}

async function borrarCupon(codigo) {
  if (!confirm(`¿Eliminar el cupón ${codigo}? Los pedidos que lo usaron se conservan.`)) return;
  try {
    await api('borrar', { cuerpo: { tipo: 'cupon', id: codigo } });
    toast('Cupón eliminado');
    verCupones();
  } catch (err) { toast(err.message, 'error'); }
}

async function verCarritos() {
  const c = raiz.querySelector('#mk-cuerpo');
  c.innerHTML = '<div class="esqueleto"><div class="esqueleto-linea"></div></div>';
  try {
    const d = await api('carritos', { query: { limite: 50 } });
    const filas = d.items.map((x) => `<tr>
      <td data-etiqueta="Email">${esc(x.email || '—')}</td>
      <td class="num" data-etiqueta="Ítems">${numero(x.n_items)}</td>
      <td class="num" data-etiqueta="Valor">${money(x.subtotal)}</td>
      <td data-etiqueta="Inactivo">${esc(String(x.ultima_actividad).slice(0, 16))}</td>
      <td data-etiqueta="Acciones"><button class="btn sec mini" type="button" data-accion="recuperar" data-id="${x.id}">Enviar correo</button></td>
    </tr>`).join('');
    c.innerHTML = filas
      ? `<div class="tabla-wrap"><table class="tabla"><thead><tr><th>Email</th><th class="num">Ítems</th><th class="num">Valor</th><th>Última actividad</th><th></th></tr></thead><tbody>${filas}</tbody></table></div>`
      : vacio('Sin carritos abandonados');
  } catch (e) { c.innerHTML = panelError(esc(e.message)); }
}

async function render() {
  const p = raiz.querySelector('#mk-panel');
  if (pestaña === 'cupones') { await verCupones(); }
  else if (pestaña === 'carritos') { await verCarritos(); }
  else { await verPromociones(); }
}

function conectar() {
  raiz.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-accion]'); if (!btn) return;
    const a = btn.dataset.accion;
    if (a === 'tab') { pestaña = btn.dataset.tab; raiz.querySelectorAll('.ficha-tab').forEach((x) => x.setAttribute('aria-selected', String(x === btn))); render(); return; }
    if (a === 'cupon-alternar') { alternarCupon(btn.dataset.codigo); return; }
    if (a === 'cupon-borrar') { borrarCupon(btn.dataset.codigo); return; }
    if (a === 'recuperar') {
      try { await api('carrito_recuperar', { cuerpo: { id: Number(btn.dataset.id) } }); toast('Correo de recuperación enviado'); }
      catch (err) { toast(err.message, 'error'); }
      return;
    }
    if (a === 'cupon-crear') {
      const codigo = raiz.querySelector('#cp-codigo').value.trim().toUpperCase();
      const tipo = raiz.querySelector('#cp-tipo').value;
      const valor = Number(raiz.querySelector('#cp-valor').value || 0);
      const minimo = Number(raiz.querySelector('#cp-minimo').value || 0);
      const vence = raiz.querySelector('#cp-vence').value.trim();
      const usos = raiz.querySelector('#cp-usos').value;
      const activo = raiz.querySelector('#cp-activo').checked;
      try {
        await api('guardar', { cuerpo: { tipo: 'cupon', data: { codigo, tipo, valor, minimo, vence, usos_max: usos === '' ? null : Number(usos), activo } } });
        toast('Cupón creado'); render();
      } catch (err) { toast(err.message, 'error'); }
      return;
    }
  });
}

export default {
  titulo: 'Marketing', permiso: 'promociones.ver',
  async montar(el) {
    raiz = el;
    const ruta = location.hash.replace(/^#\/?/, '');
    pestaña = (ruta === 'carritos' || ruta === 'cupones') ? ruta : 'promociones';
    raiz.innerHTML = cabecera() + '<div class="tarjeta">' + tabs() + '<div id="mk-panel"></div><div id="mk-cuerpo"></div></div>';
    conectar(); await render();
  },
  desmontar() { raiz = null; },
};
