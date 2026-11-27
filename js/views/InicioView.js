/* Vista de inicio: carrusel, promociones y productos destacados. */
import { obtenerCatalogo } from '../services/catalogo.js';
import { tarjetaHTML } from '../components/ProductCard.js';

function carrusel() {
  return `<div class="carrusel">
    <div class="carrusel-slides">
      <div class="slide"><img fetchpriority="high" src="/img/Slide1.webp" alt="Cascos para moto"><div class="slide-texto"><h2>Cascos Certificados DOT/ECE</h2><p>Protección y estilo para cada ruta</p><a href="/productos" class="btn-slide">Ver productos</a></div><button type="button" class="btn-nav prev" aria-label="Slide anterior">&#10094;</button><button type="button" class="btn-nav next" aria-label="Slide siguiente">&#10095;</button></div>
      <div class="slide"><img loading="lazy" decoding="async" src="/img/Slide2.webp" alt="Chaquetas de moto"><div class="slide-texto"><h2>Chaquetas & Guantes</h2><p>Indumentaria de alto rendimiento</p><a href="/productos" class="btn-slide">Ver productos</a></div><button type="button" class="btn-nav prev" aria-label="Slide anterior">&#10094;</button><button type="button" class="btn-nav next" aria-label="Slide siguiente">&#10095;</button></div>
      <div class="slide"><img loading="lazy" decoding="async" src="/img/Slide3.webp" alt="Accesorios moto"><div class="slide-texto"><h2>Accesorios para tu Moto</h2><p>Todo lo que necesitas en un solo lugar</p><a href="/productos" class="btn-slide">Ver productos</a></div><button type="button" class="btn-nav prev" aria-label="Slide anterior">&#10094;</button><button type="button" class="btn-nav next" aria-label="Slide siguiente">&#10095;</button></div>
    </div>
    <div class="indicadores">
      <button type="button" class="punto" aria-label="Ir al slide 1"></button>
      <button type="button" class="punto" aria-label="Ir al slide 2"></button>
      <button type="button" class="punto" aria-label="Ir al slide 3"></button>
    </div>
  </div>
  <div class="promos">
    <div class="card"><img alt="Equipamiento enduro" loading="lazy" decoding="async" src="/img/promocard1.webp"><div class="info-card"><span>Enduro</span></div></div>
    <div class="card"><img alt="Equipamiento deportivo" loading="lazy" decoding="async" src="/img/promocard2.webp"><div class="info-card"><span>Deportivo</span></div></div>
  </div>`;
}

function iniciarCarrusel(raiz) {
  const track = raiz.querySelector('.carrusel-slides');
  if (!track) return;
  const DURATION = 450, DELAY = 4000;
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const slides = [...track.querySelectorAll('.slide')];
  const dots = [...raiz.querySelectorAll('.carrusel .punto')];
  const ease = (t) => (t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2);
  let cur = 0, raf, timer;

  function go(n, instant) {
    cur = (n + slides.length) % slides.length;
    dots.forEach((d, k) => d.classList.toggle('activo', k === cur));
    const from = track.scrollLeft, to = cur * track.clientWidth;
    cancelAnimationFrame(raf);
    if (instant || reduce) { track.scrollLeft = to; return; }
    const t0 = performance.now();
    const step = (now) => {
      const p = Math.min((now - t0) / DURATION, 1);
      track.scrollLeft = from + (to - from) * ease(p);
      if (p < 1) raf = requestAnimationFrame(step);
    };
    raf = requestAnimationFrame(step);
  }
  const play = () => { clearInterval(timer); if (!reduce) timer = setInterval(() => go(cur + 1), DELAY); };
  const stop = () => clearInterval(timer);

  raiz.querySelector('.carrusel').addEventListener('click', (e) => {
    if (e.target.closest('.next')) go(cur + 1);
    else if (e.target.closest('.prev')) go(cur - 1);
    else { const d = e.target.closest('.punto'); if (d) go(dots.indexOf(d)); else return; }
    play();
  });
  let x0 = null;
  track.addEventListener('pointerdown', (e) => { x0 = e.clientX; stop(); });
  track.addEventListener('pointerup', (e) => { if (x0 !== null && Math.abs(e.clientX - x0) > 50) go(cur + (e.clientX < x0 ? 1 : -1)); x0 = null; play(); });
  track.addEventListener('pointercancel', () => { x0 = null; play(); });
  const box = raiz.querySelector('.carrusel');
  box.addEventListener('mouseenter', stop); box.addEventListener('mouseleave', play);
  box.addEventListener('focusin', stop); box.addEventListener('focusout', play);
  document.addEventListener('visibilitychange', () => (document.hidden ? stop() : play()));
  addEventListener('resize', () => go(cur, true));
  go(0, true); play();
}

export default {
  async render(el, params, ctx) {
    document.title = 'MotoGear Bogotá';
    let catalog;
    try { catalog = await obtenerCatalogo(ctx.store); } catch { catalog = { productos: [], categorias: [] }; }
    const destacados = catalog.productos.filter((p) => p.destacado);
    const lista = (destacados.length ? destacados : catalog.productos.slice(0, 3)).map((p) => tarjetaHTML(p, catalog)).join('');
    el.innerHTML = `${carrusel()}
      <section class="seccion-productos">
        <h2 class="titulo-seccion">Más vendidos</h2>
        <div class="grilla-productos">${lista || '<p class="carrito-vacio">Aún no hay productos disponibles.</p>'}</div>
      </section>`;
    iniciarCarrusel(el);
  },
};
