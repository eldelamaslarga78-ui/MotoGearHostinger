/* Vista · Factura imprimible (Fase 3). */

import { api } from '../api.js';
import { esc, money, numero } from '../formato.js';
import { panelError } from '../ui/skeleton.js';

let raiz = null;

export default {
  titulo: 'Factura',
  permiso: 'facturas.ver',

  async montar(el) {
    raiz = el;
    const [, param] = location.hash.replace(/^#\/?/, '').split('/');
    const id = Number(param || 0);
    raiz.innerHTML = '<div class="tarjeta"><div class="esqueleto"><div class="esqueleto-linea"></div></div></div>';
    try {
      const d = await api('factura_ver', { query: { id } });
      const f = d.factura;
      const p = d.pedido;
      const filas = d.items.map((i) => `<tr>
        <td>${esc(i.nombre)}<div class="nota">${esc(i.sku)} · ${esc(i.talla || '—')}${i.color ? ' ' + esc(i.color) : ''}</div></td>
        <td class="num">${numero(i.cantidad)}</td>
        <td class="num">${money(i.precio)}</td>
        <td class="num">${money(i.total_linea || (i.precio * i.cantidad))}</td></tr>`).join('');

      raiz.innerHTML = `<div class="factura">
        <div class="factura-cab">
          <div><h1>MotoGear</h1><p class="nota">Factura de venta</p></div>
          <div class="num"><strong>${esc(f.numero_completo)}</strong><p class="nota">${esc(String(f.fecha).slice(0, 16))}</p></div>
        </div>
        <div class="tarjeta-gap"></div>
        <div class="linea-dato"><span>Cliente</span><span>${esc(p.cliente_nombre)}</span></div>
        <div class="linea-dato"><span>Documento</span><span>${esc(p.cliente_tel || '—')}</span></div>
        <div class="linea-dato"><span>Dirección</span><span>${esc(p.envio_ciudad || '')}${p.envio_direccion ? ', ' + esc(p.envio_direccion) : ''}</span></div>
        <table>
          <thead><tr><th>Producto</th><th class="num">Cant.</th><th class="num">Precio</th><th class="num">Línea</th></tr></thead>
          <tbody>${filas}</tbody>
        </table>
        <div class="totales">
          <div class="linea-dato"><span>Subtotal</span><span>${money(f.subtotal)}</span></div>
          <div class="linea-dato"><span>Descuento</span><span>${money(f.descuento)}</span></div>
          <div class="linea-dato"><span>Impuestos</span><span>${money(f.impuestos)}</span></div>
          <div class="linea-dato"><span><strong>Total</strong></span><span><strong>${money(f.total)}</strong></span></div>
        </div>
        <p class="nota factura-nota">Documento interno de la tienda. No válido como factura electrónica DIAN.</p>
        <button class="btn mini" type="button" id="btn-imprimir">Imprimir / guardar PDF</button>
      </div>`;
      raiz.querySelector('#btn-imprimir').addEventListener('click', () => window.print());
    } catch (e) {
      raiz.innerHTML = `<div class="tarjeta">${panelError(esc(e.message))}</div>`;
    }
  },

  desmontar() {
    raiz = null;
  },
};
