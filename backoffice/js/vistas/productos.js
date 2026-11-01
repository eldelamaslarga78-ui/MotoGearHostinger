/* Vista · Productos (Fase 2, listado). */

import { api } from '../api.js';
import { esc, money, numero } from '../formato.js';
import { panelError, vacio } from '../ui/skeleton.js';

const LIMITE = 20;

const estado = { q: '', categoria: '', activo: '', pagina: 1, total: 0, paginas: 1 };

let raiz = null;
let categorias = [];
let relojBusqueda = null;
let cargando = false;

const imgSrc = (r) => (r ? String(r).replace(/^\.\//, '../') : '');

/* ------------------------------ plantillas ------------------------------ */

function barra() {
  return `<div class="barra-vista">
    <div class="titulo-bloque">
      <h2>Productos</h2>
      <p class="nota" id="productos-contador">Cargando…</p>
    </div>
    <button class="btn mini" type="button" id="productos-nuevo">Nuevo producto</button>
  </div>`;
}

function herramientas() {
  const opciones = categorias.map((c) =>
    `<option value="${esc(c.id)}">${esc(c.nombre)}</option>`).join('');
  return `<div class="herramientas">
    <label class="oculto-visual" for="productos-q">Buscar productos</label>
    <input class="campo buscador" id="productos-q" type="search" placeholder="Buscar por nombre, marca o SKU" value="${esc(estado.q)}">
    <label class="oculto-visual" for="productos-cat">Categoría</label>
    <select class="campo" id="productos-cat">
      <option value="">Todas las categorías</option>
      ${opciones}
    </select>
    <div class="segmentado" role="group" aria-label="Estado">
      <button type="button" data-val="" aria-pressed="${estado.activo === ''}">Todos</button>
      <button type="button" data-val="1" aria-pressed="${estado.activo === '1'}">Activos</button>
      <button type="button" data-val="0" aria-pressed="${estado.activo === '0'}">Archivados</button>
    </div>
  </div>`;
}

function fila(p) {
  const mini = p.img
    ? `<img class="miniatura" src="${esc(imgSrc(p.img))}" alt="" loading="lazy">`
    : `<span class="miniatura" aria-hidden="true"></span>`;
  const chip = p.activo
    ? '<span class="estado est-activo">Activo</span>'
    : '<span class="estado est-inactivo">Archivado</span>';
  return `<tr>
    <td data-etiqueta="Producto">
      <div class="celda-producto">
        ${mini}
        <div><div class="nombre">${esc(p.nombre)}</div><div class="sku">${esc(p.sku)}</div></div>
      </div>
    </td>
    <td data-etiqueta="Categoría">${esc(p.categoria)}</td>
    <td data-etiqueta="Marca">${esc(p.marca || '—')}</td>
    <td class="num" data-etiqueta="Precio">${money(p.precio)}</td>
    <td class="num" data-etiqueta="Stock">${numero(p.stock)}</td>
    <td data-etiqueta="Estado">${chip}</td>
    <td data-etiqueta="Acciones">
      <button class="icono-btn" type="button" data-id="${p.id}" aria-label="Editar ${esc(p.nombre)}">⋯</button>
    </td>
  </tr>`;
}

function cuerpo(datos) {
  if (!datos.items.length) {
    return vacio('Sin productos', 'Ajusta la búsqueda o crea el primero.');
  }
  const filas = datos.items.map(fila).join('');
  return `<div class="tabla-wrap">
    <table class="tabla">
      <thead><tr>
        <th>Producto</th><th>Categoría</th><th>Marca</th>
        <th class="num">Precio</th><th class="num">Stock</th><th>Estado</th><th></th>
      </tr></thead>
      <tbody>${filas}</tbody>
    </table>
  </div>${paginacion(datos)}`;
}

function paginacion(datos) {
  const desde = (datos.pagina - 1) * datos.limite + 1;
  const hasta = Math.min(datos.pagina * datos.limite, datos.total);
  return `<div class="paginacion">
    <span>${datos.total === 0 ? '0' : `${desde}–${hasta}`} de ${numero(datos.total)}</span>
    <button class="btn sec mini" type="button" id="productos-ant" ${datos.pagina <= 1 ? 'disabled' : ''}>Anterior</button>
    <button class="btn sec mini" type="button" id="productos-sig" ${datos.pagina >= datos.paginas ? 'disabled' : ''}>Siguiente</button>
  </div>`;
}

function esqueleto() {
  return `<div class="tabla-wrap"><table class="tabla"><tbody>
    ${Array.from({ length: 5 }, () => `<tr><td colspan="7"><div class="esqueleto"><div class="esqueleto-linea"></div></div></td></tr>`).join('')}
  </tbody></table></div>`;
}

/* -------------------------------- ciclo --------------------------------- */

async function cargar() {
  if (!raiz) return;
  const cuerpoEl = raiz.querySelector('#productos-cuerpo');
  cargando = true;
  cuerpoEl.innerHTML = esqueleto();
  try {
    const datos = await api('productos', { query: { q: estado.q, categoria: estado.categoria, activo: estado.activo, pagina: estado.pagina, limite: LIMITE } });
    if (!raiz) return;
    estado.total = datos.total;
    estado.paginas = datos.paginas;
    estado.pagina = Math.min(estado.pagina, datos.paginas || 1);
    cuerpoEl.innerHTML = cuerpo(datos);
    raiz.querySelector('#productos-contador').textContent = `${numero(datos.total)} producto${datos.total === 1 ? '' : 's'}`;
  } catch (e) {
    if (!raiz) return;
    cuerpoEl.innerHTML = panelError(esc(e.message));
  } finally {
    cargando = false;
  }
}

function conectar() {
  raiz.querySelector('#productos-q').addEventListener('input', (e) => {
    clearTimeout(relojBusqueda);
    relojBusqueda = setTimeout(() => {
      estado.q = e.target.value.trim();
      estado.pagina = 1;
      cargar();
    }, 250);
  });

  raiz.querySelector('#productos-cat').value = estado.categoria;
  raiz.querySelector('#productos-cat').addEventListener('change', (e) => {
    estado.categoria = e.target.value;
    estado.pagina = 1;
    cargar();
  });

  raiz.querySelectorAll('.segmentado button').forEach((b) => {
    b.addEventListener('click', () => {
      estado.activo = b.dataset.val;
      estado.pagina = 1;
      raiz.querySelectorAll('.segmentado button').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
      cargar();
    });
  });

  raiz.querySelector('#productos-nuevo').addEventListener('click', () => {
    location.hash = '#/producto/nuevo';
  });

  raiz.querySelector('#productos-cuerpo').addEventListener('click', (e) => {
    if (e.target.closest('#productos-ant')) {
      if (estado.pagina > 1) { estado.pagina--; cargar(); }
      return;
    }
    if (e.target.closest('#productos-sig')) {
      if (estado.pagina < estado.paginas) { estado.pagina++; cargar(); }
      return;
    }
    const btn = e.target.closest('button[data-id]');
    if (btn) location.hash = '#/producto/' + btn.dataset.id;
  });
}

export default {
  titulo: 'Productos',
  permiso: 'productos.ver',

  async montar(el) {
    raiz = el;
    try {
      categorias = await api('categorias');
    } catch {
      categorias = [];
    }
    raiz.innerHTML = barra() + herramientas() + '<div class="tarjeta" id="productos-cuerpo"></div>';
    conectar();
    await cargar();
  },

  desmontar() {
    clearTimeout(relojBusqueda);
    raiz = null;
    categorias = [];
    cargando = false;
  },
};
