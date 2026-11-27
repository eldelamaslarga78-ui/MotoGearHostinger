/* Tarjeta de producto reutilizable (catálogo, inicio y detalle). */
import { esc, money, imgSrc } from '../core/formato.js';

const CART_SVG = '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .491.592l-1.5 8A.5.5 0 0 1 13 12H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5M5 12a2 2 0 1 0 0 4 2 2 0 0 0 0-4m7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4"/></svg>';

export function nombreCategoria(catalog, id) {
  return (catalog.categorias.find((c) => c.id === id) || {}).nombre || '';
}

export function tarjetaHTML(p, catalog) {
  const conTallas = p.tallas.some((t) => t.talla !== '');
  const first = p.tallas.find((t) => t.stock > 0);
  const agotado = p.stock <= 0;
  const cat = nombreCategoria(catalog, p.categoria);
  return `<div class="tarjeta" data-id="${p.id}" data-cat="${esc(p.categoria)}" data-marca="${esc(p.marca)}" data-precio="${p.precio}">
    <div class="tarjeta-img"><img src="${esc(imgSrc(p.img))}" alt="${esc(p.nombre)}" loading="lazy" decoding="async">${agotado ? '<span class="badge agotado">Agotado</span>' : p.badge ? `<span class="badge${p.badgeTipo === 'oferta' ? ' oferta' : ''}">${esc(p.badge)}</span>` : ''}</div>
    <div class="tarjeta-info"><span class="categoria">${esc((cat + ' ' + p.marca).trim())}</span><h3><a href="/producto/${p.id}">${esc(p.nombre)}</a></h3><p>${esc(p.desc)}</p>
    ${conTallas ? `<select class="talla" aria-label="Talla">${p.tallas.map((t) => `<option value="${esc(t.talla)}"${t.stock <= 0 ? ' disabled' : ''}${first && t.talla === first.talla ? ' selected' : ''}>${esc(t.talla)} — ${t.stock > 0 ? t.stock + ' disp.' : 'agotada'}</option>`).join('')}</select>` : (!agotado && p.stock <= 5 ? `<span class="quedan">¡Quedan ${p.stock}!</span>` : '')}
    <div class="tarjeta-footer"><span class="precio">${money(p.precio)}</span><button class="btn-agregar"${agotado ? ' disabled' : ''}>${CART_SVG} ${agotado ? 'Agotado' : 'Agregar'}</button></div></div></div>`;
}
