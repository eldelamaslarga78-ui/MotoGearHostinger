/* Vista · Transportadoras (envíos). Sin transportadora activa no se puede despachar. */

import { api } from '../api.js';
import { toast } from '../ui/toast.js';
import { esc } from '../formato.js';
import { panelError, vacio } from '../ui/skeleton.js';

let raiz = null;
let items = [];
let editando = null;   // null = lista · 'nueva' · id numérico

function cabecera() {
  return `<div class="barra-vista">
    <div class="titulo-bloque"><h2>Transportadoras</h2><p class="nota" id="tr-contador">Cargando…</p></div>
    <button class="btn mini" type="button" data-accion="nueva">Nueva transportadora</button>
  </div>`;
}

function formulario() {
  const t = editando === 'nueva' ? null : items.find((x) => x.id === editando);
  return `<form class="tarjeta-simple tarjeta-form" id="tr-form">
    <h3>${t ? 'Editar transportadora' : 'Nueva transportadora'}</h3>
    <div class="form-grid">
      <div class="campo-bloque"><label for="tr-nombre">Nombre</label>
        <input class="campo" id="tr-nombre" required maxlength="60" value="${esc(t ? t.nombre : '')}" placeholder="Servientrega, Envía…"></div>
      <div class="campo-bloque"><label for="tr-url">URL de seguimiento</label>
        <input class="campo" id="tr-url" maxlength="200" value="${esc(t ? t.tracking_url : '')}" placeholder="https://…/rastreo?guia={guia}"></div>
      <div class="campo-bloque"><label><input type="checkbox" id="tr-activo" ${!t || t.activo ? 'checked' : ''}> Activa (se puede elegir al despachar)</label></div>
    </div>
    <p class="nota">Usa <code>{guia}</code> donde debe ir el número de guía. Ejemplo: <code>https://transportadora.com/rastreo/{guia}</code></p>
    <div class="ficha-pie">
      <button class="btn sec mini" type="button" data-accion="cancelar">Cancelar</button>
      <button class="btn mini" type="submit">Guardar</button>
    </div>
  </form>`;
}

function tabla() {
  if (!items.length) return vacio('Sin transportadoras', 'Crea la primera para poder despachar pedidos.');
  const filas = items.map((t) => `<tr>
    <td data-etiqueta="Nombre">${esc(t.nombre)}</td>
    <td data-etiqueta="Seguimiento">${t.tracking_url ? esc(t.tracking_url) : '—'}</td>
    <td data-etiqueta="Estado">${t.activo ? '<span class="estado est-activo">Activa</span>' : '<span class="estado est-inactivo">Inactiva</span>'}</td>
    <td data-etiqueta="Acciones">
      <button class="btn sec mini" type="button" data-accion="editar" data-id="${t.id}">Editar</button>
      <button class="btn mini" type="button" data-accion="alternar" data-id="${t.id}">${t.activo ? 'Desactivar' : 'Activar'}</button>
    </td>
  </tr>`).join('');
  return `<div class="tabla-wrap"><table class="tabla">
    <thead><tr><th>Nombre</th><th>Seguimiento</th><th>Estado</th><th></th></tr></thead>
    <tbody>${filas}</tbody></table></div>`;
}

function render() {
  const c = raiz.querySelector('#tr-cuerpo');
  c.innerHTML = (editando ? formulario() : '') + tabla();
}

async function cargar() {
  try {
    items = await api('transportadoras');
    const activas = items.filter((t) => t.activo).length;
    raiz.querySelector('#tr-contador').textContent =
      `${items.length} transportadora${items.length === 1 ? '' : 's'} · ${activas} activa${activas === 1 ? '' : 's'}`;
    render();
  } catch (e) {
    raiz.querySelector('#tr-cuerpo').innerHTML = panelError(esc(e.message));
  }
}

async function guardar(e) {
  e.preventDefault();
  const cuerpo = {
    id: editando === 'nueva' ? 0 : editando,
    nombre: raiz.querySelector('#tr-nombre').value.trim(),
    tracking_url: raiz.querySelector('#tr-url').value.trim(),
    activo: raiz.querySelector('#tr-activo').checked,
  };
  try {
    await api('transportadoras', { cuerpo });
    toast('Transportadora guardada');
    editando = null;
    await cargar();
  } catch (err) { toast(err.message, 'error'); }
}

async function alternar(id) {
  const t = items.find((x) => x.id === id);
  if (!t) return;
  try {
    await api('transportadoras', { cuerpo: { id: t.id, nombre: t.nombre, tracking_url: t.tracking_url, activo: !t.activo } });
    toast(t.activo ? 'Transportadora desactivada' : 'Transportadora activada');
    await cargar();
  } catch (err) { toast(err.message, 'error'); }
}

function conectar() {
  raiz.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-accion]');
    if (!btn) return;
    const a = btn.dataset.accion;
    if (a === 'nueva') { editando = 'nueva'; render(); return; }
    if (a === 'editar') { editando = Number(btn.dataset.id); render(); return; }
    if (a === 'cancelar') { editando = null; render(); return; }
    if (a === 'alternar') { alternar(Number(btn.dataset.id)); return; }
  });
  raiz.addEventListener('submit', (e) => { if (e.target.id === 'tr-form') guardar(e); });
}

export default {
  titulo: 'Transportadoras',
  permiso: 'envios.gestionar',
  async montar(el) {
    raiz = el; editando = null; items = [];
    raiz.innerHTML = cabecera() + '<div id="tr-cuerpo"></div>';
    conectar();
    await cargar();
  },
  desmontar() { raiz = null; items = []; },
};
