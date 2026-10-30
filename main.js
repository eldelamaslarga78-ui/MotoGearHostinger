/* MotoGear – JS compartido, conectado a la API PHP/MySQL (api/api.php) */
(() => {
  'use strict';
  const API = 'api/api.php', KEY = 'mg-cart2', CKEY = 'mg-coupon';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const money = n => '$' + Math.round(n).toLocaleString('es-CO');
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  // El carrito guarda solo id, cantidad y talla; el precio SIEMPRE sale de la base de datos
  const load = () => { try { const c = JSON.parse(localStorage.getItem(KEY)); return Array.isArray(c) ? c : []; } catch { return []; } };
  const save = c => { try { localStorage.setItem(KEY, JSON.stringify(c)); } catch {} badge(); };
  const api = async (r, body) => {
    const res = await fetch(`${API}?r=${r}`, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {});
    const j = await res.json().catch(() => ({ error: 'Respuesta inválida del servidor' }));
    if (!res.ok) throw new Error(j.error || 'Error de conexión');
    return j;
  };

  /* ---------- Insignia del carrito + toast ---------- */
  function badge() {
    const a = $('header li[id="cart-badge"]'); if (!a) return;
    const n = load().reduce((t, i) => t + i.qty, 0);
    let b = $('.cart-badge', a);
    if (!n) return b && b.remove();
    if (!b) { b = document.createElement('span'); b.className = 'cart-badge'; a.appendChild(b); }
    b.textContent = n;
  }
  let tt;
  // tipo: 'ok' (verde) | 'error' (rojo)
  function toast(msg, tipo = 'ok') {
    let t = $('.toast');
    if (!t) { t = document.createElement('div'); t.className = 'toast'; document.body.appendChild(t); }
    t.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
    t.classList.remove('ok', 'error'); t.classList.add(tipo, 'show');
    t.textContent = msg;
    clearTimeout(tt); tt = setTimeout(() => t.classList.remove('show'), tipo === 'error' ? 6000 : 4000);
  }

  /* ---------- Menú móvil ---------- */
  const toggle = $('#menu-toggle');
  if (toggle) {
    $$('header ul a').forEach(a => a.addEventListener('click', () => toggle.checked = false));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') toggle.checked = false; });
  }

  /* ---------- Formulario de contacto -> base de datos ---------- */
  const form = $('.contacto-form');
  if (form) form.addEventListener('submit', async e => {
    e.preventDefault();
    const v = id => $('#' + id).value.trim(), btn = $('.btn-enviar', form);
    btn.disabled = true;
    try {
      await api('mensaje', { nombre: v('nombre') + ' ' + v('apellido'), email: v('email'), telefono: v('telefono'),
        asunto: $('#asunto').selectedOptions[0].text, mensaje: v('mensaje'), web: $('#web')?.value || '' });
      form.reset(); toast('¡Mensaje enviado! Te responderemos pronto');
    } catch (err) { toast(err.message, 'error'); }
    finally { btn.disabled = false; }
  });

  /* ---------- Carrusel (index) controlado por JS ---------- */
  const track = $('.carrusel-slides');
  if (track) {
    const DURATION = 450, DELAY = 4000;           // velocidad de transición y tiempo entre slides (ms)
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const slides = $$('.slide', track), dots = $$('.carrusel .punto');
    const ease = t => t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
    let cur = 0, raf, timer;

    function go(n, instant) {
      cur = (n + slides.length) % slides.length;
      dots.forEach((d, k) => d.classList.toggle('activo', k === cur));
      const from = track.scrollLeft, to = cur * track.clientWidth;
      cancelAnimationFrame(raf);
      if (instant || reduce) { track.scrollLeft = to; return; }
      const t0 = performance.now();
      const step = now => {
        const p = Math.min((now - t0) / DURATION, 1);
        track.scrollLeft = from + (to - from) * ease(p);
        if (p < 1) raf = requestAnimationFrame(step);
      };
      raf = requestAnimationFrame(step);
    }
    const play = () => { clearInterval(timer); if (!reduce) timer = setInterval(() => go(cur + 1), DELAY); };
    const stop = () => clearInterval(timer);

    $('.carrusel').addEventListener('click', e => {
      if (e.target.closest('.next')) go(cur + 1);
      else if (e.target.closest('.prev')) go(cur - 1);
      else { const d = e.target.closest('.punto'); if (d) go(dots.indexOf(d)); else return; }
      play();
    });
    // Swipe táctil / arrastre
    let x0 = null;
    track.addEventListener('pointerdown', e => { x0 = e.clientX; stop(); });
    track.addEventListener('pointerup', e => {
      if (x0 !== null && Math.abs(e.clientX - x0) > 50) go(cur + (e.clientX < x0 ? 1 : -1));
      x0 = null; play();
    });
    track.addEventListener('pointercancel', () => { x0 = null; play(); });
    // Pausa al pasar el mouse, enfocar o cambiar de pestaña
    const box = $('.carrusel');
    box.addEventListener('mouseenter', stop); box.addEventListener('mouseleave', play);
    box.addEventListener('focusin', stop);    box.addEventListener('focusout', play);
    document.addEventListener('visibilitychange', () => document.hidden ? stop() : play());
    addEventListener('resize', () => go(cur, true));
    go(0, true); play();
  }

  badge();

  /* ---------- Catálogo, carrito y pedidos (necesitan datos de la API) ---------- */
  (async () => {
    const gridHome = $('.seccion-productos .grilla-productos'), gridCat = $('.catalogo .grilla-productos'), wrap = $('.carrito-items');
    if (!gridHome && !gridCat && !wrap) return;
    let DATA;
    try { DATA = await api('catalogo'); }
    catch {
      const m = '<p class="carrito-vacio">No pudimos cargar el catálogo. Recarga la página en unos minutos.</p>';
      [gridHome, gridCat, wrap].forEach(g => g && (g.innerHTML = m));
      return toast('No se pudo conectar con la tienda', 'error');
    }
    const prod = id => DATA.productos.find(p => p.id === +id);
    const stockOf = (p, t) => (p.tallas.find(x => x.talla === t) || { stock: 0 }).stock;
    const catName = id => (DATA.categorias.find(c => c.id === id) || {}).nombre || '';
    const CART_SVG = '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .491.592l-1.5 8A.5.5 0 0 1 13 12H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5M5 12a2 2 0 1 0 0 4 2 2 0 0 0 0-4m7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4"/></svg>';
    const cardHTML = p => {
      const conTallas = p.tallas.some(t => t.talla !== ''), first = p.tallas.find(t => t.stock > 0);
      const agotado = p.stock <= 0;
      return `<div class="tarjeta" data-id="${p.id}" data-cat="${esc(p.categoria)}" data-marca="${esc(p.marca)}" data-precio="${p.precio}">
        <div class="tarjeta-img"><img src="${esc(p.img)}" alt="${esc(p.nombre)}" loading="lazy" decoding="async">${agotado ? '<span class="badge agotado">Agotado</span>' : p.badge ? `<span class="badge${p.badgeTipo === 'oferta' ? ' oferta' : ''}">${esc(p.badge)}</span>` : ''}</div>
        <div class="tarjeta-info"><span class="categoria">${esc((catName(p.categoria) + ' ' + p.marca).trim())}</span><h3>${esc(p.nombre)}</h3><p>${esc(p.desc)}</p>
        ${conTallas ? `<select class="talla" aria-label="Talla">${p.tallas.map(t => `<option value="${esc(t.talla)}"${t.stock <= 0 ? ' disabled' : ''}${first && t.talla === first.talla ? ' selected' : ''}>${esc(t.talla)} — ${t.stock > 0 ? t.stock + ' disp.' : 'agotada'}</option>`).join('')}</select>` : (!agotado && p.stock <= 5 ? `<span class="quedan">¡Quedan ${p.stock}!</span>` : '')}
        <div class="tarjeta-footer"><span class="precio">${money(p.precio)}</span><button class="btn-agregar"${agotado ? ' disabled' : ''}>${CART_SVG} ${agotado ? 'Agotado' : 'Agregar'}</button></div></div></div>`;
    };
    const noProd = '<p class="carrito-vacio">Aún no hay productos disponibles.</p>';

    if (gridHome) {
      const d = DATA.productos.filter(p => p.destacado);
      gridHome.innerHTML = (d.length ? d : DATA.productos.slice(0, 3)).map(cardHTML).join('') || noProd;
    }
    if (gridCat) {
      gridCat.innerHTML = DATA.productos.map(cardHTML).join('') || noProd;
      const radios = items => items.map(([v, t]) => `<label class="filtro-opcion"><input type="radio" name="categoria" value="${esc(v)}"> ${esc(t)}</label>`).join('');
      const gCat = $('input[name=categoria]')?.closest('.filtro-grupo');
      if (gCat) { $$('label', gCat).forEach(l => l.remove()); gCat.insertAdjacentHTML('beforeend', radios([['todos', 'Todos'], ...DATA.categorias.map(c => [c.id, c.nombre])])); $('input', gCat).checked = true; }
      const gMar = $$('.filtro-grupo').pop();
      if (gMar && !gMar.contains($('input[name=precio]'))) {
        $$('label', gMar).forEach(l => l.remove());
        [...new Set(DATA.productos.map(p => p.marca).filter(Boolean))].forEach(m => gMar.insertAdjacentHTML('beforeend', `<label class="filtro-opcion"><input type="checkbox" value="${esc(m)}"> ${esc(m)}</label>`));
      }
      const cards = $$('.tarjeta', gridCat);
      if (cards.length) {
        const data = cards.map((c, k) => ({ c, cat: c.dataset.cat, marca: c.dataset.marca.toLowerCase(), price: +c.dataset.precio, order: k }));
        const out = $('.catalogo-resultado'), sel = $('.catalogo-orden');
        const apply = () => {
          const cat = $('input[name=categoria]:checked').value, pr = $('input[name=precio]:checked').value;
          const brands = $$('.filtro-grupo input[type=checkbox]:checked').map(i => i.value.toLowerCase());
          const ok = d => (cat === 'todos' || d.cat === cat)
            && (pr === 'todos' || (pr === 'bajo' && d.price < 200000) || (pr === 'medio' && d.price >= 200000 && d.price <= 600000) || (pr === 'alto' && d.price > 600000))
            && (!brands.length || brands.includes(d.marca));
          const s = sel.value;
          [...data].sort((a, b) => s === 'precio-asc' ? a.price - b.price : s === 'precio-desc' ? b.price - a.price : s === 'nuevo' ? b.order - a.order : a.order - b.order)
            .forEach(d => { d.c.hidden = !ok(d); gridCat.appendChild(d.c); });
          const n = data.filter(ok).length;
          out.textContent = `${n} producto${n === 1 ? '' : 's'} encontrado${n === 1 ? '' : 's'}`;
        };
        $$('.filtros input').forEach(i => i.addEventListener('change', apply));
        sel.addEventListener('change', apply); apply();
      }
    }

    // Agregar al carrito
    document.addEventListener('click', e => {
      const btn = e.target.closest('.btn-agregar'); if (!btn || btn.disabled) return;
      const card = btn.closest('.tarjeta'), p = prod(card?.dataset.id); if (!p) return;
      const talla = $('.talla', card)?.value || '';
      const cart = load(), have = cart.filter(i => i.id === p.id && i.talla === talla).reduce((t, i) => t + i.qty, 0), disp = stockOf(p, talla);
      if (have + 1 > disp) return toast(disp <= 0 ? 'Esa talla está agotada' : `Solo quedan ${disp} unidades${talla ? ' en talla ' + talla : ''} de “${p.nombre}”`, 'error');
      const f = cart.find(i => i.id === p.id && i.talla === talla);
      f ? f.qty++ : cart.push({ id: p.id, qty: 1, talla });
      save(cart); toast(`“${p.nombre}” agregado al carrito`);
    });

    /* ----- Página del carrito ----- */
    if (wrap) {
      const anchor = $('.seguir-comprando', wrap), lines = $$('.resumen-linea');
      let coupon = null;
      const clean = () => { // quita productos que ya no existen y ajusta al stock
        const c = load().filter(i => prod(i.id) && stockOf(prod(i.id), i.talla) > 0).map(i => ({ ...i, qty: Math.min(i.qty, stockOf(prod(i.id), i.talla)) }));
        save(c); return c;
      };
      const subtotal = () => load().reduce((t, i) => t + (prod(i.id)?.precio || 0) * i.qty, 0);
      const totals = () => {
        const sub = subtotal(), disc = coupon ? Math.floor(sub * coupon.pct / 100) : 0;
        lines[0].children[0].textContent = `Subtotal (${load().reduce((t, i) => t + i.qty, 0)} items)`;
        lines[0].children[1].textContent = money(sub);
        lines[2].children[0].textContent = coupon ? `Descuento (${coupon.codigo} −${coupon.pct}%)` : 'Descuento aplicado';
        lines[2].children[1].textContent = '− ' + money(disc);
        $('.resumen-total span:last-child').textContent = money(sub - disc);
      };
      const render = () => {
        $$('.carrito-item,.carrito-vacio', wrap).forEach(n => n.remove());
        const cart = clean();
        if (!cart.length) { const p = document.createElement('p'); p.className = 'carrito-vacio'; p.textContent = 'Tu carrito está vacío. ¡Explora el catálogo y equipa tu moto!'; wrap.insertBefore(p, anchor); }
        cart.forEach(it => {
          const p = prod(it.id), row = document.createElement('div');
          row.className = 'carrito-item'; row.dataset.id = it.id; row.dataset.talla = it.talla;
          row.innerHTML = `
            <div class="item-producto"><img src="${esc(p.img)}" alt="${esc(p.nombre)}" loading="lazy">
              <div class="item-info"><span class="item-categoria">${esc((catName(p.categoria) + ' ' + p.marca).trim())}</span><h4 class="item-nombre">${esc(p.nombre)}</h4>${it.talla ? `<span class="item-talla">Talla: ${esc(it.talla)}</span>` : ''}</div></div>
            <span class="item-precio">${money(p.precio)}</span>
            <div class="item-cantidad"><button class="btn-cantidad" data-act="menos" aria-label="Quitar una unidad">−</button><span class="cantidad-valor">${it.qty}</span><button class="btn-cantidad" data-act="mas" aria-label="Agregar una unidad">+</button></div>
            <span class="item-subtotal">${money(p.precio * it.qty)}</span>
            <button class="btn-eliminar" data-act="del" title="Eliminar" aria-label="Eliminar ${esc(p.nombre)}">✕</button>`;
          wrap.insertBefore(row, anchor);
        });
        totals();
      };
      wrap.addEventListener('click', e => {
        const b = e.target.closest('[data-act]'); if (!b) return;
        const row = b.closest('.carrito-item'); let cart = load();
        const it = cart.find(i => i.id === +row.dataset.id && i.talla === row.dataset.talla); if (!it) return;
        const stock = stockOf(prod(it.id), it.talla);
        if (b.dataset.act === 'mas') { if (it.qty + 1 > stock) return toast(`Solo quedan ${stock} unidades${it.talla ? ' en talla ' + it.talla : ''}`, 'error'); it.qty++; }
        if (b.dataset.act === 'menos') it.qty = Math.max(1, it.qty - 1);
        if (b.dataset.act === 'del') cart = cart.filter(i => i !== it);
        save(cart); render();
      });
      const setCoupon = async (code, quiet) => {
        try {
          coupon = await api('cupon', { codigo: code, subtotal: subtotal() });
          localStorage.setItem(CKEY, coupon.codigo); $('.cupon-input').value = coupon.codigo;
          if (!quiet) toast(`Cupón ${coupon.codigo} aplicado`);
        } catch (err) { coupon = null; localStorage.removeItem(CKEY); if (!quiet) toast(err.message, 'error'); }
        totals();
      };
      $('.cupon-btn').addEventListener('click', () => { const v = $('.cupon-input').value.trim(); if (v) setCoupon(v); else toast('Escribe un código', 'error'); });
      render();
      const saved = localStorage.getItem(CKEY); if (saved) setCoupon(saved, true);

      // Finalizar compra: crea el pedido en la base de datos
      const fp = $('#form-pedido');
      fp.addEventListener('submit', async e => {
        e.preventDefault();
        const cart = load(); if (!cart.length) return toast('Tu carrito está vacío', 'error');
        const v = id => $('#p-' + id).value.trim(), btn = $('.btn-checkout', fp);
        btn.disabled = true;
        try {
          const r = await api('pedido', { nombre: v('nombre'), telefono: v('telefono'), email: v('email'), ciudad: v('ciudad'), direccion: v('direccion'), notas: v('notas'),
            cupon: coupon?.codigo || '', items: cart, web: v('web') });
          const resumen = cart.map(i => `• ${i.qty} x ${prod(i.id).nombre}${i.talla ? ' (talla ' + i.talla + ')' : ''}`).join('\n');
          const wa = `https://api.whatsapp.com/send?phone=573167800764&text=${encodeURIComponent(`Hola MotoGear, acabo de hacer el pedido #${r.id} por ${money(r.total)}.\n${resumen}\nA nombre de ${v('nombre')}.`)}`;
          save([]); localStorage.removeItem(CKEY);
          const page = $('.pagina-carrito'); page.style.gridTemplateColumns = '1fr';
          page.innerHTML = `<div class="pedido-ok"><h2>¡Pedido #${+r.id} recibido!</h2><p>Total: <strong>${money(r.total)}</strong>. Te contactaremos para coordinar el pago y la entrega.</p>
            <a class="btn-checkout" target="_blank" rel="noopener noreferrer" href="${esc(wa)}">Confirmar por WhatsApp</a><a class="seguir-comprando" href="productos.html">← Seguir comprando</a></div>`;
          toast(`Pedido #${r.id} registrado con éxito`);
          scrollTo({ top: 0, behavior: 'smooth' });
        } catch (err) { toast(err.message, 'error'); btn.disabled = false; }
      });
    }
  })();
})();
