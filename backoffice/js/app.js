/* Arranque del backoffice: sesión, router por hash, notificaciones y búsqueda. */

import { api, setCsrf } from './api.js';
import { toast } from './ui/toast.js';
import { esc } from './formato.js';

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];

const VISTAS = {
  dashboard: { titulo: 'Resumen', permiso: 'dashboard.ver', modulo: () => import('./vistas/dashboard.js') },
  pedidos: { titulo: 'Pedidos', permiso: 'pedidos.ver', fase: 3, modulo: () => import('./vistas/pedidos.js'), desc: 'Bandeja con filtros, detalle y máquina de estados.' },
  factura: { titulo: 'Factura', permiso: 'facturas.ver', fase: 3, modulo: () => import('./vistas/factura.js'), desc: 'Factura imprimible.' },
  carritos: { titulo: 'Carritos', permiso: 'carritos.ver', fase: 5, modulo: () => import('./vistas/marketing.js'), desc: 'Carritos abandonados y recuperación.' },
  productos: { titulo: 'Productos', permiso: 'productos.ver', fase: 2, modulo: () => import('./vistas/productos.js'), desc: 'CRUD completo con variantes, imágenes y precios.' },
  producto: { titulo: 'Producto', permiso: 'productos.editar', fase: 2, modulo: () => import('./vistas/producto.js'), desc: 'Ficha de producto.' },
  categorias: { titulo: 'Categorías', permiso: 'categorias.editar', fase: 2, modulo: () => import('./vistas/categorias.js'), desc: 'Árbol de categorías y orden del catálogo.' },
  inventario: { titulo: 'Inventario', permiso: 'inventario.ajustar', fase: 2, modulo: () => import('./vistas/inventario.js'), desc: 'Ajustes de stock con libro mayor y alertas.' },
  clientes: { titulo: 'Clientes', permiso: 'clientes.ver', fase: 4, modulo: () => import('./vistas/clientes.js'), desc: 'Ficha única con pedidos, direcciones y RMA.' },
  devoluciones: { titulo: 'Devoluciones', permiso: 'devoluciones.ver', fase: 4, modulo: () => import('./vistas/devoluciones.js'), desc: 'RMA con estados y reembolsos.' },
  mensajes: { titulo: 'Mensajes', permiso: 'mensajes.ver', fase: 1, legado: true, desc: 'Formulario de contacto y respuestas.' },
  cupones: { titulo: 'Cupones', permiso: 'cupones.ver', fase: 1, modulo: () => import('./vistas/marketing.js'), desc: 'Códigos, vigencias y usos.' },
  promociones: { titulo: 'Promociones', permiso: 'promociones.ver', fase: 5, modulo: () => import('./vistas/marketing.js'), desc: 'Descuentos automáticos por producto o categoría.' },
  pagos: { titulo: 'Pagos', permiso: 'pagos.ver', fase: 6, desc: 'Pasarela, conciliación y reembolsos.' },
  usuarios: { titulo: 'Usuarios', permiso: 'usuarios.gestionar', fase: 1, desc: 'Roles, permisos y acceso del equipo.' },
  seguridad: { titulo: 'Seguridad', permiso: 'usuarios.gestionar', fase: 6, modulo: () => import('./vistas/seguridad.js'), desc: 'Autenticación en dos pasos (2FA).' },
  auditoria: { titulo: 'Auditoría', permiso: 'auditoria.ver', fase: 1, desc: 'Registro inmutable de cambios.' },
  configuracion: { titulo: 'Configuración', permiso: 'config.editar', fase: 6, desc: 'Datos de la tienda, envíos e impuestos.' },
};

let PERMS = [];
let vistaActual = null;
const puede = (p) => PERMS.includes('*') || PERMS.includes(p);

/* ------------------------------- Sesión -------------------------------- */

const acceso = () => $('#acceso');
const app = () => $('#app');

function mostrarAcceso(mensaje = '') {
  app().hidden = true;
  acceso().hidden = false;
  const err = $('#acceso-error');
  err.hidden = !mensaje;
  err.textContent = mensaje;
  $('#clave').value = '';
  setTimeout(() => $('#usuario').focus(), 30);
}

