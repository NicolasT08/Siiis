import { CircleCheck, Clock, Layers, Rocket } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { Proyecto } from '../../../lib/api';
import { claveEstado, ESTADOS } from '../projectUtils';

type Stat = { icon: LucideIcon; valor: number; label: string };

/** Cifras calculadas sobre los proyectos que devuelve la API (nunca valores fijos). */
export default function ProjectStats({ proyectos }: { proyectos: Proyecto[] | null }) {
  const contar = (clave: string) => proyectos?.filter((p) => claveEstado(p.estado) === clave).length ?? 0;
  const enDesarrollo = contar('en_desarrollo');

  const stats: Stat[] = [
    { icon: Layers, valor: proyectos?.length ?? 0, label: 'Proyectos registrados' },
    { icon: CircleCheck, valor: contar('aprobado'), label: ESTADOS.aprobado.plural },
    { icon: Clock, valor: contar('pendiente'), label: ESTADOS.pendiente.plural },
  ];
  // "En desarrollo" solo se muestra si ese estado aparece en los datos reales.
  if (enDesarrollo > 0) stats.push({ icon: Rocket, valor: enDesarrollo, label: ESTADOS.en_desarrollo.plural });

  return (
    <div className="container">
      <ul className="project-stats" aria-label="Resumen de proyectos" aria-busy={proyectos === null}>
        {stats.map(({ icon: Icono, valor, label }) => (
          <li key={label} className="project-stats__item">
            <Icono className="project-stats__icon" size={30} strokeWidth={1.6} aria-hidden="true" />
            <div>
              <strong className="project-stats__value">{proyectos === null ? '—' : valor}</strong>
              <span className="project-stats__label">{label}</span>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
