/* Cliente único de la API. Adjunta CSRF y traduce los errores del servidor. */

let CSRF = '';

export function setCsrf(token) {
  CSRF = token || '';
}

export function csrfToken() {
  return CSRF;
}

export class ApiError extends Error {
  constructor(mensaje, estado, datos) {
    super(mensaje);
    this.name = 'ApiError';
    this.estado = estado;
    this.datos = datos;
  }
}

const ESPERA_MS = 12000;

function url(ruta, query) {
  let u = '../api/api.php?r=' + encodeURIComponent(ruta);
  if (query && Object.keys(query).length) {
    u += '&' + new URLSearchParams(query).toString();
  }
  return u;
}

async function pedir(u, init) {
  const control = new AbortController();
  const reloj = setTimeout(() => control.abort(), ESPERA_MS);
  try {
    return await fetch(u, { ...init, signal: control.signal, credentials: 'same-origin' });
  } finally {
    clearTimeout(reloj);
  }
}

/**
 * Llamada a la API.
 * @param {string} ruta  valor de ?r=
 * @param {{cuerpo?:object, query?:object, metodo?:string}} opciones
 */
export async function api(ruta, opciones = {}) {
  const esPost = opciones.cuerpo !== undefined || opciones.metodo === 'POST';
  const init = {
    method: esPost ? 'POST' : 'GET',
    headers: { Accept: 'application/json' },
    cache: 'no-store',
  };

  if (esPost) {
    init.headers['Content-Type'] = 'application/json';
    init.headers['X-CSRF'] = CSRF;
    init.body = JSON.stringify(opciones.cuerpo || {});
  }

  let res;
  try {
    res = await pedir(url(ruta, opciones.query), init);
  } catch {
    // Un reintento para cortes momentáneos de red; nunca para 4xx.
    try {
      res = await pedir(url(ruta, opciones.query), init);
    } catch {
      throw new ApiError('Sin conexión con el servidor. Revisa tu red.', 0, null);
    }
  }

  let datos = null;
  try {
    datos = await res.json();
  } catch {
    datos = null;
  }

  if (res.status === 401) {
    window.dispatchEvent(new CustomEvent('mg:sesion-expirada'));
    throw new ApiError('Tu sesión expiró. Vuelve a entrar.', 401, datos);
  }

  if (!res.ok) {
    const base = (datos && typeof datos.error === 'string' && datos.error)
      ? datos.error
      : 'No se pudo completar la solicitud.';
    // Si el servidor devolvió HTML (404, 500, 502 de un proxy) el estado es la única pista útil.
    const mensaje = res.status >= 500 ? base + ' (HTTP ' + res.status + ')' : base;
    throw new ApiError(mensaje, res.status, datos);
  }

  return datos;
}
