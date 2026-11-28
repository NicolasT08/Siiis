import { useRef, useState } from 'react';
import type { LucideIcon } from 'lucide-react';
import { Bold, Heading2, Heading3, Italic, Link2, List, ListOrdered, Quote } from 'lucide-react';
import Markdown from './Markdown';

type Accion =
  | { tipo: 'envolver'; antes: string; despues: string; ejemplo: string }
  | { tipo: 'linea'; prefijo: (i: number) => string; ejemplo: string };

const BOTONES: { etiqueta: string; Icono: LucideIcon; accion: Accion }[] = [
  { etiqueta: 'Negrita', Icono: Bold, accion: { tipo: 'envolver', antes: '**', despues: '**', ejemplo: 'texto en negrita' } },
  { etiqueta: 'Cursiva', Icono: Italic, accion: { tipo: 'envolver', antes: '_', despues: '_', ejemplo: 'texto en cursiva' } },
  { etiqueta: 'Título', Icono: Heading2, accion: { tipo: 'linea', prefijo: () => '## ', ejemplo: 'Título' } },
  { etiqueta: 'Subtítulo', Icono: Heading3, accion: { tipo: 'linea', prefijo: () => '### ', ejemplo: 'Subtítulo' } },
  { etiqueta: 'Lista con viñetas', Icono: List, accion: { tipo: 'linea', prefijo: () => '- ', ejemplo: 'Elemento' } },
  { etiqueta: 'Lista numerada', Icono: ListOrdered, accion: { tipo: 'linea', prefijo: (i) => `${i + 1}. `, ejemplo: 'Elemento' } },
  { etiqueta: 'Enlace', Icono: Link2, accion: { tipo: 'envolver', antes: '[', despues: '](https://)', ejemplo: 'texto del enlace' } },
  { etiqueta: 'Cita', Icono: Quote, accion: { tipo: 'linea', prefijo: () => '> ', ejemplo: 'Cita' } },
];

/** Aplica la acción sobre la selección [inicio, fin) y devuelve el texto nuevo y la selección resultante. */
function aplicar(texto: string, inicio: number, fin: number, accion: Accion): { texto: string; inicio: number; fin: number } {
  if (accion.tipo === 'envolver') {
    const seleccion = texto.slice(inicio, fin) || accion.ejemplo;
    const nuevo = texto.slice(0, inicio) + accion.antes + seleccion + accion.despues + texto.slice(fin);
    const desde = inicio + accion.antes.length;
    return { texto: nuevo, inicio: desde, fin: desde + seleccion.length };
  }
  // Por líneas: desde el inicio de la primera línea seleccionada hasta el final de la última.
  const desde = texto.lastIndexOf('\n', inicio - 1) + 1;
  const finLinea = texto.indexOf('\n', fin);
  const hasta = finLinea === -1 ? texto.length : finLinea;
  const bloque = texto.slice(desde, hasta) || accion.ejemplo;
  const lineas = bloque.split('\n').map((linea, i) => accion.prefijo(i) + linea);
  const reemplazo = lineas.join('\n');
  return { texto: texto.slice(0, desde) + reemplazo + texto.slice(hasta), inicio: desde, fin: desde + reemplazo.length };
}

type Props = {
  id: string;
  valor: string;
  onChange: (valor: string) => void;
  maxLength: number;
  disabled?: boolean;
  invalido?: boolean;
  describedBy?: string;
};

/**
 * Editor de Markdown ligero (DEC-B37, DEC-B70): un textarea con botones que insertan la sintaxis alrededor de la
 * selección, y una vista previa con el mismo renderizador seguro del detalle (sin HTML crudo).
 */
export default function MarkdownEditor({ id, valor, onChange, maxLength, disabled, invalido, describedBy }: Props) {
  const [vista, setVista] = useState<'escribir' | 'previa'>('escribir');
  const ref = useRef<HTMLTextAreaElement>(null);

  const usar = (accion: Accion) => {
    const area = ref.current;
    if (!area) return;
    const r = aplicar(valor, area.selectionStart, area.selectionEnd, accion);
    if (r.texto.length > maxLength) return;
    onChange(r.texto);
    // Tras el render, el cursor queda sobre lo insertado para seguir escribiendo.
    requestAnimationFrame(() => {
      area.focus();
      area.setSelectionRange(r.inicio, r.fin);
    });
  };

  return (
    <div className="md-editor">
      <div className="app-tabs md-editor__tabs" role="tablist" aria-label="Modo del editor">
        {(['escribir', 'previa'] as const).map((modo) => (
          <button
            key={modo}
            type="button"
            role="tab"
            id={`${id}-tab-${modo}`}
            aria-selected={vista === modo}
            aria-controls={`${id}-panel`}
            className={vista === modo ? 'is-active' : undefined}
            onClick={() => setVista(modo)}
          >
            {modo === 'escribir' ? 'Escribir' : 'Vista previa'}
          </button>
        ))}
      </div>

      <div id={`${id}-panel`} role="tabpanel" aria-labelledby={`${id}-tab-${vista}`}>
        {vista === 'escribir' ? (
          <>
            <div className="md-editor__toolbar" role="toolbar" aria-label="Formato" aria-controls={id}>
              {BOTONES.map(({ etiqueta, Icono, accion }) => (
                <button
                  key={etiqueta}
                  type="button"
                  className="btn btn--ghost btn--sm"
                  title={etiqueta}
                  aria-label={etiqueta}
                  disabled={disabled}
                  onClick={() => usar(accion)}
                >
                  <Icono size={16} aria-hidden="true" />
                </button>
              ))}
            </div>
            <textarea
              ref={ref}
              id={id}
              name={id}
              value={valor}
              onChange={(e) => onChange(e.target.value)}
              rows={12}
              maxLength={maxLength}
              disabled={disabled}
              aria-invalid={invalido}
              aria-describedby={describedBy}
              placeholder="Escribe aquí. Usa los botones para dar formato."
            />
          </>
        ) : valor.trim() ? (
          <div className="md-editor__preview">
            <Markdown texto={valor} />
          </div>
        ) : (
          <p className="app-hint md-editor__preview">Todavía no hay texto para previsualizar.</p>
        )}
      </div>
    </div>
  );
}
