import { useEffect, useId, useRef } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { AlertTriangle, Loader2 } from 'lucide-react';
import { Aviso } from './ui';

type Props = {
  titulo: string;
  children: ReactNode;
  /** Texto del botón que confirma, p. ej. "Eliminar". */
  confirmar: string;
  ocupado?: boolean;
  /** Error de la acción, que se muestra dentro del diálogo para poder reintentar. */
  error?: string;
  onConfirmar: () => void;
  onCancelar: () => void;
};

/**
 * Diálogo de confirmación para acciones que no se pueden deshacer. Usa el mismo fondo que el inicio de sesión
 * (`modal-overlay`), enfoca "Cancelar" al abrir, mantiene el foco dentro y se cierra con Escape o clic afuera
 * (salvo mientras la acción está en curso). Reemplaza a `window.confirm`, que algunos navegadores bloquean.
 */
export default function ConfirmDialog({ titulo, children, confirmar, ocupado = false, error, onConfirmar, onCancelar }: Props) {
  const tituloId = useId();
  const textoId = useId();
  const cajaRef = useRef<HTMLDivElement>(null);
  const cancelarRef = useRef<HTMLButtonElement>(null);
  const cancelar = useRef(onCancelar);
  const bloqueado = useRef(ocupado);
  useEffect(() => {
    cancelar.current = onCancelar;
    bloqueado.current = ocupado;
  }, [onCancelar, ocupado]);

  useEffect(() => {
    const anterior = document.activeElement as HTMLElement | null;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    cancelarRef.current?.focus();

    const teclado = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        if (!bloqueado.current) cancelar.current();
        return;
      }
      if (e.key !== 'Tab' || !cajaRef.current) return;
      const botones = Array.from(cajaRef.current.querySelectorAll<HTMLElement>('button:not([disabled])'));
      if (botones.length === 0) return;
      const primero = botones[0];
      const ultimo = botones[botones.length - 1];
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
        if (e.target === e.currentTarget && !ocupado) onCancelar();
      }}
    >
      <div ref={cajaRef} className="app-dialog" role="alertdialog" aria-modal="true" aria-labelledby={tituloId} aria-describedby={textoId}>
        <div className="app-dialog__icon" aria-hidden="true">
          <AlertTriangle size={22} />
        </div>
        <h2 id={tituloId}>{titulo}</h2>
        <div id={textoId} className="app-dialog__body">
          {children}
        </div>
        {error && <Aviso tipo="error">{error}</Aviso>}
        <div className="app-dialog__actions">
          <button ref={cancelarRef} type="button" className="btn btn--ghost" disabled={ocupado} onClick={onCancelar}>
            Cancelar
          </button>
          <button type="button" className="btn btn--danger" disabled={ocupado} onClick={onConfirmar}>
            {ocupado && <Loader2 className="app-spin" size={16} aria-hidden="true" />} {confirmar}
          </button>
        </div>
      </div>
    </div>,
    document.body,
  );
}
