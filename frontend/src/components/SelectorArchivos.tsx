import { useId, useRef } from 'react';
import { FileText, Paperclip, X } from 'lucide-react';
import { tamano } from '../lib/format';

type Props = {
  /** Archivos elegidos (controlado por el formulario). */
  archivos: File[];
  onChange: (archivos: File[]) => void;
  accept: string;
  /** Máximo de archivos; con 1 el botón reemplaza el archivo elegido. */
  max?: number;
  etiqueta?: string;
  ayuda?: string;
  disabled?: boolean;
  invalido?: boolean;
  /** id del mensaje de error asociado (aria-describedby). */
  errorId?: string;
};

/**
 * Selector de archivos con el estilo de los botones del sitio, en lugar del control nativo del navegador. El input
 * real queda oculto pero accesible (se activa con el botón o con el teclado) y la lista muestra lo elegido, con
 * botón para quitar cada archivo.
 */
export default function SelectorArchivos({ archivos, onChange, accept, max = 1, etiqueta, ayuda, disabled = false, invalido = false, errorId }: Props) {
  const id = useId();
  const input = useRef<HTMLInputElement>(null);
  const lleno = max > 1 && archivos.length >= max;

  const elegir = (lista: FileList | null) => {
    const nuevos = Array.from(lista ?? []);
    if (input.current) input.current.value = '';
    if (nuevos.length === 0) return;
    onChange(max === 1 ? nuevos.slice(0, 1) : [...archivos, ...nuevos].slice(0, max));
  };

  return (
    <div className={`file-picker${invalido ? ' is-invalid' : ''}`}>
      <input
        ref={input}
        id={id}
        type="file"
        className="visually-hidden"
        accept={accept}
        multiple={max > 1}
        disabled={disabled || lleno}
        aria-invalid={invalido}
        aria-describedby={[`${id}-ayuda`, errorId].filter(Boolean).join(' ')}
        onChange={(e) => elegir(e.target.files)}
      />
      <label htmlFor={id} className={`btn btn--navy btn-archivo file-picker__button${disabled || lleno ? ' is-disabled' : ''}`}>
        <Paperclip size={16} aria-hidden="true" />
        {etiqueta ?? (max > 1 ? 'Seleccionar archivos' : archivos.length ? 'Cambiar archivo' : 'Seleccionar archivo')}
      </label>
      <span id={`${id}-ayuda`} className="file-picker__hint">
        {ayuda}
        {max > 1 && ` (${archivos.length} de ${max})`}
      </span>
      {archivos.length === 0 ? (
        <p className="file-picker__empty">Ningún archivo elegido.</p>
      ) : (
        <ul className="file-picker__list">
          {archivos.map((archivo, i) => (
            <li key={`${archivo.name}-${i}`}>
              <FileText size={15} aria-hidden="true" />
              <span className="file-picker__name">{archivo.name}</span>
              <span className="file-picker__size">{tamano(archivo.size)}</span>
              <button
                type="button"
                className="file-picker__remove"
                disabled={disabled}
                aria-label={`Quitar ${archivo.name}`}
                onClick={() => onChange(archivos.filter((_, j) => j !== i))}
              >
                <X size={15} aria-hidden="true" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
