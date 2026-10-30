/* Panel de administración – conectado a la API PHP/MySQL con sesión y token CSRF */
(() => {
  'use strict';
  const API = 'api/api.php', $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = n => '$' + Math.round(n).toLocaleString('es-CO');
  let CSRF = '', D = { categorias: [], productos: [], cupones: [], pedidos: [], mensajes: [] };
  let tt; const toast = (m, tipo = 'ok') => { const t = $('#toast'); t.setAttribute('role', tipo === 'error' ? 'alert' : 'status'); t.classList.remove('ok', 'error'); t.classList.add(tipo, 'show'); t.textContent = m; clearTimeout(tt); tt = setTimeout(() => t.classList.remove('show'), 4000); };
  const show = on => { $('#login').hidden = on; $('#panel').hidden = !on; };
  const api = async (r, body) => {
    const o = body !== undefined ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify(body) } : {};
    const res = await fetch(`${API}?r=${r}`, o), j = await res.json().catch(() => ({ error: 'Respuesta inválida' }));
    if (res.status === 401 && r !== 'login') show(false);
    if (!res.ok) throw new Error(j.error || 'Error');
    return j;
  };
  const run = async (fn, okMsg) => { try { await fn(); if (okMsg) toast(okMsg); await refresh(); } catch (e) { toast(e.message, 'error'); } };
  const refresh = async () => { D = await api('admin'); render(); };

  /* ----- Acceso ----- */
  (async () => { try { const me = await api('me'); if (me.admin) { CSRF = me.csrf; show(true); await refresh(); } } catch {} })();
  $('#login-form').addEventListener('submit', async e => {
    e.preventDefault();
    try { const r = await api('login', { usuario: $('#usuario').value, clave: $('#clave').value }); CSRF = r.csrf; $('#clave').value = ''; show(true); await refresh(); }
    catch (err) { toast(err.message, 'error'); }
  });
  $('#salir').addEventListener('click', async () => { try { await api('logout', {}); } catch {} location.reload(); });
  $$('.tab').forEach(t => t.addEventListener('click', () => {
    $$('.tab').forEach(x => x.classList.toggle('activo', x === t));
    $$('.vista').forEach(v => v.hidden = v.id !== 'tab-' + t.dataset.tab);
  }));

  /* ----- Render ----- */
  const stockTxt = p => p.tallas.length === 1 && p.tallas[0].talla === '' ? String(p.tallas[0].stock) : (p.tallas.map(t => `${esc(t.talla)}: ${t.stock}`).join(' · ') || '0');
  const catName = id => (D.categorias.find(c => c.id === id) || {}).nombre || '—';
  const ESTADOS = ['nuevo', 'confirmado', 'enviado', 'entregado', 'cancelado'];
  function render() {
    $('#p-cat').innerHTML = D.categorias.map(c => `<option value="${esc(c.id)}">${esc(c.nombre)}</option>`).join('');
    $('#n-ped').textContent = D.pedidos.filter(p => p.estado === 'nuevo').length || '';
    $('#lista-ped').innerHTML = D.pedidos.length ? D.pedidos.map(p => `
      <details class="pedido"><summary><strong>#${p.id}</strong> ${esc(p.cliente)} · ${money(p.total)} · <small>${esc(p.creado)}</small>
        <span class="etiqueta est est-${esc(p.estado)}">${esc(p.estado)}</span></summary>
        <div class="det">
          <div>📞 <a href="https://wa.me/${esc(String(p.telefono).replace(/\D/g, ''))}" target="_blank" rel="noopener">${esc(p.telefono)}</a>${p.email ? ' · ✉️ ' + esc(p.email) : ''}</div>
          <div>📍 ${esc(p.direccion)}, ${esc(p.ciudad)}</div>${p.notas ? `<div>📝 ${esc(p.notas)}</div>` : ''}
          <ul>${p.items.map(i => `<li>${i.cantidad} × ${esc(i.nombre)}${i.talla ? ' (talla ' + esc(i.talla) + ')' : ''} — ${money(i.precio * i.cantidad)}</li>`).join('')}</ul>
          <div>Subtotal ${money(p.subtotal)}${p.cupon ? ` · Cupón ${esc(p.cupon)} −${money(p.descuento)}` : ''} · <strong>Total ${money(p.total)}</strong></div>
          <label>Estado <select data-estado="${p.id}" ${p.estado === 'cancelado' ? 'disabled' : ''}>${ESTADOS.map(s => `<option${s === p.estado ? ' selected' : ''}>${s}</option>`).join('')}</select></label>
          ${p.estado !== 'cancelado' ? '<small>Al cancelar, el stock se devuelve automáticamente.</small>' : ''}
        </div></details>`).join('') : '<div class="caja vacio">Aún no hay pedidos</div>';
    $('#tabla-prod').innerHTML = D.productos.length ? '<tr><th></th><th>Producto</th><th>Categoría</th><th>Precio</th><th>Stock</th><th></th></tr>' + D.productos.map(p => `<tr>
      <td><img src="${esc(p.img)}" alt=""></td>
      <td><strong>${esc(p.nombre)}</strong> <small>${esc(p.marca)}</small>${+p.destacado ? ' <span class="etiqueta">Destacado</span>' : ''}${+p.activo ? '' : ' <span class="etiqueta">Oculto</span>'}</td>
      <td>${esc(catName(p.categoria_id))}</td><td>${money(p.precio)}</td><td class="${+p.stock <= 0 ? 'agotado' : ''}">${stockTxt(p)}</td>
      <td><button class="btn sec mini" data-edit="${p.id}">Editar</button><button class="btn mini" data-del="producto:${p.id}">Eliminar</button></td></tr>`).join('') : '<tr><td class="vacio">Sin productos</td></tr>';
    $('#tabla-cat').innerHTML = '<tr><th>Categoría</th><th>Productos</th><th></th></tr>' + D.categorias.map(c => `<tr><td>${esc(c.nombre)}</td><td>${D.productos.filter(p => p.categoria_id === c.id).length}</td>
      <td><button class="btn sec mini" data-ren="${esc(c.id)}">Renombrar</button><button class="btn mini" data-del="categoria:${esc(c.id)}">Eliminar</button></td></tr>`).join('');
    $('#tabla-cup').innerHTML = D.cupones.length ? '<tr><th>Código</th><th>Desc.</th><th>Vence</th><th>Mínimo</th><th>Usos</th><th>Estado</th><th></th></tr>' + D.cupones.map(k => `<tr>
      <td><strong>${esc(k.codigo)}</strong></td><td>${+k.pct}%</td><td>${esc(k.vence || '—')}</td><td>${money(k.minimo)}</td><td>${+k.usos}${k.usos_max ? ' / ' + +k.usos_max : ''}</td><td>${+k.activo ? 'Activo' : 'Inactivo'}</td>
      <td><button class="btn sec mini" data-tog="${esc(k.codigo)}">${+k.activo ? 'Desactivar' : 'Activar'}</button><button class="btn mini" data-del="cupon:${esc(k.codigo)}">Eliminar</button></td></tr>`).join('') : '<tr><td class="vacio">Sin cupones</td></tr>';
    $('#lista-msg').innerHTML = D.mensajes.length ? D.mensajes.map(m => `<div class="msg"><strong>${esc(m.nombre)}</strong> · ${esc(m.asunto)}
      <small>${esc(m.email)}${m.telefono ? ' · ' + esc(m.telefono) : ''} · ${esc(m.creado)}</small><p>${esc(m.mensaje)}</p>
      <div><a class="btn sec mini" href="mailto:${esc(m.email)}">Responder</a> <button class="btn mini" data-del="mensaje:${m.id}">Eliminar</button></div></div>`).join('') : '<div class="caja vacio">Sin mensajes</div>';
  }

  /* ----- Eventos delegados ----- */
  document.addEventListener('click', e => {
    const t = e.target, del = t.dataset.del;
    if (del) { const [tipo, id] = [del.slice(0, del.indexOf(':')), del.slice(del.indexOf(':') + 1)]; if (confirm('¿Eliminar? Esta acción no se puede deshacer.')) run(() => api('borrar', { tipo, id }), 'Eliminado'); }
    if (t.dataset.ren) { const c = D.categorias.find(x => x.id === t.dataset.ren), n = prompt('Nuevo nombre', c.nombre); if (n && n.trim()) run(() => api('guardar', { tipo: 'categoria', data: { id: c.id, nombre: n.trim() } }), 'Categoría actualizada'); }
    if (t.dataset.tog) { const k = D.cupones.find(x => x.codigo === t.dataset.tog); run(() => api('guardar', { tipo: 'cupon', data: { codigo: k.codigo, pct: k.pct, vence: k.vence || '', minimo: k.minimo, usos_max: k.usos_max, activo: !+k.activo } }), 'Cupón actualizado'); }
    if (t.dataset.edit) {
      const p = D.productos.find(x => String(x.id) === t.dataset.edit), F = i => $('#p-' + i);
      F('id').value = p.id; F('nombre').value = p.nombre; F('cat').value = p.categoria_id; F('marca').value = p.marca; F('precio').value = p.precio; setEd(p);
      F('desc').value = p.descr; F('img').value = p.img; F('badge').value = p.badge; F('badgeTipo').value = p.badge_tipo;
      F('dest').checked = !!+p.destacado; F('activo').checked = !!+p.activo; $('#prod-titulo').textContent = 'Editar producto'; scrollTo({ top: 0, behavior: 'smooth' });
    }
  });
  document.addEventListener('change', e => {
    const id = e.target.dataset.estado;
    if (id) run(() => api('pedido_estado', { id: +id, estado: e.target.value }), 'Pedido actualizado');
  });

  /* ----- Formularios ----- */
  /* ----- Editor de stock por talla ----- */
  const rowsEl = $('#tallas-rows');
  const addRow = (t = '', s = 0) => { const d = document.createElement('div'); d.className = 'trow';
    d.innerHTML = `<input class="t-n" placeholder="Talla" maxlength="10" value="${esc(t)}" aria-label="Talla"><input class="t-s" type="number" min="0" value="${+s}" aria-label="Stock de la talla"><button type="button" class="btn mini" data-trm aria-label="Quitar talla">✕</button>`;
    rowsEl.appendChild(d); };
  const modo = sin => { $('#wrap-stock').hidden = !sin; $('#wrap-tallas').hidden = sin; $('#p-sintalla').checked = sin; };
  const getTallas = () => $('#p-sintalla').checked ? [{ talla: '', stock: $('#p-stock').value }]
    : $$('.trow', rowsEl).map(r => ({ talla: $('.t-n', r).value, stock: $('.t-s', r).value }));
  const setEd = p => { rowsEl.innerHTML = ''; const sin = p.tallas.length === 1 && p.tallas[0].talla === '';
    modo(sin); if (sin) $('#p-stock').value = p.tallas[0].stock; else p.tallas.forEach(t => addRow(t.talla, t.stock)); };
  $('#t-add').addEventListener('click', () => addRow());
  $('#t-preset').addEventListener('click', () => { rowsEl.innerHTML = ''; ['S', 'M', 'L', 'XL'].forEach(t => addRow(t, 0)); });
  $('#p-sintalla').addEventListener('change', e => modo(e.target.checked));
  rowsEl.addEventListener('click', e => { if (e.target.dataset.trm !== undefined) e.target.closest('.trow').remove(); });
  const resetProd = () => { $('#form-prod').reset(); $('#p-id').value = ''; $('#p-activo').checked = true; $('#prod-titulo').textContent = 'Nuevo producto'; rowsEl.innerHTML = ''; modo(false); addRow(); };
  resetProd();
  $('#p-cancel').addEventListener('click', resetProd);
  /* ----- Vista previa y confirmación antes de guardar un producto ----- */
  const modal = $('#modal'); let pending = null, lastFocus = null;
  const collectProd = () => { const F = i => $('#p-' + i); return { id: F('id').value || null, nombre: F('nombre').value.trim(), categoria_id: F('cat').value,
    marca: F('marca').value.trim(), descr: F('desc').value.trim(), precio: +F('precio').value, img: F('img').value.trim(), badge: F('badge').value.trim(),
    badge_tipo: F('badgeTipo').value, destacado: F('dest').checked, activo: F('activo').checked,
    tallas: getTallas().map(t => ({ talla: String(t.talla).trim().toUpperCase(), stock: Math.max(0, +t.stock || 0) })) }; };
  const stockPlano = p => p.tallas.length === 1 && p.tallas[0].talla === '' ? p.tallas[0].stock + ' unidades (sin tallas)' : (p.tallas.map(t => `${t.talla}: ${t.stock}`).join(' · ') || '0');
  const DIFF = [['Nombre', p => p.nombre], ['Categoría', p => catName(p.categoria_id)], ['Marca', p => p.marca || '—'], ['Descripción', p => p.descr || '—'],
    ['Precio', p => money(p.precio)], ['Stock', stockPlano], ['Imagen', p => p.img], ['Etiqueta', p => p.badge ? p.badge + (p.badge_tipo === 'oferta' ? ' (oferta)' : '') : '—'],
    ['Más vendidos', p => p.destacado ? 'Sí' : 'No'], ['Visible en la tienda', p => p.activo ? 'Sí' : 'No']];
  const pvCard = (p, titulo) => {
    const tl = p.tallas.filter(t => t.talla !== ''), total = p.tallas.reduce((s, t) => s + t.stock, 0);
    return `<figure class="pv-col"><figcaption>${esc(titulo)}</figcaption><div class="pv-card${p.activo ? '' : ' pv-oculto'}">
      <div class="pv-img"><img src="${esc(p.img)}" alt="${esc(p.nombre)}">${total <= 0 ? '<span class="pv-badge pv-ago">Agotado</span>' : p.badge ? `<span class="pv-badge${p.badge_tipo === 'oferta' ? ' pv-of' : ''}">${esc(p.badge)}</span>` : ''}${p.activo ? '' : '<span class="pv-hid">Oculto en la tienda</span>'}</div>
      <div class="pv-info"><span class="pv-cat">${esc((catName(p.categoria_id) + ' ' + p.marca).trim())}</span><h3>${esc(p.nombre) || '—'}</h3><p>${esc(p.descr)}</p>
      ${tl.length ? `<div class="pv-tallas">${tl.map(t => `<span class="${t.stock <= 0 ? 'pv-t0' : ''}">${esc(t.talla)} · ${t.stock}</span>`).join('')}</div>` : ''}
      <div class="pv-pie"><strong>${money(p.precio)}</strong><span class="pv-btn">Agregar</span></div></div></div></figure>`;
  };
  const openPreview = d => {
    const old = d.id ? D.productos.find(x => String(x.id) === String(d.id)) : null;
    const o = old ? { ...old, precio: +old.precio, destacado: !!+old.destacado, activo: !!+old.activo } : null;
    const filas = DIFF.map(([k, f]) => { const n = f(d), a = o ? f(o) : null, ch = o && String(n) !== String(a);
      return o ? `<tr class="${ch ? 'pv-cambio' : ''}"><td><strong>${esc(k)}</strong></td><td>${esc(a)}</td><td>${esc(n)}</td></tr>`
               : `<tr><td><strong>${esc(k)}</strong></td><td>${esc(n)}</td></tr>`; }).join('');
    $('#modal-cuerpo').innerHTML = `<div class="pv-cards">${o ? pvCard(o, 'Actual') : ''}${pvCard(d, o ? 'Nueva versión' : 'Así se verá en la tienda')}</div>
      <p id="pv-aviso" class="error" hidden>⚠️ No se pudo cargar la imagen. Revisa la ruta antes de guardar.</p>
      <div class="tabla-wrap"><table class="tabla pv-tabla"><tr><th>Campo</th>${o ? '<th>Antes</th><th>Ahora</th>' : '<th>Valor</th>'}</tr>${filas}</table></div>`;
    $$('#modal-cuerpo .pv-img img').forEach(i => i.addEventListener('error', () => { i.classList.add('roto'); $('#pv-aviso').hidden = false; }));
    $('#modal-titulo').textContent = o ? 'Confirma los cambios' : 'Confirma el nuevo producto';
    pending = d; lastFocus = document.activeElement; modal.hidden = false; document.body.classList.add('sin-scroll'); $('#m-ok');
  };
  const closeModal = () => { modal.hidden = true; document.body.classList.remove('sin-scroll'); lastFocus?.focus?.(); };
  $('#form-prod').addEventListener('submit', e => {
    e.preventDefault();
    const d = collectProd();
    if (!$('#p-sintalla').checked && d.tallas.some(t => !t.talla)) return toast('Todas las tallas deben tener nombre', 'error');
    if (!d.tallas.length) return toast('Agrega al menos una talla', 'error');
    if (new Set(d.tallas.map(t => t.talla)).size !== d.tallas.length) return toast('Hay tallas repetidas', 'error');
    openPreview(d);
  });
  $('#m-no').addEventListener('click', closeModal);
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  $('#m-ok').addEventListener('click', () => {
    const d = pending; if (!d) return; pending = null; closeModal();
    run(async () => { await api('guardar', { tipo: 'producto', data: d }); resetProd(); }, 'Producto guardado');
  });
  document.addEventListener('keydown', e => {
    if (modal.hidden) return;
    if (e.key === 'Escape') closeModal();
    if (e.key === 'Tab') { e.preventDefault(); const f = [$('#m-no'), $('#m-ok')]; f[(f.indexOf(document.activeElement) + 1) % 2].focus(); }
  });
  $('#p-file').addEventListener('change', async e => {
    const f = e.target.files[0]; if (!f) return;
    const fd = new FormData(); fd.append('img', f);
    try { const res = await fetch(`${API}?r=upload`, { method: 'POST', headers: { 'X-CSRF': CSRF }, body: fd }), j = await res.json(); if (!res.ok) throw new Error(j.error); $('#p-img').value = j.ruta; toast('Imagen subida'); }
    catch (err) { toast(err.message, 'error'); } e.target.value = '';
  });
  $('#form-cat').addEventListener('submit', e => { e.preventDefault(); run(async () => { await api('guardar', { tipo: 'categoria', data: { nombre: $('#c-nombre').value } }); e.target.reset(); }, 'Categoría creada'); });
  $('#form-cup').addEventListener('submit', e => { e.preventDefault(); run(async () => { await api('guardar', { tipo: 'cupon', data: { codigo: $('#k-codigo').value, pct: $('#k-pct').value, vence: $('#k-vence').value, minimo: $('#k-min').value, usos_max: $('#k-max').value, activo: true } }); e.target.reset(); }, 'Cupón guardado'); });
  $('#form-clave').addEventListener('submit', e => { e.preventDefault(); run(async () => { await api('clave', { actual: $('#clave-act').value, nueva: $('#clave-nueva').value }); e.target.reset(); }, 'Contraseña actualizada'); });
})();
