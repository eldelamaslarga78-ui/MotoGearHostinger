/* Vista · Mi cuenta (migrada del panel anterior): cambio de contraseña. */

import { api } from '../api.js';
import { toast } from '../ui/toast.js';

let raiz = null;

export default {
  titulo: 'Mi cuenta',
  permiso: null,
  async montar(el) {
    raiz = el;
    raiz.innerHTML = `<div class="barra-vista"><div class="titulo-bloque"><h2>Mi cuenta</h2><p class="nota">Contraseña y acceso</p></div></div>
      <form class="tarjeta-simple tarjeta-form" id="cta-form">
        <h3>Cambiar contraseña</h3>
        <div class="form-grid">
          <div class="campo-bloque"><label for="cta-actual">Contraseña actual</label>
            <input class="campo" id="cta-actual" type="password" autocomplete="current-password" required></div>
          <div class="campo-bloque"><label for="cta-nueva">Nueva contraseña (mínimo 10)</label>
            <input class="campo" id="cta-nueva" type="password" minlength="10" autocomplete="new-password" required></div>
        </div>
        <div class="ficha-pie"><button class="btn mini" type="submit">Actualizar contraseña</button></div>
      </form>
      <div class="tarjeta-simple tarjeta-form">
        <h3>Verificación en dos pasos</h3>
        <p class="nota">Pide un código de tu app de autenticación al iniciar sesión.</p>
        <a class="btn sec mini" href="#/seguridad">Configurar 2FA</a>
      </div>`;
    raiz.querySelector('#cta-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const actual = raiz.querySelector('#cta-actual').value;
      const nueva = raiz.querySelector('#cta-nueva').value;
      try {
        await api('clave', { cuerpo: { actual, nueva } });
        e.target.reset();
        toast('Contraseña actualizada');
      } catch (err) { toast(err.message, 'error'); }
    });
  },
  desmontar() { raiz = null; },
};
