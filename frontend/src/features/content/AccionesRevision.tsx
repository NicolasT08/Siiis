import { useState } from 'react';
import { CheckCircle2, Eye, EyeOff, Flag, RotateCcw, Trash2 } from 'lucide-react';
import { api } from '../../lib/api';
import type { DocumentoEntrada, DocumentoResumen, Modulo } from '../../lib/api';
import { mensajeError } from '../../lib/errors';
import { Aviso } from '../../components/ui';
import ConfirmDialog from '../../components/ConfirmDialog';

type Props = {
  modulo: Modulo;
  documento: DocumentoResumen;
  /** Si es false, no se muestran los botones de publicar (solo eliminar). */
  puedePublicar: boolean;
  puedeBorrar: boolean;
  onCambio: (documento: DocumentoResumen | null) => void;
};

/**
 * Publicar, ocultar, devolver a revisión y eliminar (DEC-B33 a DEC-B35); eliminar pide confirmación con ConfirmDialog.
 * Lo usan el detalle de un documento (según `permisos`) y el panel de contenido. Para publicar: proyectos →
 * `ejecucion` + visible; artículos → `publicado` + visible (docs/api.md 9d y 10).
 */
export default function AccionesRevision({ modulo, documento, puedePublicar, puedeBorrar, onCambio }: Props) {
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState('');

  const aplicar = async (cambio: DocumentoEntrada) => {
    setOcupado(true);
    setError('');
    try {
      const { documento: nuevo } = await api.updateDocument(modulo, documento.id, cambio);
      onCambio(nuevo);
    } catch (e) {
      setError(mensajeError(e));
    } finally {
      setOcupado(false);
    }
  };

  // El backend borra también sus archivos de Cloudinary (DEC-B35).
  const [confirmando, setConfirmando] = useState(false);
  const [errorBorrar, setErrorBorrar] = useState('');
  const eliminar = async () => {
    setOcupado(true);
    setErrorBorrar('');
    try {
      await api.deleteDocument(modulo, documento.id);
      setConfirmando(false);
      onCambio(null);
    } catch (e) {
      setErrorBorrar(mensajeError(e));
      setOcupado(false);
    }
  };

  const enRevision = documento.estado === 'en_revision';
  const estadoPublicado = modulo === 'projects' ? 'ejecucion' : 'publicado';

  return (
    <div className="app-actions">
      {puedePublicar && enRevision && (
        <button type="button" className="btn btn--navy" disabled={ocupado} onClick={() => void aplicar({ estado: estadoPublicado, visible: true })}>
          <CheckCircle2 size={16} aria-hidden="true" /> Publicar
        </button>
      )}
      {puedePublicar && !enRevision && documento.visible && (
        <button type="button" className="btn btn--bronze" disabled={ocupado} onClick={() => void aplicar({ visible: false })}>
          <EyeOff size={16} aria-hidden="true" /> Ocultar
        </button>
      )}
      {puedePublicar && !enRevision && !documento.visible && (
        <button type="button" className="btn btn--navy" disabled={ocupado} onClick={() => void aplicar({ visible: true })}>
          <Eye size={16} aria-hidden="true" /> Mostrar
        </button>
      )}
      {puedePublicar && modulo === 'projects' && documento.estado === 'ejecucion' && (
        <button type="button" className="btn btn--navy" disabled={ocupado} onClick={() => void aplicar({ estado: 'finalizado' })}>
          <Flag size={16} aria-hidden="true" /> Marcar finalizado
        </button>
      )}
      {puedePublicar && !enRevision && (
        <button type="button" className="btn btn--ghost" disabled={ocupado} onClick={() => void aplicar({ estado: 'en_revision', visible: false })}>
          <RotateCcw size={16} aria-hidden="true" /> Devolver a revisión
        </button>
      )}
      {puedeBorrar && (
        <button
          type="button"
          className="btn btn--danger"
          disabled={ocupado}
          onClick={() => {
            setErrorBorrar('');
            setConfirmando(true);
          }}
        >
          <Trash2 size={16} aria-hidden="true" /> Eliminar
        </button>
      )}
      {error && <Aviso tipo="error">{error}</Aviso>}
      {confirmando && (
        <ConfirmDialog
          titulo={`¿Eliminar ${modulo === 'projects' ? 'este proyecto' : documento.categoria === 'noticia' ? 'esta noticia' : 'este artículo'}?`}
          confirmar="Eliminar"
          ocupado={ocupado}
          error={errorBorrar}
          onConfirmar={() => void eliminar()}
          onCancelar={() => setConfirmando(false)}
        >
          <p>
            <strong>«{documento.titulo}»</strong>
          </p>
          <p>Se elimina para siempre, junto con sus archivos adjuntos. No se puede deshacer.</p>
        </ConfirmDialog>
      )}
    </div>
  );
}
