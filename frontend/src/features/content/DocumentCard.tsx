import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowUpRight, Calendar, CircleCheck, Clock, EyeOff, FileText, FolderKanban, Newspaper, Play } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { DocumentoResumen, EstadoDocumento, PersonaDocumento } from '../../lib/api';
import { ETIQUETA_CATEGORIA, ETIQUETA_ESTADO, fecha, iniciales, moduloDeCategoria, nombrePersona, rutaDocumento } from '../../lib/format';
import '../projects/projects.css';

const ESTILO_ESTADO: Record<EstadoDocumento, { clase: string; Icono: LucideIcon }> = {
  en_revision: { clase: 'pendiente', Icono: Clock },
  ejecucion: { clase: 'en_desarrollo', Icono: Play },
  finalizado: { clase: 'aprobado', Icono: CircleCheck },
  publicado: { clase: 'aprobado', Icono: CircleCheck },
};

export function EstadoBadge({ estado, visible = true }: { estado: EstadoDocumento; visible?: boolean }) {
  const { clase, Icono } = ESTILO_ESTADO[estado] ?? ESTILO_ESTADO.en_revision;
  return (
    <span className="app-badges">
      <span className={`status-badge status-badge--${clase}`}>
        <Icono size={14} strokeWidth={2.2} aria-hidden="true" />
        <span className="visually-hidden">Estado: </span>
        {ETIQUETA_ESTADO[estado] ?? estado}
      </span>
      {!visible && (
        <span className="status-badge status-badge--otro">
          <EyeOff size={14} aria-hidden="true" /> Oculto
        </span>
      )}
    </span>
  );
}

export function CategoriaChip({ categoria }: { categoria: DocumentoResumen['categoria'] }) {
  const Icono = categoria === 'proyecto' ? FolderKanban : categoria === 'noticia' ? Newspaper : FileText;
  return (
    <span className="category-chip category-chip--light">
      <Icono size={13} strokeWidth={2.2} aria-hidden="true" />
      <span className="visually-hidden">Categoría: </span>
      {ETIQUETA_CATEGORIA[categoria] ?? categoria}
    </span>
  );
}

export function PersonaAvatar({ persona, className = 'avatar' }: { persona: PersonaDocumento | null; className?: string }) {
  const [fallo, setFallo] = useState(false);
  const url = persona?.foto_perfil?.url;
  if (url && !fallo) return <img className={className} src={url} alt="" loading="lazy" onError={() => setFallo(true)} />;
  return (
    <span className={`${className} avatar--initials`} aria-hidden="true">
      {iniciales(persona)}
    </span>
  );
}

/** Tarjeta de un proyecto, artículo o noticia (resumen de los listados). */
export default function DocumentCard({ documento, mostrarEstado = true }: { documento: DocumentoResumen; mostrarEstado?: boolean }) {
  const to = rutaDocumento(moduloDeCategoria(documento.categoria), documento.id);
  const cuando = fecha(documento.fecha_creacion);
  return (
    <article className="project-card">
      <div className="project-card__body">
        <div className="project-card__top">
          <CategoriaChip categoria={documento.categoria} />
          <span className="project-card__open" aria-hidden="true">
            Abrir <ArrowUpRight size={14} />
          </span>
        </div>
        <h3 className="project-card__title">
          <Link to={to} className="project-card__link">
            {documento.titulo}
          </Link>
        </h3>
        <p className="project-card__summary">{documento.descripcion}</p>
        <div className="project-card__footer">
          <PersonaAvatar persona={documento.autor} />
          <div className="project-card__meta">
            <span className="project-card__author-name">{nombrePersona(documento.autor)}</span>
            {cuando && (
              <time className="project-card__date" dateTime={documento.fecha_creacion}>
                <Calendar size={13} aria-hidden="true" /> {cuando}
              </time>
            )}
          </div>
          {mostrarEstado && <EstadoBadge estado={documento.estado} visible={documento.visible} />}
        </div>
      </div>
    </article>
  );
}
