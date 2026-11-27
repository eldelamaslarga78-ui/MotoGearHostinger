/* Vista de detalle de producto (/producto/:id). */
import { obtenerCatalogo } from '../services/catalogo.js';
import { esc, money, imgSrc } from '../core/formato.js';
import { nombreCategoria } from '../components/ProductCard.js';

export default {
  async render(el, params, ctx) {
    const id = parseInt(params.id, 10);
    let catalog;
    try { catalog = await obtenerCatalogo(ctx.store); } catch { catalog = { productos: [], categorias: [] }; }
    const p = catalog.productos.find((x) => x.id === id);
    if (!p) { el.innerHTML = '<div class="carrito-vacio"><strong>Producto no encontrado</strong><p><a href="/productos">Ver productos</a></p></div>'; return; }

    document.title = `${p.nombre} · MotoGear Bogotá`;
    const cat = nombreCategoria(catalog, p.categoria);
    const conTallas = p.tallas.some((t) => t.talla !== '');
    const agotado = p.stock <= 0;

    el.innerHTML = `
      <section class="producto-detalle" data-id="${p.id}">
        <div class="producto-imagen">
          <img src="${esc(imgSrc(p.img))}" alt="${esc(p.nombre)}">
          ${p.badge ? `<span class="badge${p.badgeTipo === 'oferta' ? ' oferta' : ''}">${esc(p.badge)}</span>` : ''}
        </div>
        <div class="producto-info">
          <span class="categoria">${esc((cat + ' ' + p.marca).trim())}</span>
          <h1>${esc(p.nombre)}</h1>
          <p class="producto-desc">${esc(p.desc)}</p>
          <div class="precio producto-precio">${money(p.precio)}</div>
          ${conTallas ? `<select class="talla producto-talla" aria-label="Talla">${p.tallas.map((t) => `<option value="${esc(t.talla)}"${t.stock <= 0 ? ' disabled' : ''}>${esc(t.talla)} — ${t.stock > 0 ? t.stock + ' disp.' : 'agotada'}</option>`).join('')}</select>` : ''}
          ${agotado ? '<span class="badge agotado">Agotado</span>' : (!conTallas && p.stock <= 5 ? `<span class="quedan">¡Quedan ${p.stock}!</span>` : '')}
          <button class="btn-agregar producto-agregar"${agotado ? ' disabled' : ''}>${agotado ? 'Agotado' : 'Agregar al carrito'}</button>
          <a href="/productos" class="seguir-comprando">← Volver a productos</a>
        </div>
      </section>`;
  },
};
