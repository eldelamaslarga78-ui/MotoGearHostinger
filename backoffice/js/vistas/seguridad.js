/* Vista · Seguridad (Fase 6): autenticación en dos pasos. */

import { api } from '../api.js';
import { toast } from '../ui/toast.js';
import { esc } from '../formato.js';

let raiz = null;
let secreto = '';

function cabecera() {
  return `<div class="barra-vista"><div class="titulo-bloque"><h2>Seguridad</h2><p class="nota">Autenticación en dos pasos (2FA)</p></div></div>`;
}

function panelActivo() {
  return `<div class="tarjeta">
    <div class="tarjeta-cab"><h3>2FA activado</h3><span class="estado est-activo">Activo</span></div>
    <p class="nota">Tu cuenta pide un código de 6 dígitos además de la contraseña al iniciar sesión.</p>
    <button class="btn sec mini" type="button" data-accion="desactivar">Desactivar 2FA</button>
  </div>`;
}

function panelInactivo() {
  return `<div class="tarjeta">
    <div class="tarjeta-cab"><h3>Activar 2FA</h3></div>
    ${secreto
      ? `<div class="tarjeta-simple tarjeta-form">
          <p class="nota">Añade esta clave en tu app de autenticación (Google Authenticator, Authy…):</p>
          <div class="tarjeta-simple"><strong>${esc(secreto)}</strong></div>
          <div class="herramientas">
            <input class="campo" id="sf-codigo" placeholder="Código de 6 dígitos">
            <button class="btn mini" type="button" data-accion="activar">Verificar y activar</button>
          </div>
        </div>`
      : `<p class="nota">Activa la verificación en dos pasos para proteger tu cuenta.</p>
        <button class="btn mini" type="button" data-accion="generar">Generar clave</button>`}
  </div>`;
}

async function cargar() {
  const c = raiz.querySelector('#sf-cuerpo');
  try {
    const e = await api('2fa_estado');
    c.innerHTML = e.activo ? panelActivo() : panelInactivo();
  } catch (err) {
    c.innerHTML = `<div class="tarjeta">${esc(err.message)}</div>`;
  }
}

function conectar() {
  raiz.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-accion]'); if (!btn) return;
    const a = btn.dataset.accion;
    try {
      if (a === 'generar') {
        const r = await api('2fa_generar', { cuerpo: {} });
        secreto = r.secreto;
        cargar();
        toast('Clave generada');
      } else if (a === 'activar') {
        const codigo = raiz.querySelector('#sf-codigo').value.trim();
        await api('2fa_activar', { cuerpo: { codigo } });
        secreto = '';
        toast('2FA activado');
        cargar();
      } else if (a === 'desactivar') {
        await api('2fa_desactivar', { cuerpo: {} });
        toast('2FA desactivado');
        cargar();
      }
    } catch (err) { toast(err.message, 'error'); }
  });
}

export default {
  titulo: 'Seguridad', permiso: 'usuarios.gestionar',
  async montar(el) {
    raiz = el; secreto = '';
    raiz.innerHTML = cabecera() + '<div id="sf-cuerpo"></div>';
    conectar(); await cargar();
  },
  desmontar() { raiz = null; },
};
