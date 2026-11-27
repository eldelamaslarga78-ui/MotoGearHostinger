/* Enrutador SPA con History API. Rutas: '/', '/productos', '/producto/:id', ... */
export class Router {
  constructor(rutas, ctx = {}) {
    this.rutas = rutas; // [{ path: '/', view }, ...]
    this.ctx = ctx;     // { store, ... } compartido con las vistas
  }

  match(pathname) {
    for (const ruta of this.rutas) {
      const claves = [];
      const regex = new RegExp(
        '^' + ruta.path.replace(/:[^/]+/g, (m) => { claves.push(m.slice(1)); return '([^/]+)'; }) + '$'
      );
      const m = pathname.match(regex);
      if (m) {
        const params = {};
        claves.forEach((k, i) => { params[k] = decodeURIComponent(m[i + 1]); });
        return { view: ruta.view, params };
      }
    }
    return null;
  }

  navigate(path) {
    if (location.pathname === path) { this.resolve(); return; }
    history.pushState({}, '', path);
    this.resolve();
  }

  async resolve() {
    const r = this.match(location.pathname);
    const app = document.getElementById('app');
    if (!r) {
      app.innerHTML = '<div class="carrito-vacio"><strong>Página no encontrada</strong><p><a href="/">Volver al inicio</a></p></div>';
      document.title = 'No encontrado · MotoGear Bogotá';
      return;
    }
    try {
      await r.view.render(app, r.params, this.ctx);
    } catch (e) {
      app.innerHTML = '<div class="carrito-vacio"><strong>No se pudo cargar la página</strong></div>';
      console.error(e);
    }
    window.scrollTo(0, 0);
    if (typeof this.ctx.onRoute === 'function') this.ctx.onRoute(location.pathname);
  }

  start() {
    window.addEventListener('popstate', () => this.resolve());
    document.addEventListener('click', (e) => {
      const a = e.target.closest('a');
      if (!a) return;
      const href = a.getAttribute('href');
      if (!href || href.startsWith('http') || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:')) return;
      if (a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('rel')) return;
      e.preventDefault();
      this.navigate(href);
    });
    this.resolve();
  }
}
