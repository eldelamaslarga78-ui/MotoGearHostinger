/* Cliente HTTP centralizado de la tienda pública. */
const BASE = '/api/api.php';

export class ApiError extends Error {
  constructor(mensaje, estado) { super(mensaje); this.name = 'ApiError'; this.estado = estado; }
}

export async function api(r, cuerpo) {
  let res;
  try {
    res = cuerpo !== undefined
      ? await fetch(`${BASE}?r=${r}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cuerpo) })
      : await fetch(`${BASE}?r=${r}`);
  } catch {
    throw new ApiError('Sin conexión con el servidor', 0);
  }
  const datos = await res.json().catch(() => ({ error: 'Respuesta inválida del servidor' }));
  if (!res.ok) throw new ApiError(datos.error || 'Error de conexión', res.status);
  return datos;
}
