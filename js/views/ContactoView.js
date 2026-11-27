/* Vista de contacto: formulario + información. */
import { api } from '../services/ApiClient.js';
import { toast } from '../components/Toast.js';

const INSTAGRAM = '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M8 0C5.829 0 5.556.01 4.703.048 3.85.088 3.269.222 2.76.42a3.9 3.9 0 0 0-1.417.923A3.9 3.9 0 0 0 .42 2.76C.222 3.268.087 3.85.048 4.7.01 5.555 0 5.827 0 8.001c0 2.172.01 2.444.048 3.297.04.852.174 1.433.372 1.942.205.526.478.972.923 1.417.444.445.89.719 1.416.923.51.198 1.09.333 1.942.372C5.555 15.99 5.827 16 8 16s2.444-.01 3.298-.048c.851-.04 1.434-.174 1.943-.372a3.9 3.9 0 0 0 1.416-.923c.445-.445.718-.891.923-1.417.197-.509.332-1.09.372-1.942C15.99 10.445 16 10.173 16 8s-.01-2.445-.048-3.299c-.04-.851-.175-1.433-.372-1.941a3.9 3.9 0 0 0-.923-1.417A3.9 3.9 0 0 0 13.24.42c-.51-.198-1.092-.333-1.943-.372C10.443.01 10.172 0 7.998 0zm-.717 1.442h.718c2.136 0 2.389.007 3.232.046.78.035 1.204.166 1.486.275.373.145.64.319.92.599s.453.546.598.92c.11.281.24.705.275 1.485.039.843.047 1.096.047 3.231s-.008 2.389-.047 3.232c-.035.78-.166 1.203-.275 1.485a2.5 2.5 0 0 1-.599.919c-.28.28-.546.453-.92.598-.28.11-.704.24-1.485.276-.843.038-1.096.047-3.232.047s-2.39-.009-3.233-.047c-.78-.036-1.203-.166-1.485-.276a2.5 2.5 0 0 1-.92-.598 2.5 2.5 0 0 1-.6-.92c-.109-.281-.24-.705-.275-1.485-.038-.843-.046-1.096-.046-3.233s.008-2.388.046-3.231c.036-.78.166-1.204.276-1.486.145-.373.319-.64.599-.92s.546-.453.92-.598c.282-.11.705-.24 1.485-.276.738-.034 1.024-.044 2.515-.045zm4.988 1.328a.96.96 0 1 0 0 1.92.96.96 0 0 0 0-1.92m-4.27 1.122a4.109 4.109 0 1 0 0 8.217 4.109 4.109 0 0 0 0-8.217m0 1.441a2.667 2.667 0 1 1 0 5.334 2.667 2.667 0 0 1 0-5.334"/></svg>';
const TIKTOK = '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M9 0h1.98c.144.715.54 1.617 1.235 2.512C12.895 3.389 13.797 4 15 4v2c-1.753 0-3.07-.814-4-1.829V11a5 5 0 1 1-5-5v2a3 3 0 1 0 3 3z"/></svg>';
const FACEBOOK = '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951"/></svg>';

export default {
  render(el) {
    document.title = 'Contacto · MotoGear Bogotá';
    el.innerHTML = `
      <section class="hero-contacto"><div class="hero-contacto-texto"><h1>Contáctanos</h1><p>Estamos para ayudarte en todo lo que necesites</p></div></section>
      <section class="pagina-contacto">
        <div class="contacto-form-wrap">
          <h2 class="contacto-subtitulo">Envíanos un mensaje</h2>
          <form class="contacto-form">
            <input type="text" id="web" name="web" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <div class="form-fila">
              <div class="form-grupo"><label for="nombre">Nombre</label><input type="text" id="nombre" placeholder="Tu nombre" required></div>
              <div class="form-grupo"><label for="apellido">Apellido</label><input type="text" id="apellido" placeholder="Tu apellido" required></div>
            </div>
            <div class="form-fila">
              <div class="form-grupo"><label for="email">Correo electrónico</label><input type="email" id="email" placeholder="tucorreo@email.com" required></div>
              <div class="form-grupo"><label for="telefono">Teléfono</label><input type="tel" id="telefono" placeholder="+57 316 780 0764"></div>
            </div>
            <div class="form-grupo"><label for="asunto">Asunto</label>
              <select id="asunto" required><option value="">Selecciona un asunto</option><option value="pedido">Consulta sobre pedido</option><option value="producto">Información de producto</option><option value="devolucion">Devolución / Garantía</option><option value="otro">Otro</option></select>
            </div>
            <div class="form-grupo"><label for="mensaje">Mensaje</label><textarea id="mensaje" rows="5" placeholder="Escribe tu mensaje aquí..." required></textarea></div>
            <button type="submit" class="btn-enviar">Enviar mensaje <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M15.854.146a.5.5 0 0 1 .11.54l-5.819 14.547a.75.75 0 0 1-1.329.124l-3.178-4.995L.643 7.184a.75.75 0 0 1 .124-1.33L15.314.037a.5.5 0 0 1 .54.11z"/></svg></button>
          </form>
        </div>
        <aside class="contacto-info">
          <div class="info-bloque">
            <h3 class="contacto-subtitulo">Información</h3>
            <div class="info-item"><div class="info-icono">📍</div><div><strong>Dirección</strong><p>Calle 80 #45-23, Bogotá, Colombia</p></div></div>
            <div class="info-item"><div class="info-icono">📞</div><div><strong>Teléfono</strong><p>+57 316 780 0764</p></div></div>
            <div class="info-item"><div class="info-icono">✉️</div><div><strong>Correo</strong><p>info@motogear.co</p></div></div>
            <div class="info-item"><div class="info-icono">🕐</div><div><strong>Horario</strong><p>Lun - Sáb: 9:00am - 7:00pm</p><p>Dom: 10:00am - 4:00pm</p></div></div>
          </div>
          <div class="info-bloque">
            <h3 class="contacto-subtitulo">Síguenos</h3>
            <div class="redes-contacto"><a href="#" class="red-item">${INSTAGRAM}Instagram</a><a href="#" class="red-item">${TIKTOK}TikTok</a><a href="#" class="red-item">${FACEBOOK}Facebook</a></div>
          </div>
          <div class="info-bloque">
            <h3 class="contacto-subtitulo">Ubicación</h3>
            <div class="mapa-wrap"><iframe title="Mapa de ubicación de MotoGear" src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3976.6!2d-74.0721!3d4.7109!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zBMKwNDInMzkuMiJOIDc0wrAwNCczNS42Ilc!5e0!3m2!1ses!2sco!4v1" width="100%" height="200" allowfullscreen="" loading="lazy"></iframe></div>
          </div>
        </aside>
      </section>`;

    const form = el.querySelector('.contacto-form');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const v = (id) => el.querySelector('#' + id).value.trim();
      const btn = el.querySelector('.btn-enviar', form); btn.disabled = true;
      try {
        await api('mensaje', { nombre: v('nombre') + ' ' + v('apellido'), email: v('email'), telefono: v('telefono'), asunto: el.querySelector('#asunto').selectedOptions[0].text, mensaje: v('mensaje'), web: v('web') });
        form.reset();
        toast('¡Mensaje enviado! Te responderemos pronto');
      } catch (err) { toast(err.message, 'error'); }
      finally { btn.disabled = false; }
    });
  },
};
