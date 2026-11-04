/* Vista · Ficha de producto (Fase 2). */

import { api, csrfToken } from '../api.js';
import { toast } from '../ui/toast.js';
import { esc, money, numero } from '../formato.js';
import { panelError } from '../ui/skeleton.js';

const imgSrc = (r) => (r ? String(r).replace(/^\.\//, '../') : '');

const TABS = [
  ['general', 'General'],
  ['precios', 'Precios y envío'],
  ['variantes', 'Variantes'],
  ['inventario', 'Inventario'],
  ['multimedia', 'Multimedia'],
  ['seo', 'SEO'],
];

let raiz = null;
let productoId = null;      // null = nuevo
let producto = null;
let variantes = [];
let imagenes = [];
let categorias = [];
let pestaña = 'general';
let ajusteAbierto = null;   // id de variante con el formulario de ajuste abierto

/* ------------------------------ utilidades ------------------------------ */

const $id = (i) => raiz.querySelector('#' + i);
const val = (i) => {
  const el = raiz.querySelector('[data-campo="' + i + '"]');
  return el ? el.value : '';
};

/* ------------------------------ plantillas ------------------------------ */

function cabecera() {
  const titulo = producto ? producto.nombre : 'Nuevo producto';
  const meta = producto ? `${esc(producto.sku)} · ${esc(producto.slug)}` : 'Sin guardar';
  return `<div class="barra-vista">
    <div class="titulo-bloque">
      <h2>${esc(titulo)}</h2>
      <p class="nota">${meta}</p>
    </div>
    <button class="btn sec mini" type="button" data-accion="volver">Volver</button>
    ${productoId ? '<button class="btn sec mini" type="button" data-accion="archivar">Archivar o eliminar</button>' : ''}
    <button class="btn mini" type="button" data-accion="guardar">Guardar</button>
  </div>`;
}

function pestanas() {
  return `<div class="ficha-tabs" role="tablist">${TABS.map(([k, t]) =>
    `<button class="ficha-tab" type="button" role="tab" data-accion="pestaña" data-pestaña="${k}" aria-selected="${pestaña === k}">${t}</button>`
  ).join('')}</div>`;
}

function opcionesCategorias() {
  return categorias.map((c) => `<option value="${esc(c.id)}"${producto && producto.categoria_id === c.id ? ' selected' : ''}>${esc(c.nombre)}</option>`).join('');
}

function panelGeneral() {
  const p = producto || {};
  return `<div class="form-grid">
    <div class="campo-bloque ancho"><label for="f-nombre">Nombre</label>
      <input class="campo" id="f-nombre" data-campo="nombre" value="${esc(p.nombre || '')}"></div>
    <div class="campo-bloque"><label for="f-sku">SKU</label>
      <input class="campo" id="f-sku" data-campo="sku" value="${esc(p.sku || '')}" placeholder="Auto"></div>
    <div class="campo-bloque"><label for="f-slug">Slug</label>
      <input class="campo" id="f-slug" data-campo="slug" value="${esc(p.slug || '')}" placeholder="Auto"></div>
    <div class="campo-bloque"><label for="f-categoria">Categoría</label>
      <select class="campo" id="f-categoria" data-campo="categoria_id"><option value="">—</option>${opcionesCategorias()}</select></div>
    <div class="campo-bloque"><label for="f-marca">Marca</label>
      <input class="campo" id="f-marca" data-campo="marca" value="${esc(p.marca || '')}"></div>
    <div class="campo-bloque"><label for="f-badge">Etiqueta</label>
      <input class="campo" id="f-badge" data-campo="badge" value="${esc(p.badge || '')}"></div>
    <div class="campo-bloque"><label for="f-badge-tipo">Tipo de etiqueta</label>
      <select class="campo" id="f-badge-tipo" data-campo="badge_tipo">
        <option value="">Sin oferta</option>
        <option value="oferta"${(p.badge_tipo === 'oferta') ? ' selected' : ''}>Oferta</option>
      </select></div>
    <div class="campo-bloque ancho"><label for="f-descr">Descripción corta</label>
      <input class="campo" id="f-descr" data-campo="descr" value="${esc(p.descr || '')}"></div>
    <div class="campo-bloque ancho"><label for="f-descr-larga">Descripción larga</label>
      <textarea class="campo" id="f-descr-larga" data-campo="descr_larga">${esc(p.descr_larga || '')}</textarea></div>
    <div class="campo-bloque"><label><input type="checkbox" data-campo="destacado" ${p.destacado ? 'checked' : ''}> Destacado</label></div>
    <div class="campo-bloque"><label><input type="checkbox" data-campo="activo" ${p.activo === undefined || p.activo ? 'checked' : ''}> Activo</label></div>
  </div>`;
}

function panelPrecios() {
  const p = producto || {};
  const margen = p.costo && p.precio ? Math.round((p.precio - p.costo) * 100 / p.precio) : null;
  return `<div class="form-grid">
    <div class="campo-bloque"><label for="f-precio">Precio (COP)</label>
      <input class="campo" id="f-precio" data-campo="precio" type="number" min="0" value="${p.precio || 0}"></div>
    <div class="campo-bloque"><label for="f-precio-antes">Precio anterior</label>
      <input class="campo" id="f-precio-antes" data-campo="precio_antes" type="number" min="0" value="${p.precio_antes ?? ''}" placeholder="Sin tachar"></div>
    <div class="campo-bloque"><label for="f-costo">Costo</label>
      <input class="campo" id="f-costo" data-campo="costo" type="number" min="0" value="${p.costo ?? ''}" placeholder="Para calcular margen"></div>
    <div class="campo-bloque"><label for="f-iva">IVA (%)</label>
      <input class="campo" id="f-iva" data-campo="iva_pct" type="number" step="0.01" min="0" max="100" value="${p.iva_pct ?? 19}"></div>
    <div class="campo-bloque"><label for="f-peso">Peso (g)</label>
      <input class="campo" id="f-peso" data-campo="peso_g" type="number" min="0" value="${p.peso_g || 0}"></div>
    <div class="campo-bloque ancho"><p class="nota">Margen:
      ${margen === null ? '—' : `<span class="${margen >= 0 ? 'margen-ok' : 'margen-mal'}">${margen} %</span>`}
    </p></div>
    <div class="campo-bloque"><label for="f-alto">Alto (mm)</label>
      <input class="campo" id="f-alto" data-campo="alto_mm" type="number" min="0" value="${p.alto_mm || 0}"></div>
    <div class="campo-bloque"><label for="f-ancho">Ancho (mm)</label>
      <input class="campo" id="f-ancho" data-campo="ancho_mm" type="number" min="0" value="${p.ancho_mm || 0}"></div>
    <div class="campo-bloque"><label for="f-largo">Largo (mm)</label>
      <input class="campo" id="f-largo" data-campo="largo_mm" type="number" min="0" value="${p.largo_mm || 0}"></div>
  </div>`;
}

function panelVariantes() {
  if (!productoId) {
    return `<div class="estado-vacio"><strong>Guarda el producto primero</strong><span>Luego podrás generar la matriz de tallas y colores.</span></div>`;
  }
  const filas = variantes.map((v) => {
    const ajuste = ajusteAbierto === v.id ? `<tr class="ajuste-fila"><td colspan="8">
      <div class="herramientas">
        <input class="campo" data-ajuste="cantidad" type="number" placeholder="Cantidad (+ / −)">
        <input class="campo" data-ajuste="motivo" placeholder="Motivo (mín. 5 caracteres)">
        <button class="btn mini" type="button" data-accion="ajuste-aplicar" data-id="${v.id}">Aplicar</button>
        <button class="btn sec mini" type="button" data-accion="ajuste-cancelar">Cancelar</button>
      </div>
    </td></tr>` : '';
    return `<tr>
      <td data-etiqueta="Talla">${esc(v.talla || '—')}</td>
      <td data-etiqueta="Color">${esc(v.color || '—')}</td>
      <td data-etiqueta="SKU">${esc(v.sku)}</td>
      <td data-etiqueta="Precio extra"><input class="campo" type="number" data-campo="precio_extra" data-id="${v.id}" value="${v.precio_extra}"></td>
      <td data-etiqueta="Stock mín."><input class="campo" type="number" min="0" data-campo="stock_minimo" data-id="${v.id}" value="${v.stock_minimo}"></td>
      <td class="num" data-etiqueta="Stock">${numero(v.stock)}</td>
      <td data-etiqueta="Activo"><input type="checkbox" data-campo="activo" data-id="${v.id}" ${v.activo ? 'checked' : ''}></td>
      <td data-etiqueta="Acciones">
        <button class="btn sec mini" type="button" data-accion="variante-guardar" data-id="${v.id}">Guardar</button>
        <button class="btn sec mini" type="button" data-accion="variante-ajustar" data-id="${v.id}">Ajustar stock</button>
      </td>
    </tr>${ajuste}`;
  }).join('');

  return `<div class="tarjeta-simple">
    <div class="tarjeta-cab"><h3>Generar matriz</h3></div>
    <div class="herramientas">
      <div class="campo-bloque"><label for="m-tallas">Tallas (separadas por coma)</label>
        <input class="campo" id="m-tallas" placeholder="XS, S, M, L, XL"></div>
      <div class="campo-bloque"><label for="m-colores">Colores (separados por coma)</label>
        <input class="campo" id="m-colores" placeholder="Negro, Rojo, Azul"></div>
      <button class="btn sec mini" type="button" data-accion="preset-tallas">Cargar S, M, L, XL</button>
      <label class="campo-bloque"><input type="checkbox" id="m-sintalla"> Sin tallas (un solo stock)</label>
      <button class="btn mini" type="button" data-accion="generar-matriz">Generar</button>
    </div>
  </div>
  ${variantes.length
    ? `<div class="tabla-wrap"><table class="tabla">
        <thead><tr><th>Talla</th><th>Color</th><th>SKU</th><th>Precio extra</th><th>Stock mín.</th><th class="num">Stock</th><th>Activo</th><th></th></tr></thead>
        <tbody>${filas}</tbody></table></div>`
    : `<div class="estado-vacio"><strong>Sin variantes</strong><span>Genera la matriz para crear tallas y colores.</span></div>`}`;
}

function panelInventario() {
  if (!productoId) {
    return `<div class="estado-vacio"><strong>Guarda el producto primero</strong></div>`;
  }
  return `<div id="inventario-cuerpo"><div class="esqueleto"><div class="esqueleto-linea"></div><div class="esqueleto-linea"></div></div></div>`;
}

function panelMultimedia() {
  if (!productoId) {
    return `<div class="estado-vacio"><strong>Guarda el producto primero</strong></div>`;
  }
  const tarjetas = imagenes.map((img) => `
    <div class="tarjeta-imagen${img.principal ? ' principal' : ''}">
      <img src="${esc(imgSrc(img.ruta))}" alt="${esc(img.alt)}" loading="lazy">
      <div class="nota">${esc(img.alt || '—')}</div>
      <div class="acciones">
        ${img.principal ? '' : `<button class="btn sec mini" type="button" data-accion="imagen-principal" data-id="${img.id}">Principal</button>`}
        <button class="btn sec mini" type="button" data-accion="imagen-borrar" data-id="${img.id}">Borrar</button>
      </div>
    </div>`).join('');
  return `<div class="herramientas">
      <div class="campo-bloque"><label for="i-alt">Texto alternativo (alt)</label>
        <input class="campo" id="i-alt" placeholder="Describe la imagen"></div>
      <button class="btn mini" type="button" data-accion="imagen-elegir">Subir imagen</button>
      <input type="file" id="i-archivo" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
    </div>
    <p class="nota">Máximo 6 imágenes, 4 MB cada una. La primera subida es la principal.</p>
    ${imagenes.length ? `<div class="rejilla-imagenes">${tarjetas}</div>` : `<div class="estado-vacio"><strong>Sin imágenes</strong></div>`}`;
}

function panelSeo() {
  const p = producto || {};
  const t = p.meta_titulo || '';
  const d = p.meta_desc || '';
  return `<div class="form-grid">
    <div class="campo-bloque"><label for="s-titulo">Título SEO <span class="nota">(${t.length}/70)</span></label>
      <input class="campo" id="s-titulo" data-campo="meta_titulo" maxlength="70" value="${esc(t)}"></div>
    <div class="campo-bloque"><label for="s-desc">Descripción SEO <span class="nota">(${d.length}/160)</span></label>
      <input class="campo" id="s-desc" data-campo="meta_desc" maxlength="160" value="${esc(d)}"></div>
    <div class="campo-bloque ancho"><label>Vista previa</label>
      <div class="previa-seo">
        <div class="p-titulo">${esc(t || p.nombre || 'Nombre del producto')}</div>
        <div class="p-url">ecomerce.sebasdigital.site/productos/${esc(p.slug || '')}</div>
        <div class="p-desc">${esc(d || p.descr || '')}</div>
      </div>
    </div>
  </div>`;
}

function renderPanel() {
  const panel = raiz.querySelector('#producto-panel');
  switch (pestaña) {
    case 'precios': panel.innerHTML = panelPrecios(); break;
    case 'variantes': panel.innerHTML = panelVariantes(); break;
    case 'inventario': panel.innerHTML = panelInventario(); break;
    case 'multimedia': panel.innerHTML = panelMultimedia(); break;
    case 'seo': panel.innerHTML = panelSeo(); break;
    default: panel.innerHTML = panelGeneral();
  }
  if (pestaña === 'inventario' && productoId) cargarInventario();
}

/* -------------------------------- datos --------------------------------- */

async function cargarInventario() {
  const c = raiz.querySelector('#inventario-cuerpo');
  if (!c) return;
  try {
    const d = await api('inventario', { query: { limite: 50 } });
    const variantesDelProducto = new Set(variantes.map((v) => v.id));
    const filas = d.items.filter((m) => variantesDelProducto.has(m.variante_id)).map((m) => `
      <tr>
        <td data-etiqueta="Fecha">${esc(String(m.creado).slice(0, 16))}</td>
        <td data-etiqueta="Tipo">${esc(m.tipo)}</td>
        <td class="num" data-etiqueta="Cantidad">${m.cantidad > 0 ? '+' : ''}${numero(m.cantidad)}</td>
        <td class="num" data-etiqueta="Resultante">${numero(m.stock_despues)}</td>
        <td data-etiqueta="Motivo">${esc(m.motivo || '—')}</td>
        <td data-etiqueta="Usuario">${esc(m.usuario || '—')}</td>
      </tr>`).join('');
    c.innerHTML = filas
      ? `<div class="tabla-wrap"><table class="tabla">
          <thead><tr><th>Fecha</th><th>Tipo</th><th class="num">Cantidad</th><th class="num">Resultante</th><th>Motivo</th><th>Usuario</th></tr></thead>
          <tbody>${filas}</tbody></table></div>`
      : `<div class="estado-vacio"><strong>Sin movimientos</strong><span>Los ajustes de stock aparecen aquí.</span></div>`;
  } catch (e) {
    c.innerHTML = panelError(esc(e.message));
  }
}

async function recargar() {
  if (!productoId) { render(); return; }
  const d = await api('producto_detalle', { query: { id: productoId } });
  producto = d.producto;
  variantes = d.variantes;
  imagenes = d.imagenes;
  render();
}

function render() {
  raiz.innerHTML = cabecera() + '<div class="tarjeta">' + pestanas() + '<div id="producto-panel"></div></div>';
  renderPanel();
}

/* ------------------------------- acciones ------------------------------- */

function recolectar() {
  const num = (k) => {
    const v = val(k);
    return v === '' ? null : Number(v);
  };
  return {
    id: productoId ?? 0,
    nombre: val('nombre'),
    sku: val('sku'),
    slug: val('slug'),
    categoria_id: val('categoria_id'),
    marca: val('marca'),
    badge: val('badge'),
    badge_tipo: val('badge_tipo'),
    descr: val('descr'),
    descr_larga: val('descr_larga'),
    precio: Number(val('precio') || 0),
    precio_antes: num('precio_antes'),
    costo: num('costo'),
    iva_pct: val('iva_pct') === '' ? 19 : Number(val('iva_pct')),
    peso_g: Number(val('peso_g') || 0),
    alto_mm: Number(val('alto_mm') || 0),
    ancho_mm: Number(val('ancho_mm') || 0),
    largo_mm: Number(val('largo_mm') || 0),
    meta_titulo: val('meta_titulo'),
    meta_desc: val('meta_desc'),
    destacado: (raiz.querySelector('[data-campo="destacado"]') || {}).checked,
    activo: (raiz.querySelector('[data-campo="activo"]') || {}).checked,
  };
}

async function guardar() {
  const data = recolectar();
  try {
    const r = await api('producto_guardar', { cuerpo: { data } });
    toast(productoId ? 'Producto guardado' : 'Producto creado');
    if (!productoId) {
      productoId = r.id;
      history.replaceState(null, '', '#/producto/' + r.id);
    }
    await recargar();
  } catch (e) {
    toast(e.message, 'error');
  }
}

async function generarMatriz() {
  const sinTalla = $id('m-sintalla').checked;
  const tallas = ($id('m-tallas').value || '').split(',').map((s) => s.trim()).filter(Boolean);
  const colores = ($id('m-colores').value || '').split(',').map((s) => s.trim()).filter(Boolean)
    .map((c) => ({ nombre: c, hex: '' }));
  if (!sinTalla && !tallas.length && !colores.length) { toast('Indica tallas o colores, o marca "Sin tallas"', 'error'); return; }
  try {
    const r = await api('variantes_generar', { cuerpo: { producto_id: productoId, tallas, colores, sin_talla: sinTalla } });
    toast(`${r.creadas} creadas, ${r.omitidas} ya existían`);
    await recargar();
  } catch (e) {
    toast(e.message, 'error');
  }
}

async function varianteGuardar(id) {
  const fila = raiz.querySelector(`[data-campo="precio_extra"][data-id="${id}"]`);
  const inputs = raiz.querySelectorAll(`[data-campo][data-id="${id}"]`);
  const cuerpo = { id };
  inputs.forEach((el) => {
    cuerpo[el.dataset.campo] = el.type === 'checkbox' ? el.checked : el.value;
  });
  try {
    await api('variante_guardar', { cuerpo });
    toast('Variante guardada');
    await recargar();
  } catch (e) {
    toast(e.message, 'error');
  }
}

async function ajusteAplicar(id) {
  const cantidad = raiz.querySelector(`[data-ajuste="cantidad"]`);
  const motivo = raiz.querySelector(`[data-ajuste="motivo"]`);
  try {
    const r = await api('stock_ajustar', { cuerpo: { variante_id: id, cantidad: Number(cantidad.value || 0), motivo: motivo.value } });
    toast(`Stock actualizado a ${r.stock}`);
    ajusteAbierto = null;
    await recargar();
  } catch (e) {
    toast(e.message, 'error');
  }
}

async function subirImagen(archivo) {
  const alt = $id('i-alt').value.trim();
  if (!alt) { toast('Escribe el texto alternativo (alt)', 'error'); return; }
  const fd = new FormData();
  fd.append('producto_id', productoId);
  fd.append('alt', alt);
  fd.append('img', archivo);
  try {
    const res = await fetch('../api/api.php?r=imagen_subir', {
      method: 'POST',
      headers: { 'X-CSRF': csrfToken(), Accept: 'application/json' },
      body: fd,
      credentials: 'same-origin',
    });
    const datos = await res.json();
    if (!res.ok) throw new Error(datos.error || 'No se pudo subir');
    toast('Imagen subida');
    await recargar();
  } catch (e) {
    toast(e.message, 'error');
  }
}

/* -------------------------------- eventos ------------------------------- */

function conectar() {
  raiz.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-accion]');
    if (!btn) return;
    const accion = btn.dataset.accion;
    const id = btn.dataset.id ? Number(btn.dataset.id) : null;

    if (accion === 'volver') { location.hash = '#/productos'; return; }
    if (accion === 'archivar') {
      if (!confirm('Si el producto tiene pedidos se archiva (queda oculto). Si no tiene historial se elimina. ¿Continuar?')) return;
      try {
        const r = await api('producto_archivar', { cuerpo: { id: productoId } });
        toast(r.mensaje || (r.archivado ? 'Producto archivado' : 'Producto eliminado'));
        location.hash = '#/productos';
      } catch (e) { toast(e.message, 'error'); }
      return;
    }
    if (accion === 'guardar') { guardar(); return; }
    if (accion === 'pestaña') { pestaña = btn.dataset.pestaña; render(); return; }
    if (accion === 'generar-matriz') { generarMatriz(); return; }
    if (accion === 'preset-tallas') { $id('m-tallas').value = 'S, M, L, XL'; return; }
    if (accion === 'variante-guardar') { varianteGuardar(id); return; }
    if (accion === 'variante-ajustar') { ajusteAbierto = id; render(); return; }
    if (accion === 'ajuste-cancelar') { ajusteAbierto = null; render(); return; }
    if (accion === 'ajuste-aplicar') { ajusteAplicar(id); return; }
    if (accion === 'imagen-principal') {
      try { await api('imagen_orden', { cuerpo: { orden: imagenes.map((x) => x.id), principal: id } }); toast('Imagen principal actualizada'); await recargar(); }
      catch (err) { toast(err.message, 'error'); }
      return;
    }
    if (accion === 'imagen-borrar') {
      try { await api('imagen_borrar', { cuerpo: { id } }); toast('Imagen borrada'); await recargar(); }
      catch (err) { toast(err.message, 'error'); }
      return;
    }
    if (accion === 'imagen-elegir') { raiz.querySelector('#i-archivo').click(); return; }
  });

  raiz.addEventListener('change', (e) => {
    if (e.target.id === 'i-archivo' && e.target.files && e.target.files[0]) {
      subirImagen(e.target.files[0]);
      e.target.value = '';
    }
    // contadores SEO
    if (e.target.id === 's-titulo') {
      const n = raiz.querySelector('[data-campo="meta_titulo"]').value.length;
      const lbl = raiz.querySelector('.campo-bloque label[for="s-titulo"]');
      if (lbl) lbl.innerHTML = `Título SEO <span class="nota">(${n}/70)</span>`;
    }
  });
}

/* ------------------------------ ciclo de vida --------------------------- */

export default {
  titulo: 'Producto',
  permiso: 'productos.editar',

  async montar(el) {
    raiz = el;
    const [, param] = location.hash.replace(/^#\/?/, '').split('/');
    productoId = (param && param !== 'nuevo') ? Number(param) : null;
    pestaña = 'general';
    ajusteAbierto = null;
    producto = null;
    variantes = [];
    imagenes = [];

    raiz.innerHTML = '<div class="tarjeta"><div class="esqueleto"><div class="esqueleto-linea alta"></div><div class="esqueleto-linea"></div></div></div>';
    try {
      categorias = await api('categorias');
      if (productoId) {
        const d = await api('producto_detalle', { query: { id: productoId } });
        producto = d.producto;
        variantes = d.variantes;
        imagenes = d.imagenes;
      }
      render();
      conectar();
    } catch (e) {
      raiz.innerHTML = `<div class="tarjeta">${panelError(esc(e.message))}</div>`;
    }
  },

  desmontar() {
    raiz = null;
    producto = null;
    variantes = [];
    imagenes = [];
    categorias = [];
  },
};
