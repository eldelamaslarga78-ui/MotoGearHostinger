/* Catálogo cacheado (una sola petición por sesión). */
import { api } from './ApiClient.js';

export async function obtenerCatalogo(store) {
  if (store.catalog) return store.catalog;
  const datos = await api('catalogo');
  store.catalog = datos;
  return datos;
}
