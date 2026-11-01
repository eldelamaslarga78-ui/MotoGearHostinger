/* Estados de carga, vacío y error. Nunca se deja un panel en blanco. */

export function esqueletoKpis(cantidad = 5) {
  return `<div class="kpis">${Array.from({ length: cantidad }, () => `
    <div class="kpi"><div class="esqueleto">
      <div class="esqueleto-linea corta"></div>
      <div class="esqueleto-linea alta"></div>
      <div class="esqueleto-linea corta"></div>
    </div></div>`).join('')}</div>
  <div class="rejilla-graficos">
    ${Array.from({ length: 3 }, (_, i) => `
      <div class="tarjeta ${i === 2 ? 'col-4' : 'col-6'}"><div class="esqueleto">
        <div class="esqueleto-linea corta"></div>
        <div class="esqueleto-linea alta"></div>
        <div class="esqueleto-linea alta"></div>
      </div></div>`).join('')}
  </div>`;
}

export function vacio(titulo, texto = '', accion = null) {
  return `<div class="estado-vacio">
    <strong>${titulo}</strong>
    ${texto ? `<span>${texto}</span>` : ''}
    ${accion ? `<button class="btn sec mini" type="button" data-accion="${accion.id}">${accion.texto}</button>` : ''}
  </div>`;
}

export function panelError(mensaje, reintento = 'reintentar') {
  return `<div class="estado-vacio" role="alert">
    <strong>No se pudieron cargar los datos</strong>
    <span>${mensaje}</span>
    <button class="btn sec mini" type="button" data-accion="${reintento}">Reintentar</button>
  </div>`;
}

export function filasEsqueleto(cantidad = 4) {
  return `<div class="esqueleto">${Array.from({ length: cantidad }, () => '<div class="esqueleto-linea"></div>').join('')}</div>`;
}
