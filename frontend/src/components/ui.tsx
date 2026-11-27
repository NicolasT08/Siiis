import type { ReactNode } from 'react';
import { AlertCircle, CheckCircle2, ChevronLeft, ChevronRight, CloudOff, Loader2 } from 'lucide-react';
import type { Meta } from '../lib/api';
import { mensajeError } from '../lib/errors';

/** Indicador de carga de una sección. */
export function Cargando({ texto = 'Cargando…' }: { texto?: string }) {
  return (
    <div className="app-loading" role="status" aria-live="polite">
      <Loader2 className="app-spin" size={22} aria-hidden="true" />
      <span>{texto}</span>
    </div>
  );
}

/** Aviso de éxito o error dentro de un formulario o una pantalla. */
export function Aviso({ tipo, children }: { tipo: 'ok' | 'error' | 'info'; children: ReactNode }) {
  const Icono = tipo === 'ok' ? CheckCircle2 : AlertCircle;
  return (
    <p className={`app-alert app-alert--${tipo}`} role={tipo === 'error' ? 'alert' : 'status'}>
      <Icono size={18} aria-hidden="true" />
      <span>{children}</span>
    </p>
  );
}

/** Error al cargar datos, con botón para reintentar. */
export function ErrorCarga({ error, onRetry, titulo = 'No pudimos cargar esta información' }: { error: unknown; onRetry?: () => void; titulo?: string }) {
  return (
    <div className="app-empty" role="alert">
      <CloudOff size={30} aria-hidden="true" />
      <h3>{titulo}</h3>
      <p>{mensajeError(error)}</p>
      {onRetry && (
        <button type="button" className="btn btn--navy" onClick={onRetry}>
          Reintentar
        </button>
      )}
    </div>
  );
}

export function Vacio({ icono, titulo, children }: { icono?: ReactNode; titulo: string; children?: ReactNode }) {
  return (
    <div className="app-empty" role="status">
      {icono}
      <h3>{titulo}</h3>
      {children && <p>{children}</p>}
    </div>
  );
}

/** Mensaje de un campo con error (400 VALIDATION_ERROR → fields). */
export function ErrorCampo({ id, mensaje }: { id: string; mensaje?: string }) {
  if (!mensaje) return null;
  return (
    <span id={id} className="app-field__error">
      {mensaje}
    </span>
  );
}

/** Paginación con el `meta` de la API. */
export function Paginacion({ meta, onPage }: { meta: Meta | null; onPage: (page: number) => void }) {
  if (!meta || meta.total_pages <= 1) return null;
  return (
    <nav className="app-pagination" aria-label="Paginación">
      <button type="button" className="btn btn--navy" disabled={meta.page <= 1} onClick={() => onPage(meta.page - 1)}>
        <ChevronLeft size={16} aria-hidden="true" /> Anterior
      </button>
      <span aria-live="polite">
        Página {meta.page} de {meta.total_pages}
        <span className="visually-hidden"> ({meta.total} resultados)</span>
      </span>
      <button
        type="button"
        className="btn btn--navy"
        disabled={meta.page >= meta.total_pages}
        onClick={() => onPage(meta.page + 1)}
      >
        Siguiente <ChevronRight size={16} aria-hidden="true" />
      </button>
    </nav>
  );
}
