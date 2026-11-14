import { BarChart3, Brain, CircleCheck, CircleDot, CircleX, Clock, Monitor, Play, Tag } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { claveEstado, etiquetaEstado } from '../projectUtils';
import type { EstadoClave } from '../projectUtils';

const ICONO_ESTADO: Record<EstadoClave, LucideIcon> = {
  aprobado: CircleCheck,
  pendiente: Clock,
  en_desarrollo: Play,
  rechazado: CircleX,
  otro: CircleDot,
};

export function StatusBadge({ estado }: { estado: string }) {
  const clave = claveEstado(estado);
  const Icono = ICONO_ESTADO[clave];
  return (
    <span className={`status-badge status-badge--${clave}`}>
      <Icono size={14} strokeWidth={2.2} aria-hidden="true" />
      <span className="visually-hidden">Estado: </span>
      {etiquetaEstado(estado)}
    </span>
  );
}

function CategoryIcon({ categoria }: { categoria: string }) {
  const c = categoria.toLowerCase();
  const props = { size: 13, strokeWidth: 2.2, 'aria-hidden': true } as const;
  if (c.includes('inteligencia') || c.includes(' ia') || c === 'ia') return <Brain {...props} />;
  if (c.includes('software') || c.includes('desarrollo')) return <Monitor {...props} />;
  if (c.includes('anál') || c.includes('anal') || c.includes('social') || c.includes('datos'))
    return <BarChart3 {...props} />;
  return <Tag {...props} />;
}

export function CategoryChip({ categoria, tone = 'light' }: { categoria: string; tone?: 'light' | 'dark' }) {
  return (
    <span className={`category-chip category-chip--${tone}`}>
      <CategoryIcon categoria={categoria} />
      <span className="visually-hidden">Categoría: </span>
      {categoria}
    </span>
  );
}
