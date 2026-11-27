/* Vista de catálogo: filtros, orden y grilla. */
import { obtenerCatalogo } from '../services/catalogo.js';
import { tarjetaHTML } from '../components/ProductCard.js';
import { esc } from '../core/formato.js';

export default {
  async render(el, params, ctx) {
    document.title = 'Productos · MotoGear Bogotá';
    let catalog;
    try { catalog = await obtenerCatalogo(ctx.store); } catch { catalog = { productos: [], categorias: [] }; }

    const radiosCat = [['todos', 'Todos'], ...catalog.categorias.map((c) => [c.id, c.nombre])]
      .map(([v, t]) => `<label class="filtro-opcion"><input type="radio" name="categoria" value="${esc(v)}"${v === 'todos' ? ' checked' : ''}> ${esc(t)}</label>`).join('');
    const marcas = [...new Set(catalog.productos.map((p) => p.marca).filter(Boolean))]
      .map((m) => `<label class="filtro-opcion"><input type="checkbox" value="${esc(m)}"> ${esc(m)}</label>`).join('');

    el.innerHTML = `
      <section class="hero-productos"><div class="hero-productos-texto"><h1>Nuestros Productos</h1><p>Equipamiento premium para cada tipo de motociclista</p></div></section>
      <section class="pagina-productos">
        <aside class="filtros">
          <h3 class="filtros-titulo">Filtrar por</h3>
          <div class="filtro-grupo"><h4 class="filtro-subtitulo">Categoría</h4>${radiosCat}</div>
          <div class="filtro-grupo"><h4 class="filtro-subtitulo">Precio</h4>
            <label class="filtro-opcion"><input type="radio" name="precio" value="todos" checked> Todos</label>
            <label class="filtro-opcion"><input type="radio" name="precio" value="bajo"> Menos de $200.000</label>
            <label class="filtro-opcion"><input type="radio" name="precio" value="medio"> $200.000 - $600.000</label>
            <label class="filtro-opcion"><input type="radio" name="precio" value="alto"> Más de $600.000</label>
          </div>
          <div class="filtro-grupo"><h4 class="filtro-subtitulo">Marca</h4>${marcas}</div>
        </aside>
        <div class="catalogo">
          <div class="catalogo-header"><span class="catalogo-resultado"></span>
            <select class="catalogo-orden">
              <option value="">Ordenar por</option>
              <option value="precio-asc">Menor precio</option>
              <option value="precio-desc">Mayor precio</option>
              <option value="nuevo">Más nuevos</option>
            </select></div>
          <div class="grilla-productos"></div>
        </div>
      </section>`;

    const grid = el.querySelector('.grilla-productos');
    grid.innerHTML = catalog.productos.map((p) => tarjetaHTML(p, catalog)).join('') || '<p class="carrito-vacio">Aún no hay productos disponibles.</p>';

    const cards = [...grid.querySelectorAll('.tarjeta')];
    const data = cards.map((c, k) => ({ c, cat: c.dataset.cat, marca: c.dataset.marca.toLowerCase(), price: +c.dataset.precio, order: k }));
    const out = el.querySelector('.catalogo-resultado');
    const sel = el.querySelector('.catalogo-orden');

    const aplicar = () => {
      const cat = el.querySelector('input[name=categoria]:checked').value;
      const pr = el.querySelector('input[name=precio]:checked').value;
      const brands = [...el.querySelectorAll('.filtro-grupo input[type=checkbox]:checked')].map((i) => i.value.toLowerCase());
      const ok = (d) => (cat === 'todos' || d.cat === cat)
        && (pr === 'todos' || (pr === 'bajo' && d.price < 200000) || (pr === 'medio' && d.price >= 200000 && d.price <= 600000) || (pr === 'alto' && d.price > 600000))
        && (!brands.length || brands.includes(d.marca));
      const s = sel.value;
      [...data].sort((a, b) => (s === 'precio-asc' ? a.price - b.price : s === 'precio-desc' ? b.price - a.price : s === 'nuevo' ? b.order - a.order : a.order - b.order))
        .forEach((d) => { d.c.hidden = !ok(d); grid.appendChild(d.c); });
      const n = data.filter(ok).length;
      out.textContent = `${n} producto${n === 1 ? '' : 's'} encontrado${n === 1 ? '' : 's'}`;
    };

    el.querySelector('.filtros').addEventListener('change', aplicar);
    sel.addEventListener('change', aplicar);
    aplicar();
  },
};
