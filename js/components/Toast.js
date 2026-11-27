/* Notificación toast. tipo: 'ok' | 'error'. */
let temporizador = null;

export function toast(mensaje, tipo = 'ok') {
  let t = document.querySelector('.toast');
  if (!t) { t = document.createElement('div'); t.className = 'toast'; document.body.appendChild(t); }
  t.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
  t.classList.remove('ok', 'error');
  t.classList.add(tipo, 'show');
  t.textContent = mensaje;
  clearTimeout(temporizador);
  temporizador = setTimeout(() => t.classList.remove('show'), tipo === 'error' ? 6000 : 4000);
}
