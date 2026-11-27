import type { UsuarioPublico } from '../../lib/api';
import { etiquetaRol as etiqueta } from '../../lib/format';

export type Miembro = UsuarioPublico;

export function nombreMiembro(m: Miembro): string {
  return `${m.nombre} ${m.apellido}`.trim();
}

/** Iniciales: primer nombre y primer apellido ("Laura Camila Rincón Ávila" → "LR"). */
export function inicialesMiembro(m: Miembro): string {
  const nombre = m.nombre.trim().split(/\s+/)[0] ?? '';
  const apellido = m.apellido.trim().split(/\s+/)[0] ?? '';
  return ((nombre[0] ?? '') + (apellido[0] ?? '')).toUpperCase() || '?';
}

export const etiquetaRol = etiqueta;

/** "Ingeniería de Sistemas · 8.º semestre", o lo que haya de los dos. */
export function detalleAcademico(m: Miembro): string {
  const partes = [m.programa_academico, m.semestre ? `${m.semestre}.º semestre` : null].filter(Boolean);
  return partes.join(' · ');
}
