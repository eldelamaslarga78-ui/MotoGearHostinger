/* Gráficos SVG propios. Sin librerías, sin CDN y accesibles (role="img" + <desc>). */

import { esc, numero, money, diaCorto, fechaLarga } from '../formato.js';

let seq = 0;
const COLORES = ['#880000', '#1a56db', '#b45309', '#1e7e34', '#5b21b6', '#c9ced5', '#0f766e'];

const W = 640;
const H = 220;

function ejeValor(n, tipo) {
  if (tipo === 'money') {
    if (n >= 1000000) return '$' + (n / 1000000).toFixed(n >= 10000000 ? 0 : 1).replace('.', ',') + ' M';
    if (n >= 1000) return '$' + Math.round(n / 1000) + ' k';
    return '$' + Math.round(n);
  }
  return numero(Math.round(n));
}

function fechaTexto(iso) {
  const [y, m, d] = String(iso).split('-').map(Number);
  return y && m && d ? fechaLarga(iso) : String(iso);
}

function ids() {
  seq += 1;
  return { titulo: 'g-t-' + seq, desc: 'g-d-' + seq };
}

/**
 * Serie temporal como línea con área.
 * @param {{serie:Array, campo:string, titulo:string, descripcion:string, tipo?:'money'|'num'}} o
 */
