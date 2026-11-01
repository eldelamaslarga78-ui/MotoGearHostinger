/* Formato común: dinero COP, números, fechas y escape de HTML. */

export const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => (
  { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
));

export function money(n) {
  if (n === null || n === undefined || Number.isNaN(Number(n))) return '—';
  return '$' + Math.round(Number(n)).toLocaleString('es-CO');
}

export function numero(n) {
  if (n === null || n === undefined || Number.isNaN(Number(n))) return '—';
  return Number(n).toLocaleString('es-CO');
}

export function pct(n) {
  if (n === null || n === undefined || Number.isNaN(Number(n))) return '—';
  return Number(n).toLocaleString('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' %';
}

export function variacion(n) {
  if (n === null || n === undefined) return '—';
  const signo = n > 0 ? '+' : '';
  return signo + Number(n).toLocaleString('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' %';
}

/** '2026-10-30' → '30/10' */
export function diaCorto(iso) {
  const s = String(iso || '');
  return s.length >= 10 ? s.slice(8, 10) + '/' + s.slice(5, 7) : s;
}

/** '2026-10-30 14:05:00' → '30/10 · 14:05' */
export function fechaHora(v) {
  const s = String(v || '');
  if (s.length < 16) return s || '—';
  return s.slice(8, 10) + '/' + s.slice(5, 7) + ' · ' + s.slice(11, 16);
}

/** Hoy en Colombia, sin depender de la zona horaria del navegador. */
export function hoyBogota() {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/Bogota',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date());
}

/** Fecha larga legible: '30 de octubre de 2026'. */
export function fechaLarga(iso) {
  const [y, m, d] = String(iso || '').split('-').map(Number);
  if (!y || !m || !d) return String(iso || '');
  return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('es-CO', {
    timeZone: 'UTC', day: 'numeric', month: 'long', year: 'numeric',
  });
}
