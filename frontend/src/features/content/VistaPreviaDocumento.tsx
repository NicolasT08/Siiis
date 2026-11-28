import { useEffect, useId, useRef } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import { ArrowUpRight, Calendar, User, X } from 'lucide-react';
import { api } from '../../lib/api';
import type { DocumentoResumen } from '../../lib/api';
import { fecha, moduloDeCategoria, nombrePersona, rutaDocumento } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { esNoEncontrado } from '../../lib/errors';
import { Cargando, ErrorCarga, Vacio } from '../../components/ui';
import { CategoriaChip, EstadoBadge } from './DocumentCard';
import DocumentoContenido from './DocumentoContenido';

type Props = {
  /** Resumen del listado: se muestra enseguida mientras llega el documento completo. */
  documento: DocumentoResumen;
  onCerrar: () => void;
};

const ENFOCABLES = 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Vista previa de una publicación en la misma pantalla (RF-05): el texto renderizado y el visor de PDF de la página
 * de detalle, con un enlace a la página completa. Se carga en diferido (trae el renderizador de Markdown). Mismo
 * fondo que los demás diálogos; foco atrapado, Escape o clic afuera para cerrar y devuelve el foco al salir.
 */
export default function VistaPreviaDocumento({ documento, onCerrar }: Props) {
  const tituloId = useId();
  const cajaRef = useRef<HTMLDivElement>(null);
  const cerrarRef = useRef<HTMLButtonElement>(null);
  const cerrar = useRef(onCerrar);
  useEffect(() => {
    cerrar.current = onCerrar;
  }, [onCerrar]);

  const modulo = moduloDeCategoria(documento.categoria);
  const ruta = rutaDocumento(modulo, documento.id);
  const { estado, recargar } = useApi((signal) => api.document(modulo, documento.id, signal), [modulo, documento.id]);
  const completo = estado.status === 'ready' ? estado.data.documento : null;

  useEffect(() => {
    const anterior = document.activeElement as HTMLElement | null;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    cerrarRef.current?.focus();

    const teclado = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        cerrar.current();
        return;
      }
      if (e.key !== 'Tab' || !cajaRef.current) return;
      const enfocables = Array.from(cajaRef.current.querySelectorAll<HTMLElement>(ENFOCABLES));
      if (enfocables.length === 0) return;
      const primero = enfocables[0];
      const ultimo = enfocables[enfocables.length - 1];
      if (e.shiftKey && document.activeElement === primero) {
        e.preventDefault();
        ultimo.focus();
      } else if (!e.shiftKey && document.activeElement === ultimo) {
        e.preventDefault();
        primero.focus();
      }
    };
    document.addEventListener('keydown', teclado);
    return () => {
      document.removeEventListener('keydown', teclado);
      document.body.style.overflow = overflow;
      anterior?.focus();
    };
  }, []);

  return createPortal(
    <div
      className="modal-overlay is-visible"
      onClick={(e) => {
        if (e.target === e.currentTarget) onCerrar();
      }}
    >
      <div ref={cajaRef} className="app-dialog app-dialog--preview" role="dialog" aria-modal="true" aria-labelledby={tituloId}>
        <div className="doc-preview__head">
          <div className="project-detail__tags">
            <CategoriaChip categoria={documento.categoria} />
            <EstadoBadge estado={documento.estado} visible={documento.visible} />
          </div>
          <button ref={cerrarRef} type="button" className="btn btn--ghost btn--sm doc-preview__close" onClick={onCerrar} aria-label="Cerrar vista previa">
            <X size={18} aria-hidden="true" />
          </button>
        </div>
        <h2 id={tituloId}>{documento.titulo}</h2>
        <ul className="project-detail__meta">
          <li>
            <User size={16} aria-hidden="true" /> {nombrePersona(documento.autor)}
          </li>
          <li>
            <Calendar size={16} aria-hidden="true" />
            <time dateTime={documento.fecha_creacion}>{fecha(documento.fecha_creacion)}</time>
          </li>
        </ul>
        <p className="project-detail__summary">{documento.descripcion}</p>

        {estado.status === 'loading' && <Cargando texto="Cargando la vista previa…" />}
        {estado.status === 'error' &&
          (esNoEncontrado(estado.error) ? (
            <Vacio titulo="Esta publicación ya no está disponible">Puede que se haya retirado o que esté de nuevo en revisión.</Vacio>
          ) : (
            <ErrorCarga error={estado.error} onRetry={recargar} titulo="No pudimos cargar la vista previa" />
          ))}
        {completo && <DocumentoContenido documento={completo} />}
        {completo && !completo.contenido?.trim() && !completo.archivos_adjuntos.length && (
          <p className="app-hint">Esta publicación no tiene texto ni documento para previsualizar.</p>
        )}

        <div className="app-dialog__actions">
          <button type="button" className="btn btn--ghost" onClick={onCerrar}>
            Cerrar
          </button>
          <Link className="btn btn--navy" to={ruta} onClick={onCerrar}>
            Abrir página completa <ArrowUpRight size={16} aria-hidden="true" />
          </Link>
        </div>
      </div>
    </div>,
    document.body,
  );
}
