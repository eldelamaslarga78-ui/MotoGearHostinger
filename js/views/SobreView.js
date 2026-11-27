/* Vista "Sobre Nosotros": historia, cifras, misión/visión, servicios y equipo. */
export default {
  render(el) {
    document.title = 'Sobre Nosotros · MotoGear Bogotá';
    el.innerHTML = `
      <section class="hero-sobre"><div class="hero-sobre-texto"><h1>Sobre Nosotros</h1><p>Un nuevo proyecto nacido para la comunidad motera de Colombia</p></div></section>

      <section class="seccion-historia">
        <div class="historia-texto">
          <h2 class="sobre-titulo">Nuestra Historia</h2>
          <p>MotoGear Bogotá nació de la pasión por las dos ruedas y de una necesidad real: la comunidad motera colombiana merece productos de calidad y servicios a la altura de su estilo de vida.</p>
          <p>Somos un proyecto nuevo, joven y con visión. Hoy ofrecemos equipamiento premium para todo tipo de rider, y próximamente llevaremos hasta tu puerta servicios innovadores que cambiarán la forma en que cuidas tu moto.</p>
          <p><strong>Esto apenas comienza.</strong> Somos parte de la comunidad, rodamos contigo.</p>
          <a href="/productos" class="btn-historia">Ver productos</a>
        </div>
        <div class="historia-imagen"><img loading="lazy" decoding="async" src="https://images.pexels.com/photos/14085022/pexels-photo-14085022.jpeg" alt="Comunidad motera"></div>
      </section>

      <section class="seccion-cifras">
        <div class="cifra"><span class="cifra-numero">+200</span><span class="cifra-label">Productos disponibles</span></div>
        <div class="cifra"><span class="cifra-numero">4</span><span class="cifra-label">Marcas premium</span></div>
        <div class="cifra"><span class="cifra-numero">3</span><span class="cifra-label">Servicios próximamente</span></div>
        <div class="cifra"><span class="cifra-numero">100%</span><span class="cifra-label">A domicilio</span></div>
      </section>

      <section class="seccion-mv">
        <div class="mv-card"><div class="mv-icono">🎯</div><h3>Misión</h3><p>Ser el aliado número uno del motociclista colombiano, ofreciendo productos certificados de calidad premium y servicios innovadores que faciliten y enriquezcan su experiencia sobre dos ruedas.</p></div>
        <div class="mv-card"><div class="mv-icono">🔭</div><h3>Visión</h3><p>Consolidarnos como la plataforma integral para motociclistas en Colombia, expandiendo nuestros servicios a domicilio a las principales ciudades del país antes de 2028.</p></div>
        <div class="mv-card"><div class="mv-icono">⚙️</div><h3>Valores</h3><p>Comunidad, innovación y calidad. Creemos en un servicio honesto, cercano y apasionado, donde cada cliente es un rider al que queremos ver rodar seguro y con estilo.</p></div>
      </section>

      <section class="seccion-servicios">
        <h2 class="sobre-titulo-centrado">Próximamente</h2>
        <p class="servicios-subtitulo">Servicios innovadores a domicilio para tu moto</p>
        <div class="servicios-grid">
          <div class="servicio-card"><div class="servicio-icono">🚿</div><h4>Lavado a Domicilio</h4><p>Lavado profesional de tu moto sin que tengas que moverla. Agenda y nosotros llegamos donde estés.</p></div>
          <div class="servicio-card"><div class="servicio-icono">✨</div><h4>Detallado Premium</h4><p>Limpieza profunda, pulido y protección de superficies. Deja tu moto como recién salida del concesionario.</p></div>
          <div class="servicio-card"><div class="servicio-icono">🎨</div><h4>Forrado Wrap</h4><p>Personaliza tu moto con viniles de la más alta calidad. Cambia de look sin afectar la pintura original.</p></div>
        </div>
      </section>

      <section class="seccion-equipo">
        <h2 class="sobre-titulo-centrado">Nuestro Equipo</h2>
        <div class="equipo-grid">
          <div class="equipo-card"><div class="equipo-foto"><img loading="lazy" decoding="async" src="https://images.pexels.com/photos/4560442/pexels-photo-4560442.jpeg" alt="Fundador"></div><h4 class="equipo-nombre">Tu Nombre</h4><span class="equipo-cargo">Fundador & CEO</span><p class="equipo-desc">Motociclista apasionado y emprendedor. Creó MotoGear para darle a la comunidad motera colombiana lo que siempre mereció.</p></div>
          <div class="equipo-card"><div class="equipo-foto"><img loading="lazy" decoding="async" src="https://images.pexels.com/photos/19062243/pexels-photo-19062243.jpeg" alt="Jefa de ventas"></div><h4 class="equipo-nombre">Tu Nombre</h4><span class="equipo-cargo">Jefa de Ventas</span><p class="equipo-desc">Especialista en atención al cliente y conocedora de todas las marcas que manejamos. Siempre lista para asesorarte.</p></div>
          <div class="equipo-card"><div class="equipo-foto"><img loading="lazy" decoding="async" src="https://images.pexels.com/photos/26558694/pexels-photo-26558694.jpeg" alt="Técnico"></div><h4 class="equipo-nombre">Tu Nombre</h4><span class="equipo-cargo">Asesor Técnico</span><p class="equipo-desc">Piloto de enduro y experto en certificaciones DOT/ECE. Lidera el desarrollo de los nuevos servicios a domicilio.</p></div>
        </div>
      </section>

      <section class="seccion-cta">
        <h2>¿Listo para equiparte?</h2>
        <p>Visítanos en nuestra tienda o explora nuestro catálogo online</p>
        <div class="cta-botones"><a href="/productos" class="btn-cta-primario">Ver productos</a><a href="/contacto" class="btn-cta-secundario">Contáctanos</a></div>
      </section>`;
  },
};
