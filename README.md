# MotoGear – Tienda con base de datos (PHP + MySQL)

## Instalación en Hostinger (10 minutos)
1. **Crear la base de datos**: hPanel → Bases de datos → Administración MySQL → crea una base, un usuario y una contraseña.
2. **Configurar**: abre `api/config.php` y pega esos 3 datos (nombre, usuario, contraseña).
3. **Subir** todo el contenido de esta carpeta a `public_html` (respeta la carpeta `api/` y `img/`). Sube también tus imágenes a `img/`.
4. **Instalar**: entra a `https://tudominio.com/api/install.php`, crea tu usuario y contraseña de administrador. Crea las tablas, carga el catálogo inicial y se bloquea solo.
5. **Borra `api/install.php`** del servidor.
6. Entra a `https://tudominio.com/admin.html` y empieza a gestionar.

## Qué puedes hacer
- **Pedidos**: llegan a la base de datos con cliente, dirección, productos, talla y total; cambia el estado (nuevo → entregado). Al cancelar, el stock se devuelve.
- **Productos**: crear, editar, ocultar, stock, tallas, destacados y subida de imagen (se convierte a .webp).
- **Categorías, cupones** (vencimiento, compra mínima, usos máximos) y **mensajes** del formulario de contacto.

## Seguridad incluida
Precios y descuentos se calculan en el servidor (el navegador no puede alterarlos) · contraseñas con `password_hash` · sesión con cookie HttpOnly + token CSRF · bloqueo tras 8 intentos fallidos · consultas preparadas (sin inyección SQL) · límite de pedidos y mensajes por IP · campo trampa anti-bots · stock con bloqueo de transacción.

## Recomendado
- Activa SSL (HTTPS) gratis en hPanel → Seguridad → SSL.
- Protege `admin.html` con contraseña de directorio (hPanel → Avanzado) como segunda barrera.
- Haz copias de la base de datos desde hPanel → Copias de seguridad.