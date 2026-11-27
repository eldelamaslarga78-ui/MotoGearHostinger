/* Formato compartido de la tienda. */
export const money = (n) => '$' + Math.round(Number(n) || 0).toLocaleString('es-CO');

export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/* La API devuelve rutas relativas "./img/...". Con History API conviene absolutas. */
export const imgSrc = (r) => (r ? String(r).replace(/^\.\//, '/') : '');
