import type { Proyecto } from '../../lib/api';

export type EstadoClave = 'aprobado' | 'pendiente' | 'en_desarrollo' | 'rechazado' | 'otro';

type EstadoMeta = { label: string; plural: string };

export const ESTADOS: Record<Exclude<EstadoClave, 'otro'>, EstadoMeta> = {
  aprobado: { label: 'Aprobado', plural: 'Aprobados' },
  pendiente: { label: 'En espera', plural: 'En espera de aprobación' },
  en_desarrollo: { label: 'En desarrollo', plural: 'En desarrollo' },
  rechazado: { label: 'Rechazado', plural: 'Rechazados' },
};

const ALIAS: Record<string, EstadoClave> = {
  aprobado: 'aprobado',
  aprobada: 'aprobado',
  publicado: 'aprobado',
  pendiente: 'pendiente',
  en_espera: 'pendiente',
  pendiente_aprobacion: 'pendiente',
  en_revision: 'pendiente',
  en_desarrollo: 'en_desarrollo',
  desarrollo: 'en_desarrollo',
  en_progreso: 'en_desarrollo',
  rechazado: 'rechazado',
  rechazada: 'rechazado',
};

function slug(valor: string): string {
  return valor
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .trim()
    .toLowerCase()
    .replace(/[\s-]+/g, '_');
}

/** Lleva el estado que envía el backend a una clave visual conocida ("otro" si no se reconoce). */
export function claveEstado(estado: string): EstadoClave {
  return ALIAS[slug(estado)] ?? 'otro';
}

export function etiquetaEstado(estado: string): string {
  const clave = claveEstado(estado);
  return clave === 'otro' ? estado : ESTADOS[clave].label;
}

const fechaFormato = new Intl.DateTimeFormat('es-CO', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
  timeZone: 'UTC',
});

/** Fechas ISO como "2025-06-09" se leen en UTC para no correr un día por la zona horaria. */
function leerFecha(iso: string): Date | null {
  const fecha = new Date(iso);
  return Number.isNaN(fecha.getTime()) ? null : fecha;
}

/** "9 jun 2025": compacto para las tarjetas. */
export function formatearFecha(iso: string): string {
  const fecha = leerFecha(iso);
  if (!fecha) return '';
  const partes = fechaFormato.formatToParts(fecha);
  const parte = (tipo: Intl.DateTimeFormatPartTypes) => partes.find((p) => p.type === tipo)?.value ?? '';
  return `${parte('day')} ${parte('month').replace('.', '')} ${parte('year')}`;
}

export function anioDe(iso: string): string {
  const fecha = leerFecha(iso);
  return fecha ? String(fecha.getUTCFullYear()) : '';
}

export function iniciales(nombre: string): string {
  const partes = nombre.trim().split(/\s+/).filter(Boolean);
  return ((partes[0]?.[0] ?? '') + (partes[1]?.[0] ?? '')).toUpperCase() || '?';
}

export type Filtros = { estado: string; categoria: string; anio: string; q: string };

export function filtrarProyectos(proyectos: Proyecto[], filtros: Filtros): Proyecto[] {
  const q = slug(filtros.q).replace(/_/g, ' ');
  return proyectos.filter((p) => {
    if (filtros.estado && claveEstado(p.estado) !== filtros.estado) return false;
    if (filtros.categoria && p.categoria !== filtros.categoria) return false;
    if (filtros.anio && anioDe(p.fecha_publicacion) !== filtros.anio) return false;
    if (q) {
      const texto = slug(`${p.titulo} ${p.resumen} ${p.autor.nombre}`).replace(/_/g, ' ');
      if (!texto.includes(q)) return false;
    }
    return true;
  });
}

export function opcionesUnicas(valores: string[], orden: 'asc' | 'desc' = 'asc'): string[] {
  const unicos = [...new Set(valores.filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'));
  return orden === 'desc' ? unicos.reverse() : unicos;
}
