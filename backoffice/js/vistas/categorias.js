/* Vista · Categorías (Fase 2). */

import { api } from '../api.js';
import { toast } from '../ui/toast.js';
import { esc, numero } from '../formato.js';
import { panelError, vacio } from '../ui/skeleton.js';

let raiz = null;
let categorias = [];
let editando = null;   // null = lista, 'nueva' = crear, <id> = editar

const nombreDe = (id) => {
  const c = categorias.find((x) => x.id === id);
  return c ? c.nombre : '';
};

function cabecera() {
  return `<div class="barra-vista">
    <div class="titulo-bloque">
      <h2>Categorías</h2>
      <p class="nota">${numero(categorias.length)} categoría${categorias.length === 1 ? '' : 's'}</p>
    </div>
    <button class="btn mini" type="button" data-accion="nueva">Nueva categoría</button>
  </div>`;
}

function formCategoria() {
  const c = editando !== 'nueva' && editando !== null
    ? categorias.find((x) => x.id === editando)
    : null;
  const padres = categorias.filter((x) => x.id !== editando);
  return `<div class="tarjeta-simple tarjeta-form">
    <div class="tarjeta-cab"><h3>${c ? 'Editar categoría' : 'Nueva categoría'}</h3></div>
    <div class="form-grid">
      <div class="campo-bloque"><label for="c-nombre">Nombre</label>
        <input class="campo" id="c-nombre" value="${esc(c ? c.nombre : '')}"></div>
      <div class="campo-bloque"><label for="c-padre">Categoría padre</label>
        <select class="campo" id="c-padre">
          <option value="">— Sin padre —</option>
          ${padres.map((p) => `<option value="${esc(p.id)}"${c && c.padre_id === p.id ? ' selected' : ''}>${esc(p.nombre)}</option>`).join('')}
        </select></div>
      <div class="campo-bloque"><label for="c-orden">Orden</label>
        <input class="campo" id="c-orden" type="number" value="${c ? c.orden : 0}"></div>
      <div class="campo-bloque"><label for="c-icono">Icono (texto)</label>
        <input class="campo" id="c-icono" value="${esc(c ? (c.icono || '') : '')}"></div>
      <div class="campo-bloque"><label><input type="checkbox" id="c-activo" ${!c || c.activo ? 'checked' : ''}> Activo</label></div>
    </div>
    <div class="ficha-pie">
      <button class="btn sec mini" type="button" data-accion="cancelar">Cancelar</button>
      <button class="btn mini" type="button" data-accion="guardar-categoria">Guardar</button>
    </div>
  </div>`;
}

function tabla() {
  if (!categorias.length) return vacio('Sin categorías', 'Crea la primera para organizar el catálogo.');
  const filas = categorias.map((c) => `
    <tr>
      <td data-etiqueta="Nombre">${esc(c.nombre)}</td>
      <td data-etiqueta="ID">${esc(c.id)}</td>
      <td data-etiqueta="Padre">${esc(c.padre_id ? nombreDe(c.padre_id) : '—')}</td>
      <td class="num" data-etiqueta="Orden">${numero(c.orden)}</td>
      <td data-etiqueta="Estado">${c.activo ? '<span class="estado est-activo">Activo</span>' : '<span class="estado est-inactivo">Inactivo</span>'}</td>
      <td data-etiqueta="Acciones">
        <button class="btn sec mini" type="button" data-accion="editar" data-id="${esc(c.id)}">Editar</button>
        <button class="btn sec mini" type="button" data-accion="borrar" data-id="${esc(c.id)}">Borrar</button>
      </td>
    </tr>`).join('');
  return `<div class="tabla-wrap"><table class="tabla">
    <thead><tr><th>Nombre</th><th>ID</th><th>Padre</th><th class="num">Orden</th><th>Estado</th><th></th></tr></thead>
    <tbody>${filas}</tbody></table></div>`;
}

function render() {
  const cuerpo = raiz.querySelector('#categorias-cuerpo');
  cuerpo.innerHTML = (editando ? formCategoria() : '') + tabla();
}

async function cargar() {
  try {
    categorias = await api('categorias');
    render();
  } catch (e) {
    raiz.querySelector('#categorias-cuerpo').innerHTML = panelError(esc(e.message));
  }
}

async function guardarCategoria() {
  const id = editando !== 'nueva' ? editando : '';
  const cuerpo = {
    data: {
      id,
      nombre: raiz.querySelector('#c-nombre').value.trim(),
      padre_id: raiz.querySelector('#c-padre').value,
      orden: Number(raiz.querySelector('#c-orden').value || 0),
      icono: raiz.querySelector('#c-icono').value.trim(),
      activo: raiz.querySelector('#c-activo').checked,
    },
  };
  try {
    await api('categoria_guardar', { cuerpo });
    toast(id ? 'Categoría guardada' : 'Categoría creada');
    editando = null;
    await cargar();
  } catch (e) {
    toast(e.message, 'error');
  }
}

async function borrar(id) {
  try {
    await api('categoria_borrar', { cuerpo: { id } });
    toast('Categoría eliminada');
    await cargar();
  } catch (e) {
    toast(e.message, 'error');
  }
}

function conectar() {
  raiz.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-accion]');
    if (!btn) return;
    const a = btn.dataset.accion;
    if (a === 'nueva') { editando = 'nueva'; render(); return; }
    if (a === 'editar') { editando = btn.dataset.id; render(); return; }
    if (a === 'cancelar') { editando = null; render(); return; }
    if (a === 'guardar-categoria') { guardarCategoria(); return; }
    if (a === 'borrar') { borrar(btn.dataset.id); return; }
  });
}

export default {
  titulo: 'Categorías',
  permiso: 'categorias.editar',

  async montar(el) {
    raiz = el;
    editando = null;
    raiz.innerHTML = cabecera() + '<div id="categorias-cuerpo"></div>';
    conectar();
    await cargar();
  },

  desmontar() {
    raiz = null;
    categorias = [];
    editando = null;
  },
};
