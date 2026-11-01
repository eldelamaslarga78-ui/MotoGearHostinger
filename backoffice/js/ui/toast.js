/* Aviso flotante reutilizable. Nunca usa innerHTML con datos del servidor. */

let reloj = null;
const el = () => document.getElementById('toast');

export function toast(mensaje, tipo = 'ok') {
  const t = el();
  if (!t) return;

  t.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
  t.classList.remove('ok', 'error', 'visible');
  t.classList.add(tipo);
  t.textContent = String(mensaje ?? '');
  // Forzar reinicio de la transición.
  void t.offsetWidth;
  t.classList.add('visible');

  clearTimeout(reloj);
  reloj = setTimeout(() => t.classList.remove('visible'), 4000);
}