function mostrarApp() {
  acceso().hidden = true;
  app().hidden = false;
}

async function cargarSesion() {
  const me = await api('me');
  if (!me.admin) return false;
  PERMS = Array.isArray(me.permisos) ? me.permisos : [];
  setCsrf(me.csrf);
  $('#usuario-nombre').textContent = me.nombre || me.usuario || '';
  aplicarPermisos();
  return true;
}

function aplicarPermisos() {
  $$('.nav-item').forEach((a) => {
    const p = a.dataset.permiso;
    a.hidden = Boolean(p && !puede(p));
  });
  const activo = $('.nav-item.activo');
  if (activo && activo.hidden) activo.classList.remove('activo');
}

/* -------------------------------- Router ------------------------------- */

function rutaActual() {
  const h = location.hash.replace(/^#\/?/, '');
  const [nombre, param] = h.split('/');
  return { nombre: nombre || 'dashboard', param: param || '' };
}

function ir(ruta) {
  if (location.hash === '#/' + ruta) {
    render();
  } else {
    location.hash = '#/' + ruta;
  }
}

function vistaNoDisponible(ruta, motivo) {
  return {
    titulo: ruta.titulo,
    montar(el) {
      el.innerHTML = `<div class="barra-vista"><div class="titulo-bloque">
          <h2>${esc(ruta.titulo)}</h2><p class="nota">${esc(motivo)}</p>
        </div></div>
        <div class="tarjeta"><div class="estado-vacio">
          <strong>${esc(ruta.titulo)}</strong>
          <span>${esc(ruta.desc || '')}</span>
          ${ruta.fase ? `<span class="nota">Planificado para la Fase ${ruta.fase}.</span>` : ''}
          ${ruta.legado ? '<a class="btn sec mini" href="../admin.html" target="_blank" rel="noopener">Abrir en el panel anterior</a>' : ''}
        </div></div>`;
    },
    desmontar() {},
  };
}

async function render() {
  const { nombre } = rutaActual();
  const ruta = VISTAS[nombre];
  const main = $('#vista');

  if (!ruta) {
    $('#titulo').textContent = 'No encontrado';
    document.title = 'No encontrado · MotoGear';
    main.innerHTML = `<div class="tarjeta"><div class="estado-vacio">
      <strong>Esa sección no existe</strong>
      <a class="btn sec mini" href="#/dashboard">Ir al resumen</a>
    </div></div>`;
    return;
  }

  $$('.nav-item').forEach((a) => a.classList.toggle('activo', a.dataset.ruta === nombre));

  if (vistaActual && vistaActual.desmontar) {
    try { vistaActual.desmontar(); } catch { /* no interrumpir la navegación */ }
  }
  vistaActual = null;

  if (!puede(ruta.permiso)) {
    vistaActual = vistaNoDisponible(ruta, 'Tu rol no tiene acceso a esta sección.');
    $('#titulo').textContent = ruta.titulo;
    document.title = ruta.titulo + ' · MotoGear';
    vistaActual.montar(main);
    return;
  }

  $('#titulo').textContent = ruta.titulo;
  document.title = ruta.titulo + ' · MotoGear';
  cerrarMenu();

  if (!ruta.modulo) {
    vistaActual = vistaNoDisponible(ruta, 'Esta sección todavía no está en el backoffice nuevo.');
    vistaActual.montar(main);
    return;
  }

  main.innerHTML = '<div class="tarjeta"><div class="esqueleto"><div class="esqueleto-linea alta"></div><div class="esqueleto-linea"></div></div></div>';
  try {
    const mod = await ruta.modulo();
    vistaActual = mod.default;
    await vistaActual.montar(main);
  } catch (e) {
    main.innerHTML = `<div class="tarjeta"><div class="estado-vacio" role="alert">
      <strong>No se pudo abrir la sección</strong>
      <span>${esc(e.message || 'Error inesperado')}</span>
      <a class="btn sec mini" href="#/dashboard">Volver al resumen</a>
    </div></div>`;
  }
}

/* --------------------------- Notificaciones ---------------------------- */

let notificaciones = { no_leidas: 0, items: [] };

function pintarGlobo() {
  const g = $('#notif-globo');
  if (!g) return;
  const n = notificaciones.no_leidas || 0;
  g.hidden = n === 0;
  g.textContent = n > 99 ? '99+' : String(n);
  $('#btn-notif').setAttribute('aria-label', n ? `Notificaciones (${n} sin leer)` : 'Notificaciones');
}

function pintarNotificaciones() {
  const lista = $('#notif-lista');
  if (!notificaciones.items.length) {
    lista.innerHTML = '<p class="nota">No hay notificaciones.</p>';
    return;
  }
  lista.innerHTML = notificaciones.items.map((n) => {
    const cuerpo = n.cuerpo ? `<span class="notif-cuerpo">${esc(n.cuerpo)}</span>` : '';
    const contenido = `<strong>${esc(n.titulo)}</strong>${cuerpo}<small>${esc(String(n.creado || '').slice(0, 16))}</small>`;
    const clase = `notif${Number(n.leida) ? '' : ' no-leida'}`;
    return n.url
      ? `<a class="${clase}" href="${esc(n.url)}">${contenido}</a>`
      : `<div class="${clase}">${contenido}</div>`;
  }).join('');
}

async function cargarNotificaciones() {
  try {
    notificaciones = await api('notificaciones');
    pintarGlobo();
    if (!$('#panel-notif').hidden) pintarNotificaciones();
  } catch { /* silencioso: no bloquea el panel */ }
}

function alternarNotificaciones(abrir) {
  const panel = $('#panel-notif');
  const visible = typeof abrir === 'boolean' ? abrir : panel.hidden;
  panel.hidden = !visible;
  $('#btn-notif').setAttribute('aria-expanded', String(visible));
  if (visible) {
    pintarNotificaciones();
    cargarNotificaciones();
  }
}

/* ------------------------------- Paleta -------------------------------- */

let relojBusqueda = null;

function abrirPaleta(abrir = true) {
  const p = $('#paleta');
  if (p.hidden === !abrir) return;
  p.hidden = !abrir;
  const input = $('#paleta-input');
  if (abrir) {
    input.value = '';
    $('#paleta-lista').innerHTML = '';
    input.focus();
  }
}

function pintarResultados(items) {
  const lista = $('#paleta-lista');
  if (!items.length) {
    lista.innerHTML = '<li><span class="p-vacio">Sin resultados</span></li>';
    return;
  }
  lista.innerHTML = items.map((it) => `
    <li><a href="${esc(it.url)}">
      <span class="p-tipo">${esc(it.tipo)}</span>
      <span class="p-titulo">${esc(it.titulo)}</span>
      <span class="p-detalle">${esc(it.detalle || '')}</span>
    </a></li>`).join('');
}

async function buscar(texto) {
  if (texto.trim().length < 2) {
    $('#paleta-lista').innerHTML = '';
    return;
  }
  try {
    const r = await api('buscar', { query: { q: texto } });
    pintarResultados(r.items || []);
  } catch (e) {
    $('#paleta-lista').innerHTML = `<li><span class="p-vacio">${esc(e.message)}</span></li>`;
  }
}

/* ------------------------------- Menú ---------------------------------- */

function abrirMenu(abrir) {
  const l = $('#lateral');
  l.classList.toggle('abierto', abrir);
  $('#velo').hidden = !abrir;
  $('#menu').setAttribute('aria-expanded', String(abrir));

  // En móvil el menú tapa la pantalla: el foco debe entrar y volver al botón.
  if (!window.matchMedia('(max-width: 1024px)').matches) return;
  if (abrir) {
    const primero = l.querySelector('a[href]');
    if (primero) primero.focus();
  } else if (l.contains(document.activeElement)) {
    $('#menu').focus();
  }
}

function cerrarMenu() {
  if (window.matchMedia('(max-width: 1024px)').matches) abrirMenu(false);
}

/* ------------------------------ Arranque ------------------------------- */

function conectar() {
  $('#form-acceso').addEventListener('submit', async (e) => {
    e.preventDefault();
    const boton = $('#btn-acceso');
    boton.disabled = true;
    try {
      const r = await api('login', {
        cuerpo: { usuario: $('#usuario').value.trim(), clave: $('#clave').value },
      });
      setCsrf(r.csrf);
      if (!(await cargarSesion())) {
        mostrarAcceso('Este usuario no tiene acceso al backoffice.');
        return;
      }
      mostrarApp();
      if (!location.hash) ir('dashboard'); else render();
      cargarNotificaciones();
    } catch (err) {
      mostrarAcceso(err.message);
    } finally {
      boton.disabled = false;
    }
  });

  $('#btn-salir').addEventListener('click', async () => {
    try { await api('logout', { cuerpo: {} }); } catch { /* la sesión ya no existe */ }
    PERMS = [];
    location.hash = '';
    location.reload();
  });

  window.addEventListener('hashchange', render);

  window.addEventListener('mg:sesion-expirada', () => {
    mostrarAcceso('Tu sesión expiró. Vuelve a entrar.');
  });

  $('#menu').addEventListener('click', () => abrirMenu(!$('#lateral').classList.contains('abierto')));
  $('#velo').addEventListener('click', () => abrirMenu(false));

  $('#btn-notif').addEventListener('click', () => alternarNotificaciones());
  $('#notif-leer').addEventListener('click', async () => {
    try {
      await api('notificacion_leer', { cuerpo: { todas: true } });
      await cargarNotificaciones();
      toast('Notificaciones marcadas como leídas');
    } catch (e) { toast(e.message, 'error'); }
  });

  $('#btn-buscar').addEventListener('click', () => abrirPaleta(true));
  $('#paleta').addEventListener('click', (e) => {
    if (e.target.id === 'paleta') abrirPaleta(false);
  });
  $('#paleta-lista').addEventListener('click', () => abrirPaleta(false));
  $('#paleta-input').addEventListener('input', (e) => {
    clearTimeout(relojBusqueda);
    const valor = e.target.value;
    relojBusqueda = setTimeout(() => buscar(valor), 250);
  });

  document.addEventListener('keydown', (e) => {
    const escribiendo = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName || '');

    if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) {
      e.preventDefault();
      abrirPaleta($('#paleta').hidden);
      return;
    }
    if (e.key === '/' && !escribiendo && $('#paleta').hidden) {
      e.preventDefault();
      abrirPaleta(true);
      return;
    }
    if (e.key === 'Escape') {
      if (!$('#paleta').hidden) abrirPaleta(false);
      else if (!$('#panel-notif').hidden) alternarNotificaciones(false);
      else cerrarMenu();
    }
    if (e.key === 'Enter' && !$('#paleta').hidden) {
      const primero = $('#paleta-lista a');
      if (primero) { primero.click(); }
    }
  });

  document.addEventListener('click', (e) => {
    const panel = $('#panel-notif');
    if (panel.hidden) return;
    if (e.target.closest('#panel-notif') || e.target.closest('#btn-notif')) return;
    alternarNotificaciones(false);
  });
}

(async function inicio() {
  conectar();
  // ?diagnostico=1 muestra en pantalla las llamadas a la API y los errores de JS.
  // Se espera la carga para espiar fetch antes de la primera petición.
  if (new URLSearchParams(location.search).has('diagnostico')) {
    try {
      const m = await import('./diagnostico.js');
      m.iniciarDiagnostico();
    } catch { /* el diagnóstico nunca debe impedir el arranque */ }
  }
  try {
    if (await cargarSesion()) {
      mostrarApp();
      if (!location.hash) location.replace('#/dashboard');
      await render();
      cargarNotificaciones();
      setInterval(() => {
        if (document.visibilityState === 'visible') cargarNotificaciones();
      }, 120000);
    } else {
      mostrarAcceso();
    }
  } catch {
    mostrarAcceso();
  }
})();
