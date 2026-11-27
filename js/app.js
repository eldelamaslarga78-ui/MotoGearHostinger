/* Bootstrap de la tienda SPA: router, estado, contador de carrito y beacon de visitas. */
import { Router } from './core/Router.js';
import { Store } from './core/Store.js';
import { toast } from './components/Toast.js';
import InicioView from './views/InicioView.js';
import ProductosView from './views/ProductosView.js';
import ProductoView from './views/ProductoView.js';
import CarritoView from './views/CarritoView.js';
import ContactoView from './views/ContactoView.js';
import SobreView from './views/SobreView.js';

const store = new Store();

const router = new Router([
  { path: '/', view: InicioView },
  { path: '/productos', view: ProductosView },
  { path: '/producto/:id', view: ProductoView },
  { path: '/carrito', view: CarritoView },
  { path: '/contacto', view: ContactoView },
  { path: '/sobre', view: SobreView },
], { store });

/* Contador del carrito en el header (reactivo). */
function actualizarBadge() {
  const a = document.querySelector('#cart-badge');
  if (!a) return;
  const n = store.count();
  let b = a.querySelector('.cart-badge');
  if (!n) { if (b) b.remove(); return; }
  if (!b) { b = document.createElement('span'); b.className = 'cart-badge'; a.appendChild(b); }
  b.textContent = n;
}
store.subscribe(actualizarBadge);
actualizarBadge();

/* Agregar al carrito (delegado, sirve en tarjetas y en el detalle). */
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.btn-agregar');
  if (!btn || btn.disabled) return;
  const cont = btn.closest('.tarjeta') || btn.closest('.producto-detalle');
  if (!cont) return;
  const id = +cont.dataset.id;
  const p = store.producto(id);
  if (!p) return;
  const talla = cont.querySelector('.talla')?.value || '';
  const disp = store.stockDe(p, talla);
  const have = store.cantidad(id, talla);
  if (have + 1 > disp) {
    toast(disp <= 0 ? 'Esa talla está agotada' : `Solo quedan ${disp} unidades${talla ? ' en talla ' + talla : ''} de “${p.nombre}”`, 'error');
    return;
  }
  store.agregar(id, talla);
  toast(`“${p.nombre}” agregado al carrito`);
});

/* Menú móvil: cierra al navegar. */
document.addEventListener('click', (e) => {
  const toggle = document.querySelector('#menu-toggle');
  if (toggle && e.target.closest('header a')) toggle.checked = false;
});

/* Beacon de visitas: una por ruta (sin datos personales). */
function beacon(ruta) {
  if (location.protocol !== 'http:' && location.protocol !== 'https:') return;
  const params = new URLSearchParams(location.search);
  const datos = JSON.stringify({
    ruta,
    referrer: document.referrer || '',
    utm_source: params.get('utm_source') || '',
    utm_medium: params.get('utm_medium') || '',
    utm_campaign: params.get('utm_campaign') || '',
  });
  const url = '/api/api.php?r=visita';
  try {
    if (navigator.sendBeacon) {
      if (navigator.sendBeacon(url, new Blob([datos], { type: 'application/json' }))) return;
    }
    fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: datos, credentials: 'same-origin', keepalive: true }).catch(() => {});
  } catch { /* una visita perdida nunca rompe la tienda */ }
}
router.ctx.onRoute = beacon;

router.start();