export function linea({ serie, campo, titulo, descripcion, tipo = 'num' }) {
  if (!Array.isArray(serie) || !serie.length) {
    return `<p class="nota">Sin datos en el periodo.</p>`;
  }

  const id = ids();
  const pad = { t: 14, r: 12, b: 24, l: 52 };
  const ancho = W - pad.l - pad.r;
  const alto = H - pad.t - pad.b;
  const valores = serie.map((d) => Number(d[campo]) || 0);
  const max = Math.max(1, ...valores);
  const n = serie.length;
  const x = (i) => pad.l + (n === 1 ? ancho / 2 : (i * ancho) / (n - 1));
  const y = (v) => pad.t + alto - (v / max) * alto;

  let rejilla = '';
  for (let i = 0; i <= 4; i++) {
    const v = (max / 4) * i;
    const yy = y(v).toFixed(1);
    rejilla += `<line class="g-eje" x1="${pad.l}" y1="${yy}" x2="${W - pad.r}" y2="${yy}"></line>`;
    rejilla += `<text class="g-texto" x="${pad.l - 8}" y="${Number(yy) + 3}" text-anchor="end">${esc(ejeValor(v, tipo))}</text>`;
  }

  const puntos = valores.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`);
  const area = `M ${x(0).toFixed(1)} ${y(valores[0]).toFixed(1)} `
    + valores.map((v, i) => `L ${x(i).toFixed(1)} ${y(v).toFixed(1)}`).join(' ')
    + ` L ${x(n - 1).toFixed(1)} ${(pad.t + alto).toFixed(1)} L ${x(0).toFixed(1)} ${(pad.t + alto).toFixed(1)} Z`;

  const puntosSvg = n <= 62
    ? valores.map((v, i) => `<circle class="g-punto" cx="${x(i).toFixed(1)}" cy="${y(v).toFixed(1)}" r="3">`
        + `<title>${esc(fechaTexto(serie[i].fecha))}: ${esc(tipo === 'money' ? money(v) : numero(v))}</title></circle>`).join('')
    : '';

  const marcas = n === 1 ? [0] : [0, Math.floor((n - 1) / 2), n - 1];
  const ejeX = [...new Set(marcas)].map((i) => (
    `<text class="g-texto" x="${x(i).toFixed(1)}" y="${H - 6}" text-anchor="${i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'}">${esc(diaCorto(serie[i].fecha))}</text>`
  )).join('');

  return `<figure class="grafico">
    <svg viewBox="0 0 ${W} ${H}" role="img" aria-labelledby="${id.titulo}">
      <title id="${id.titulo}">${esc(titulo)}</title>
      <desc id="${id.desc}">${esc(descripcion)}</desc>
      ${rejilla}
      <path class="g-area" d="${area}"></path>
      <polyline class="g-linea" points="${puntos.join(' ')}"></polyline>
      ${puntosSvg}
      ${ejeX}
    </svg>
  </figure>`;
}

/**
 * Barras agrupadas (por ejemplo pedidos vs visitas).
 * @param {{serie:Array, campos:Array<{campo:string,etiqueta:string,clase?:string}>, titulo:string, descripcion:string}} o
 */
export function barras({ serie, campos, titulo, descripcion }) {
  if (!Array.isArray(serie) || !serie.length) {
    return `<p class="nota">Sin datos en el periodo.</p>`;
  }

  const id = ids();
  const pad = { t: 14, r: 12, b: 24, l: 44 };
  const ancho = W - pad.l - pad.r;
  const alto = H - pad.t - pad.b;
  const n = serie.length;
  const max = Math.max(1, ...serie.flatMap((d) => campos.map((c) => Number(d[c.campo]) || 0)));
  const grupo = ancho / n;
  const barra = Math.max(2, Math.min(18, (grupo * 0.62) / campos.length));

  let rejilla = '';
  for (let i = 0; i <= 4; i++) {
    const v = (max / 4) * i;
    const yy = (pad.t + alto - (v / max) * alto).toFixed(1);
    rejilla += `<line class="g-eje" x1="${pad.l}" y1="${yy}" x2="${W - pad.r}" y2="${yy}"></line>`;
    rejilla += `<text class="g-texto" x="${pad.l - 8}" y="${Number(yy) + 3}" text-anchor="end">${esc(ejeValor(v, 'num'))}</text>`;
  }

  let cuerpo = '';
  serie.forEach((d, i) => {
    const base = pad.l + grupo * i + grupo / 2;
    campos.forEach((c, j) => {
      const v = Number(d[c.campo]) || 0;
      const hh = (v / max) * alto;
      const xx = base - (barra * campos.length) / 2 + barra * j;
      cuerpo += `<rect class="${c.clase || 'g-barra'}" x="${xx.toFixed(1)}" y="${(pad.t + alto - hh).toFixed(1)}" width="${barra.toFixed(1)}" height="${Math.max(0, hh).toFixed(1)}" rx="2">`
        + `<title>${esc(fechaLarga(d.fecha))} · ${esc(c.etiqueta)}: ${esc(numero(v))}</title></rect>`;
    });
  });

  const marcas = n === 1 ? [0] : [0, Math.floor((n - 1) / 2), n - 1];
  const ejeX = [...new Set(marcas)].map((i) => (
    `<text class="g-texto" x="${(pad.l + grupo * i + grupo / 2).toFixed(1)}" y="${H - 6}" text-anchor="middle">${esc(diaCorto(serie[i].fecha))}</text>`
  )).join('');

  const leyenda = `<div class="leyenda">${campos.map((c, j) => (
    `<span><i class="${j === 0 ? 'c1' : 'c6'}"></i>${esc(c.etiqueta)}</span>`
  )).join('')}</div>`;

  return `<div class="grafico">
    <svg viewBox="0 0 ${W} ${H}" role="img" aria-labelledby="${id.titulo}">
      <title id="${id.titulo}">${esc(titulo)}</title>
      <desc id="${id.desc}">${esc(descripcion)}</desc>
      ${rejilla}${cuerpo}${ejeX}
    </svg>
    ${leyenda}
  </div>`;
}

function arco(cx, cy, r, a0, a1) {
  const p = (a) => [cx + r * Math.cos(a), cy + r * Math.sin(a)];
  const [x0, y0] = p(a0);
  const [x1, y1] = p(a1);
  const grande = a1 - a0 > Math.PI ? 1 : 0;
  return `M ${x0.toFixed(2)} ${y0.toFixed(2)} A ${r} ${r} 0 ${grande} 1 ${x1.toFixed(2)} ${y1.toFixed(2)}`;
}

/**
 * Dona de participación.
 * @param {{partes:Array<{etiqueta:string,valor:number}>, titulo:string, descripcion:string, centro:string}} o
 */
export function dona({ partes, titulo, descripcion, centro = '' }) {
  const datos = (partes || []).filter((p) => Number(p.valor) > 0);
  const total = datos.reduce((a, p) => a + Number(p.valor), 0);

  if (!total) {
    return `<p class="nota">Sin pedidos registrados.</p>`;
  }

  const id = ids();
  const cx = 92;
  const cy = 92;
  const r = 64;
  let a = -Math.PI / 2;
  let trazos = '';

  datos.forEach((p, i) => {
    const porcion = (Number(p.valor) / total) * Math.PI * 2;
    const color = COLORES[i % COLORES.length];
    trazos += porcion >= Math.PI * 2 - 0.0001
      ? `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${color}" stroke-width="22"></circle>`
      : `<path class="g-dona" d="${arco(cx, cy, r, a, a + porcion)}" fill="none" stroke="${color}" stroke-width="22">`
        + `<title>${esc(p.etiqueta)}: ${esc(numero(p.valor))}</title></path>`;
    a += porcion;
  });

  const leyenda = datos.map((p, i) => (
    `<span><i class="c${(i % COLORES.length) + 1}"></i>${esc(p.etiqueta)} · ${esc(numero(p.valor))}</span>`
  )).join('');

  return `<div class="grafico">
    <svg class="g-dona-svg" viewBox="0 0 184 184" role="img" aria-labelledby="${id.titulo}" width="184" height="184">
      <title id="${id.titulo}">${esc(titulo)}</title>
      <desc id="${id.desc}">${esc(descripcion)}</desc>
      ${trazos}
      <text class="g-centro-num" x="${cx}" y="${cy - 3}" text-anchor="middle">${esc(numero(total))}</text>
      <text class="g-centro-txt" x="${cx}" y="${cy + 15}" text-anchor="middle">${esc(centro || 'total')}</text>
    </svg>
    <div class="leyenda">${leyenda}</div>
  </div>`;
}
