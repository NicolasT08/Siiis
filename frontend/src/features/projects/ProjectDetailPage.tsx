import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Calendar, FileSearch, User } from 'lucide-react';
import ProjectsHero from './components/ProjectsHero';
import { CategoryChip, StatusBadge } from './components/Badges';
import { DemoNotice, ErrorFeedback, Feedback } from './components/Feedback';
import { useProjects } from './useProjects';
import { formatearFecha } from './projectUtils';
import './projects.css';

/**
 * Detalle de un proyecto. Mientras no exista `GET /proyectos/{id}` se toma del listado;
 * cambiar a la llamada propia cuando el backend la publique.
 */
export default function ProjectDetailPage() {
  const { id = '' } = useParams();
  const { state, reintentar } = useProjects();
  const proyecto = state.status === 'ready' ? (state.proyectos.find((p) => p.id === id) ?? null) : null;

  return (
    <div className="projects-page">
      <ProjectsHero title={proyecto?.titulo ?? 'Proyecto'} compact />

      <section className="container project-detail">
        <Link to="/proyectos" className="project-detail__back">
          <ArrowLeft size={16} aria-hidden="true" /> Volver a proyectos
        </Link>

        {state.status === 'ready' && state.demo && <DemoNotice />}
        {state.status === 'loading' && <div className="skeleton project-detail__skeleton" aria-label="Cargando proyecto" />}
        {state.status === 'error' && <ErrorFeedback error={state.error} onRetry={reintentar} />}

        {state.status === 'ready' && !proyecto && (
          <Feedback icon={<FileSearch size={30} aria-hidden="true" />} title="No encontramos este proyecto">
            Puede que se haya retirado o que el enlace no sea correcto.
          </Feedback>
        )}

        {proyecto && (
          <article className="project-detail__card">
            {proyecto.imagen && (
              <img className="project-detail__img" src={proyecto.imagen.url} alt="" decoding="async" />
            )}
            <div className="project-detail__body">
              <div className="project-detail__tags">
                <CategoryChip categoria={proyecto.categoria} />
                <StatusBadge estado={proyecto.estado} />
              </div>
              <h2 className="project-detail__title">{proyecto.titulo}</h2>
              <ul className="project-detail__meta">
                <li>
                  <User size={16} aria-hidden="true" /> {proyecto.autor.nombre}
                </li>
                {formatearFecha(proyecto.fecha_publicacion) && (
                  <li>
                    <Calendar size={16} aria-hidden="true" />
                    <time dateTime={proyecto.fecha_publicacion}>{formatearFecha(proyecto.fecha_publicacion)}</time>
                  </li>
                )}
              </ul>
              <p className="project-detail__summary">{proyecto.resumen}</p>
            </div>
          </article>
        )}
      </section>
    </div>
  );
}
