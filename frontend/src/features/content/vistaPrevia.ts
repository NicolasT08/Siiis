import type { MouseEvent } from 'react';

/**
 * Clic en el enlace de una publicación con vista previa (RF-05): un clic normal abre la vista previa; con
 * Ctrl/Cmd/Shift/Alt o el botón central se deja al navegador abrir la página completa (otra pestaña o ventana).
 */
export function abrirVistaPrevia(evento: MouseEvent<HTMLAnchorElement>, abrir: () => void): void {
  if (evento.defaultPrevented || evento.button !== 0 || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) return;
  evento.preventDefault();
  abrir();
}
