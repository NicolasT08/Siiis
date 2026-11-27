import type { CategoriaDocumento, EstadoDocumento, EstadoSolicitud, Modulo, Rol } from './api';

export const ETIQUETA_ROL: Record<Rol, string> = {
  superadministrador: 'Superadministrador',
  administrador: 'Administrador',
  docente_director: 'Docente director',
  estudiante_lider: 'Estudiante líder',
  estudiante: 'Estudiante',
};

export function etiquetaRol(rol: string): string {
  return ETIQUETA_ROL[rol as Rol] ?? rol.replace(/_/g, ' ');
}

export const ETIQUETA_ESTADO: Record<EstadoDocumento, string> = {
  en_revision: 'En revisión',
  ejecucion: 'En ejecución',
  finalizado: 'Finalizado',
  publicado: 'Publicado',
};

export const ETIQUETA_CATEGORIA: Record<CategoriaDocumento, string> = {
  proyecto: 'Proyecto',
  articulo: 'Artículo',
  noticia: 'Noticia',
};

export const ETIQUETA_SOLICITUD: Record<EstadoSolicitud, string> = {
  pendiente: 'Pendiente',
  aceptada: 'Aceptada',
  rechazada: 'Rechazada',
};

/** Ruta pública del detalle de un documento. */
export function rutaDocumento(modulo: Modulo, id: string): string {
  return `${modulo === 'projects' ? '/proyectos' : '/articulos'}/${encodeURIComponent(id)}`;
}

export function moduloDeCategoria(categoria: CategoriaDocumento): Modulo {
  return categoria === 'proyecto' ? 'projects' : 'articles';
}

export function nombrePersona(p: { nombre: string; apellido: string } | null | undefined): string {
  return p ? `${p.nombre} ${p.apellido}`.trim() : 'Autor no disponible';
}

export function iniciales(p: { nombre: string; apellido: string } | null | undefined): string {
  if (!p) return '?';
  const n = p.nombre.trim().split(/\s+/)[0] ?? '';
  const a = p.apellido.trim().split(/\s+/)[0] ?? '';
  return ((n[0] ?? '') + (a[0] ?? '')).toUpperCase() || '?';
}

const FECHA = new Intl.DateTimeFormat('es-CO', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'America/Bogota' });
const FECHA_HORA = new Intl.DateTimeFormat('es-CO', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  timeZone: 'America/Bogota',
});

function leer(iso: string | null | undefined): Date | null {
  if (!iso) return null;
  const fecha = new Date(iso);
  return Number.isNaN(fecha.getTime()) ? null : fecha;
}

/** "9 jun 2026" en hora de Colombia. */
export function fecha(iso: string | null | undefined): string {
  const f = leer(iso);
  return f ? FECHA.format(f).replace('.', '') : '';
}

export function fechaHora(iso: string | null | undefined): string {
  const f = leer(iso);
  return f ? FECHA_HORA.format(f) : '';
}

export function anio(iso: string | null | undefined): string {
  const f = leer(iso);
  return f ? String(f.getFullYear()) : '';
}

/** "1,5 MB" */
export function tamano(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toLocaleString('es-CO', { maximumFractionDigits: 1 })} MB`;
}
