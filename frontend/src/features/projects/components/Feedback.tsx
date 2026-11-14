import type { ReactNode } from 'react';
import { CloudOff, FlaskConical, SearchX } from 'lucide-react';
import { ApiError } from '../../../lib/api';

type Props = { icon: ReactNode; title: string; children?: ReactNode; action?: ReactNode };

export function Feedback({ icon, title, children, action }: Props) {
  return (
    <div className="projects-feedback" role="status">
      <span className="projects-feedback__icon">{icon}</span>
      <h3>{title}</h3>
      {children && <p>{children}</p>}
      {action}
    </div>
  );
}

export function ErrorFeedback({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  const sinEndpoint = error instanceof ApiError && error.status === 404;
  return (
    <Feedback
      icon={<CloudOff size={30} aria-hidden="true" />}
      title={sinEndpoint ? 'El catálogo de proyectos aún no está disponible' : 'No pudimos cargar los proyectos'}
      action={
        <button type="button" className="btn btn--navy" onClick={onRetry}>
          Reintentar
        </button>
      }
    >
      {sinEndpoint
        ? 'El servicio de proyectos todavía no está publicado en la API. Vuelve a intentarlo más tarde.'
        : error instanceof ApiError
          ? error.message
          : 'Ocurrió un error inesperado. Intenta de nuevo.'}
    </Feedback>
  );
}

export function NoResults({ onClear }: { onClear: () => void }) {
  return (
    <Feedback
      icon={<SearchX size={30} aria-hidden="true" />}
      title="Ningún proyecto coincide con tu búsqueda"
      action={
        <button type="button" className="btn btn--navy" onClick={onClear}>
          Limpiar filtros
        </button>
      }
    >
      Prueba con otros filtros u otras palabras.
    </Feedback>
  );
}

export function DemoNotice() {
  return (
    <p className="demo-notice" role="note">
      <FlaskConical size={16} aria-hidden="true" />
      Modo demostración: datos de ejemplo, solo visibles en desarrollo (VITE_PROJECTS_DEMO).
    </p>
  );
}
