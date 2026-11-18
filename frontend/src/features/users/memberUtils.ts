import type { Miembro } from '../../lib/api';

export function nombreMiembro(m: Miembro): string {
  return `${m.nombre} ${m.apellido}`.trim();
}

/** Iniciales: primer nombre y primer apellido ("Laura Camila Rincón Ávila" → "LR"). */
export function inicialesMiembro(m: Miembro): string {
  const nombre = m.nombre.trim().split(/\s+/)[0] ?? '';
  const apellido = m.apellido.trim().split(/\s+/)[0] ?? '';
  return ((nombre[0] ?? '') + (apellido[0] ?? '')).toUpperCase() || '?';
}

const ROLES: Record<string, string> = {
  superadministrador: 'Administrador',
  administrador: 'Administrador',
  docente: 'Docente',
  investigador: 'Investigador',
  estudiante: 'Estudiante',
};

export function etiquetaRol(rol: string): string {
  const clave = rol.trim().toLowerCase();
  if (ROLES[clave]) return ROLES[clave];
  const texto = clave.replace(/_/g, ' ');
  return texto.charAt(0).toUpperCase() + texto.slice(1);
}

/** "Ingeniería de Sistemas · 8.º semestre", o lo que haya de los dos. */
export function detalleAcademico(m: Miembro): string {
  const partes = [m.programa_academico, m.semestre ? `${m.semestre}.º semestre` : null].filter(Boolean);
  return partes.join(' · ');
}

function normalizar(texto: string): string {
  return texto
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase();
}

export function filtrarMiembros(miembros: Miembro[], q: string, rol: string): Miembro[] {
  const consulta = normalizar(q.trim());
  return miembros.filter((m) => {
    if (rol && etiquetaRol(m.rol) !== rol) return false;
    if (!consulta) return true;
    return normalizar(`${nombreMiembro(m)} ${m.programa_academico ?? ''} ${etiquetaRol(m.rol)}`).includes(consulta);
  });
}
