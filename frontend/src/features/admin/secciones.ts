import { esAdministrativo } from '../../lib/auth';

/** Pestañas del panel según el rol: revisores (contenido, reseñas) y administrativos (además usuarios, inscripciones y mensajes). */
export function seccionesAdmin(rol: Parameters<typeof esAdministrativo>[0]) {
  const admin = esAdministrativo(rol);
  return [
    { to: '/admin/contenido', label: 'Contenido en revisión', texto: 'Publica, oculta o borra proyectos, artículos y noticias.', visible: true },
    { to: '/admin/resenas', label: 'Reseñas', texto: 'Aprueba u oculta reseñas y borra las ofensivas.', visible: true },
    { to: '/admin/inicio', label: 'Inicio', texto: 'Fotos del carrusel y videos de presentación de la página de inicio.', visible: admin },
    { to: '/admin/usuarios', label: 'Usuarios', texto: 'Crea cuentas, cambia roles, desactiva y reactiva integrantes.', visible: admin },
    { to: '/admin/inscripciones', label: 'Inscripciones', texto: 'Revisa solicitudes y sus documentos; acepta o rechaza.', visible: admin },
    { to: '/admin/mensajes', label: 'Mensajes de contacto', texto: 'Bandeja de mensajes con leídos y no leídos.', visible: admin },
  ].filter((s) => s.visible);
}
