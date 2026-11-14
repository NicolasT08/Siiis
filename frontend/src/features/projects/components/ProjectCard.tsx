import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowUpRight, Calendar, FolderKanban, Star } from 'lucide-react';
import type { Proyecto } from '../../../lib/api';
import { formatearFecha, iniciales } from '../projectUtils';
import { CategoryChip, StatusBadge } from './Badges';

type Props = { proyecto: Proyecto; featured?: boolean };

function Avatar({ nombre, foto }: { nombre: string; foto: string | null }) {
  const [fallo, setFallo] = useState(false);
  if (foto && !fallo) {
    return <img className="avatar" src={foto} alt="" loading="lazy" onError={() => setFallo(true)} />;
  }
  return (
    <span className="avatar avatar--initials" aria-hidden="true">
      {iniciales(nombre)}
    </span>
  );
}

function Cover({ url, className }: { url: string | null; className: string }) {
  const [fallo, setFallo] = useState(false);
  if (url && !fallo) {
    return (
      <img className={className} src={url} alt="" loading="lazy" decoding="async" onError={() => setFallo(true)} />
    );
  }
  return (
    <div className={`${className} cover-fallback`} aria-hidden="true">
      <FolderKanban size={34} strokeWidth={1.5} />
    </div>
  );
}

export default function ProjectCard({ proyecto, featured = false }: Props) {
  const { id, titulo, resumen, categoria, estado, autor, fecha_publicacion, imagen } = proyecto;
  const fecha = formatearFecha(fecha_publicacion);
  const to = `/proyectos/${encodeURIComponent(id)}`;

  if (featured) {
    return (
      <article className="project-card project-card--featured">
        <Cover url={imagen?.url ?? null} className="project-card__bg" />
        <div className="project-card__shade" aria-hidden="true" />
        <span className="featured-tag">
          <Star size={13} fill="currentColor" aria-hidden="true" /> Proyecto destacado
        </span>
        <div className="project-card__featured-body">
          <CategoryChip categoria={categoria} tone="dark" />
          <h3 className="project-card__title">
            <Link to={to} className="project-card__link">
              {titulo}
            </Link>
          </h3>
          <p className="project-card__summary">{resumen}</p>
          <div className="project-card__footer">
            <span className="project-card__author">
              <Avatar nombre={autor.nombre} foto={autor.foto_url} />
              {autor.nombre}
            </span>
            {fecha && (
              <time className="project-card__date" dateTime={fecha_publicacion}>
                <Calendar size={14} aria-hidden="true" /> {fecha}
              </time>
            )}
            <StatusBadge estado={estado} />
          </div>
        </div>
        <span className="project-card__open project-card__open--featured" aria-hidden="true">
          Abrir proyecto <ArrowUpRight size={15} />
        </span>
      </article>
    );
  }

  return (
    <article className="project-card">
      <div className="project-card__media">
        <Cover url={imagen?.url ?? null} className="project-card__img" />
      </div>
      <div className="project-card__body">
        <div className="project-card__top">
          <CategoryChip categoria={categoria} />
          <span className="project-card__open" aria-hidden="true">
            Abrir <ArrowUpRight size={14} />
          </span>
        </div>
        <h3 className="project-card__title">
          <Link to={to} className="project-card__link">
            {titulo}
          </Link>
        </h3>
        <p className="project-card__summary">{resumen}</p>
        <div className="project-card__footer">
          <Avatar nombre={autor.nombre} foto={autor.foto_url} />
          <div className="project-card__meta">
            <span className="project-card__author-name">{autor.nombre}</span>
            {fecha && (
              <time className="project-card__date" dateTime={fecha_publicacion}>
                <Calendar size={13} aria-hidden="true" /> {fecha}
              </time>
            )}
          </div>
          <StatusBadge estado={estado} />
        </div>
      </div>
    </article>
  );
}

export function ProjectCardSkeleton({ featured = false }: { featured?: boolean }) {
  return (
    <div className={`project-card project-card--skeleton${featured ? ' project-card--featured' : ''}`} aria-hidden="true">
      <div className="skeleton skeleton--media" />
      <div className="project-card__body">
        <div className="skeleton skeleton--chip" />
        <div className="skeleton skeleton--line" />
        <div className="skeleton skeleton--line skeleton--short" />
      </div>
    </div>
  );
}
