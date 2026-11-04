/* Vista · Usuarios y roles (pendiente de la Fase 0; migración del panel anterior no lo tenía). */

import { api } from '../api.js';
import { toast } from '../ui/toast.js';
import { esc, fechaHora } from '../formato.js';
import { panelError, vacio } from '../ui/skeleton.js';

let raiz = null;
let datos = null;      // { items, roles }
let editando = null;   // null = lista · 'nuevo' · id numérico

function cabecera() {
  return `<div class="barra-vista"><div class="titulo-bloque"><h2>Usuarios</h2><p class="nota">Acceso del equipo y roles</p></div>
    <button class="btn mini" type="button" data-accion="nuevo">Nuevo usuario</button></div>`;
}

function opcionesRoles(seleccionado) {
  return datos.roles.map((r) => `<option value="${r.id}"${Number(seleccionado) === r.id ? ' selected' : ''}>${esc(r.nombre)} · ${esc(r.descripcion)}</option>`).join('');
}

function formulario() {
  const u = editando === 'nuevo' ? null : datos.items.find((x) => x.id === editando);
  return `<form class="tarjeta-simple tarjeta-form" id="us-form">
    <h3>${u ? 'Editar usuario' : 'Nuevo usuario'}</h3>
    <div class="form-grid">
      <div class="campo-bloque"><label for="us-nombre">Nombre</label>
        <input class="campo" id="us-nombre" required maxlength="80" value="${esc(u ? u.nombre : '')}"></div>
      <div class="campo-bloque"><label for="us-usuario">Usuario (para iniciar sesión)</label>
        <input class="campo" id="us-usuario" required maxlength="40" pattern="[A-Za-z0-9._-]{3,40}" value="${esc(u ? u.usuario : '')}"></div>
      <div class="campo-bloque"><label for="us-email">Correo</label>
        <input class="campo" id="us-email" type="email" required maxlength="120" value="${esc(u ? u.email : '')}"></div>
      <div class="campo-bloque"><label for="us-rol">Rol</label>
        <select class="campo" id="us-rol" required>${opcionesRoles(u ? u.rol_id : 0)}</select></div>
      <div class="campo-bloque"><label for="us-clave">${u ? 'Nueva contraseña (vacía = no cambiar)' : 'Contraseña inicial (mínimo 10)'}</label>
        <input class="campo" id="us-clave" type="password" autocomplete="new-password" minlength="10" ${u ? '' : 'required'}></div>
      <div class="campo-bloque"><label><input type="checkbox" id="us-activo" ${!u || u.activo ? 'checked' : ''}> Activo (puede iniciar sesión)</label></div>
    </div>
    <div class="ficha-pie">
      <button class="btn sec mini" type="button" data-accion="cancelar">Cancelar</button>
      <button class="btn mini" type="submit">Guardar</button>
    </div>
  </form>`;
}

function tabla() {
  if (!datos.items.length) return vacio('Sin usuarios');
  const filas = datos.items.map((u) => `<tr>
    <td data-etiqueta="Nombre">${esc(u.nombre)}<div class="sku">${esc(u.usuario)} · ${esc(u.email)}</div></td>
    <td data-etiqueta="Rol">${esc(u.rol)}</td>
    <td data-etiqueta="Estado">${u.activo ? '<span class="estado est-activo">Activo</span>' : '<span class="estado est-inactivo">Inactivo</span>'}</td>
    <td data-etiqueta="2FA">${u.totp_activo ? 'Sí' : 'No'}</td>
    <td data-etiqueta="Último acceso">${u.ultimo_acceso ? esc(fechaHora(u.ultimo_acceso)) : '—'}</td>
    <td data-etiqueta="Acciones"><button class="btn sec mini" type="button" data-accion="editar" data-id="${u.id}">Editar</button></td>
  </tr>`).join('');
  return `<div class="tabla-wrap"><table class="tabla">
    <thead><tr><th>Usuario</th><th>Rol</th><th>Estado</th><th>2FA</th><th>Último acceso</th><th></th></tr></thead>
    <tbody>${filas}</tbody></table></div>`;
}

function render() {
  const c = raiz.querySelector('#us-cuerpo');
  c.innerHTML = (editando ? formulario() : '') + tabla();
}

async function cargar() {
  try {
    datos = await api('usuarios');
    render();
  } catch (e) {
    raiz.querySelector('#us-cuerpo').innerHTML = panelError(esc(e.message));
  }
}

async function guardar(e) {
  e.preventDefault();
  const data = {
    id: editando === 'nuevo' ? 0 : editando,
    nombre: raiz.querySelector('#us-nombre').value.trim(),
    usuario: raiz.querySelector('#us-usuario').value.trim(),
    email: raiz.querySelector('#us-email').value.trim(),
    rol_id: Number(raiz.querySelector('#us-rol').value),
    activo: raiz.querySelector('#us-activo').checked,
    clave: raiz.querySelector('#us-clave').value,
  };
  try {
    await api('usuario_guardar', { cuerpo: { data } });
    toast('Usuario guardado');
    editando = null;
    await cargar();
  } catch (err) { toast(err.message, 'error'); }
}

function conectar() {
  raiz.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-accion]');
    if (!btn) return;
    const a = btn.dataset.accion;
    if (a === 'nuevo') { editando = 'nuevo'; render(); return; }
    if (a === 'editar') { editando = Number(btn.dataset.id); render(); return; }
    if (a === 'cancelar') { editando = null; render(); return; }
  });
  raiz.addEventListener('submit', (e) => { if (e.target.id === 'us-form') guardar(e); });
}

export default {
  titulo: 'Usuarios',
  permiso: 'usuarios.gestionar',
  async montar(el) {
    raiz = el; editando = null;
    raiz.innerHTML = cabecera() + '<div id="us-cuerpo"></div>';
    conectar();
    await cargar();
  },
  desmontar() { raiz = null; datos = null; },
};
