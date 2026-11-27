/* Vista del carrito: líneas, cupón y checkout. */
import { api } from '../services/ApiClient.js';
import { obtenerCatalogo } from '../services/catalogo.js';
import { esc, money, imgSrc } from '../core/formato.js';
import { nombreCategoria } from '../components/ProductCard.js';
import { toast } from '../components/Toast.js';

const descuentoCupon = (c, sub) => (c.tipo === 'fijo' ? Math.min(c.valor, sub) : c.tipo === 'envio_gratis' ? 0 : Math.floor(sub * c.valor / 100));
const etiquetaCupon = (c) => (c.tipo === 'fijo' ? `−$${c.valor}` : c.tipo === 'envio_gratis' ? 'envío gratis' : `−${c.valor}%`);

export default {
  async render(el, params, ctx) {
    const store = ctx.store;
    document.title = 'Carrito · MotoGear Bogotá';
    let catalog;
    try { catalog = await obtenerCatalogo(store); } catch { catalog = { productos: [], categorias: [] }; }

    el.innerHTML = `
      <section class="hero-carrito"><div class="hero-carrito-texto"><h1>Mi Carrito</h1><p>Revisa tu pedido antes de finalizar</p></div></section>
      <section class="pagina-carrito">
        <div class="carrito-items">
          <div class="carrito-encabezado"><span>Producto</span><span>Precio</span><span>Cantidad</span><span>Subtotal</span><span></span></div>
          <a href="/productos" class="seguir-comprando">← Seguir comprando</a>
        </div>
        <aside class="carrito-resumen">
          <h3 class="resumen-titulo">Resumen del pedido</h3>
          <div class="resumen-linea"><span>Subtotal (0 items)</span><span>$0</span></div>
          <div class="resumen-linea"><span>Envío</span><span class="envio-gratis">Gratis</span></div>
          <div class="resumen-linea descuento"><span>Descuento aplicado</span><span>− $0</span></div>
          <div class="cupon"><input type="text" placeholder="Código de cupón" class="cupon-input"><button class="cupon-btn" type="button">Aplicar</button></div>
          <div class="resumen-total"><span>Total</span><span>$0</span></div>
          <form id="form-pedido" class="pedido-form" novalidate>
            <h4 class="resumen-subtitulo">Datos de entrega</h4>
            <input class="campo" id="p-nombre" placeholder="Nombre completo" autocomplete="name" required maxlength="100">
            <input class="campo" id="p-telefono" type="tel" placeholder="Teléfono / WhatsApp" autocomplete="tel" required maxlength="20">
            <input class="campo" id="p-email" type="email" placeholder="Correo (obligatorio)" autocomplete="email" required maxlength="120">
            <input class="campo" id="p-ciudad" placeholder="Ciudad" autocomplete="address-level2" required maxlength="60">
            <input class="campo" id="p-direccion" placeholder="Dirección de entrega" autocomplete="street-address" required maxlength="200">
            <input class="campo" id="p-notas" placeholder="Notas (opcional)" maxlength="300">
            <input type="text" id="p-web" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button type="submit" class="btn-checkout">Realizar pedido <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M1 8a.5.5 0 0 1 .5-.5h11.793l-3.147-3.146a.5.5 0 0 1 .708-.708l4 4a.5.5 0 0 1 0 .708l-4 4a.5.5 0 0 1-.708-.708L13.293 8.5H1.5A.5.5 0 0 1 1 8"/></svg></button>
          </form>
          <div class="metodos-pago"><span>Aceptamos:</span><div class="pago-iconos"><span class="pago-badge">Nequi</span><span class="pago-badge">Daviplata</span><span class="pago-badge">PSE</span><span class="pago-badge">Efectivo</span></div></div>
        </aside>
      </section>`;

    const wrap = el.querySelector('.carrito-items');
    const lines = [...el.querySelectorAll('.resumen-linea')];
    const prod = (id) => store.producto(id);
    const stockOf = (p, t) => store.stockDe(p, t);
    let coupon = store.coupon;

    const subtotal = () => store.cart.reduce((t, i) => t + (prod(i.id)?.precio || 0) * i.qty, 0);

    const clean = () => {
      const c = store.cart.filter((i) => prod(i.id) && stockOf(prod(i.id), i.talla) > 0)
        .map((i) => ({ ...i, qty: Math.min(i.qty, stockOf(prod(i.id), i.talla)) }));
      store.cart = c; store.guardar();
      return c;
    };

    const totals = () => {
      const sub = subtotal();
      const disc = coupon ? descuentoCupon(coupon, sub) : 0;
      lines[0].children[0].textContent = `Subtotal (${store.cart.reduce((t, i) => t + i.qty, 0)} items)`;
      lines[0].children[1].textContent = money(sub);
      lines[2].children[0].textContent = coupon ? `Descuento (${coupon.codigo} ${etiquetaCupon(coupon)})` : 'Descuento aplicado';
      lines[2].children[1].textContent = '− ' + money(disc);
      el.querySelector('.resumen-total span:last-child').textContent = money(sub - disc);
    };

    const render = () => {
      wrap.querySelectorAll('.carrito-item,.carrito-vacio').forEach((n) => n.remove());
      const cart = clean();
      if (!cart.length) {
        const p = document.createElement('p');
        p.className = 'carrito-vacio';
        p.textContent = 'Tu carrito está vacío. ¡Explora el catálogo y equipa tu moto!';
        wrap.insertBefore(p, wrap.querySelector('.seguir-comprando'));
      }
      cart.forEach((it) => {
        const p = prod(it.id);
        const row = document.createElement('div');
        row.className = 'carrito-item'; row.dataset.id = it.id; row.dataset.talla = it.talla;
        row.innerHTML = `
          <div class="item-producto"><img src="${esc(imgSrc(p.img))}" alt="${esc(p.nombre)}" loading="lazy">
            <div class="item-info"><span class="item-categoria">${esc((nombreCategoria(catalog, p.categoria) + ' ' + p.marca).trim())}</span><h4 class="item-nombre">${esc(p.nombre)}</h4>${it.talla ? `<span class="item-talla">Talla: ${esc(it.talla)}</span>` : ''}</div></div>
          <span class="item-precio">${money(p.precio)}</span>
          <div class="item-cantidad"><button class="btn-cantidad" data-act="menos" aria-label="Quitar una unidad">−</button><span class="cantidad-valor">${it.qty}</span><button class="btn-cantidad" data-act="mas" aria-label="Agregar una unidad">+</button></div>
          <span class="item-subtotal">${money(p.precio * it.qty)}</span>
          <button class="btn-eliminar" data-act="del" title="Eliminar" aria-label="Eliminar ${esc(p.nombre)}">✕</button>`;
        wrap.insertBefore(row, wrap.querySelector('.seguir-comprando'));
      });
      totals();
    };

    wrap.addEventListener('click', (e) => {
      const b = e.target.closest('[data-act]');
      if (!b) return;
      const row = b.closest('.carrito-item');
      const it = store.cart.find((i) => i.id === +row.dataset.id && i.talla === row.dataset.talla);
      if (!it) return;
      const stock = stockOf(prod(it.id), it.talla);
      if (b.dataset.act === 'mas') { if (it.qty + 1 > stock) return toast(`Solo quedan ${stock} unidades${it.talla ? ' en talla ' + it.talla : ''}`, 'error'); it.qty++; }
      if (b.dataset.act === 'menos') it.qty = Math.max(1, it.qty - 1);
      if (b.dataset.act === 'del') store.quitar(it.id, it.talla);
      store.guardar(); render();
    });

    const setCoupon = async (code, quiet) => {
      try {
        coupon = await api('cupon', { codigo: code, subtotal: subtotal() });
        store.fijarCupon(coupon, coupon.codigo);
        el.querySelector('.cupon-input').value = coupon.codigo;
        if (!quiet) toast(`Cupón ${coupon.codigo} aplicado`);
      } catch (err) { coupon = null; store.fijarCupon(null, ''); if (!quiet) toast(err.message, 'error'); }
      totals();
    };
    el.querySelector('.cupon-btn').addEventListener('click', () => {
      const v = el.querySelector('.cupon-input').value.trim();
      if (v) setCoupon(v); else toast('Escribe un código', 'error');
    });

    render();
    if (store.couponCode) setCoupon(store.couponCode, true);

    const fp = el.querySelector('#form-pedido');
    fp.addEventListener('submit', async (e) => {
      e.preventDefault();
      const cart = store.cart;
      if (!cart.length) return toast('Tu carrito está vacío', 'error');
      const v = (id) => el.querySelector('#' + id).value.trim();
      const btn = el.querySelector('.btn-checkout', fp); btn.disabled = true;
      try {
        const r = await api('pedido', { nombre: v('p-nombre'), telefono: v('p-telefono'), email: v('p-email'), ciudad: v('p-ciudad'), direccion: v('p-direccion'), notas: v('p-notas'), cupon: coupon?.codigo || '', items: cart, web: v('p-web') });
        const resumen = cart.map((i) => `• ${i.qty} x ${prod(i.id).nombre}${i.talla ? ' (talla ' + i.talla + ')' : ''}`).join('\n');
        const wa = `https://api.whatsapp.com/send?phone=573167800764&text=${encodeURIComponent(`Hola MotoGear, acabo de hacer el pedido #${r.id} por ${money(r.total)}.\n${resumen}\nA nombre de ${v('p-nombre')}.`)}`;
        store.vaciar();
        const page = el.querySelector('.pagina-carrito'); page.style.gridTemplateColumns = '1fr';
        page.innerHTML = `<div class="pedido-ok"><h2>¡Pedido #${+r.id} recibido!</h2><p>Total: <strong>${money(r.total)}</strong>. Te contactaremos para coordinar el pago y la entrega.</p>
          <a class="btn-checkout" target="_blank" rel="noopener noreferrer" href="${esc(wa)}">Confirmar por WhatsApp</a><a class="seguir-comprando" href="/productos">← Seguir comprando</a></div>`;
        toast(`Pedido #${r.id} registrado con éxito`);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } catch (err) { toast(err.message, 'error'); btn.disabled = false; }
    });
  },
};
