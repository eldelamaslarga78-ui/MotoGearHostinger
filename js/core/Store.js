/* Estado global reactivo: carrito, cupón y caché de catálogo (sincronizado a localStorage). */
const CART_KEY = 'mg-cart2';
const COUPON_KEY = 'mg-coupon';

function cargar() {
  try { const c = JSON.parse(localStorage.getItem(CART_KEY)); return Array.isArray(c) ? c : []; } catch { return []; }
}

export class Store {
  constructor() {
    this.cart = cargar();
    this.coupon = null;                    // cupón validado por la API
    this.couponCode = localStorage.getItem(COUPON_KEY) || '';
    this.catalog = null;                   // { categorias, productos } cacheado
    this._listeners = new Set();
  }

  subscribe(fn) { this._listeners.add(fn); return () => this._listeners.delete(fn); }
  emit() { this._listeners.forEach((fn) => fn()); }

  guardar() {
    try { localStorage.setItem(CART_KEY, JSON.stringify(this.cart)); } catch { /* sin storage */ }
    this.emit();
  }

  count() { return this.cart.reduce((t, i) => t + i.qty, 0); }

  producto(id) { return this.catalog ? this.catalog.productos.find((p) => p.id === +id) : null; }
  stockDe(p, talla) { return p ? (p.tallas.find((x) => x.talla === talla) || { stock: 0 }).stock : 0; }

  agregar(id, talla = '') {
    const f = this.cart.find((i) => i.id === id && i.talla === talla);
    if (f) f.qty++; else this.cart.push({ id, qty: 1, talla });
    this.guardar();
  }

  cantidad(id, talla = '') {
    return this.cart.filter((i) => i.id === id && i.talla === talla).reduce((t, i) => t + i.qty, 0);
  }

  fijarCantidad(id, talla = '', qty) {
    const f = this.cart.find((i) => i.id === id && i.talla === talla);
    if (f) { f.qty = Math.max(1, qty); this.guardar(); }
  }

  quitar(id, talla = '') {
    this.cart = this.cart.filter((i) => !(i.id === id && i.talla === talla));
    this.guardar();
  }

  vaciar() {
    this.cart = [];
    this.coupon = null;
    this.couponCode = '';
    localStorage.removeItem(COUPON_KEY);
    this.guardar();
  }

  fijarCupon(coupon, codigo) {
    this.coupon = coupon;
    this.couponCode = codigo || '';
    if (codigo) localStorage.setItem(COUPON_KEY, codigo);
    else localStorage.removeItem(COUPON_KEY);
    this.emit();
  }
}
