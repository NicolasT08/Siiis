import { useState } from 'react';
import { CheckCircle2, Eye, EyeOff, Flag, RotateCcw, Trash2 } from 'lucide-react';
import { api } from '../../lib/api';
import type { DocumentoEntrada, DocumentoResumen, Modulo } from '../../lib/api';
import { mensajeError } from '../../lib/errors';
import { Aviso } from '../../components/ui';

type Props = {
  modulo: Modulo;
  documento: DocumentoResumen;
  /** Si es false, no se muestran los botones de publicar (solo borrar). */
  puedePublicar: boolean;
  puedeBorrar: boolean;
  onCambio: (documento: DocumentoResumen | null) => void;
};

/**
 * Publicar, ocultar, devolver a revisión y borrar (DEC-B33 a DEC-B35). Lo usan el detalle de un documento (según
 * `permisos`) y el panel de contenido. Para publicar: proyectos → `ejecucion` + visible; artículos → `publicado` +
 * visible (docs/api.md 9d y 10).
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

  const borrar = async () => {
    if (!window.confirm(`¿Borrar "${documento.titulo}"? Se elimina para siempre, con sus archivos adjuntos.`)) return;
    setOcupado(true);
    setError('');
    try {
      await api.deleteDocument(modulo, documento.id);
      onCambio(null);
    } catch (e) {
      setError(mensajeError(e));
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
        <button type="button" className="btn btn--danger" disabled={ocupado} onClick={() => void borrar()}>
          <Trash2 size={16} aria-hidden="true" /> Borrar
        </button>
      )}
      {error && <Aviso tipo="error">{error}</Aviso>}
    </div>
  );
}
