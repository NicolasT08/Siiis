import { Component } from 'react';
import type { ErrorInfo, ReactNode } from 'react';
import { AlertTriangle } from 'lucide-react';
import { esErrorDeCarga, recargarUnaVez } from '../lib/recargaPorChunk';

/**
 * Manejo global de errores (auditoría A-02): un error al dibujar cualquier página ya no deja la pantalla en
 * blanco, sino un aviso con el estilo del sitio y el botón "Recargar".
 *
 * Caso especial: tras un despliegue, una pestaña abierta con el build anterior pide archivos de /assets que ya no
 * existen (los nombres llevan hash). Ese fallo se corrige recargando, así que se recarga sola UNA vez; la marca en
 * sessionStorage evita un ciclo si el archivo sigue sin existir (se muestra entonces el aviso).
 */

type Estado = { error: unknown; recargando: boolean };

export default class ErrorBoundary extends Component<{ children: ReactNode }, Estado> {
  state: Estado = { error: null, recargando: false };

  static getDerivedStateFromError(error: unknown): Partial<Estado> {
    return { error };
  }

  componentDidCatch(error: unknown, info: ErrorInfo) {
    if (esErrorDeCarga(error) && recargarUnaVez()) {
      this.setState({ recargando: true });
      return;
    }
    console.error('Error no controlado en la interfaz', error, info.componentStack);
  }

  render() {
    const { error, recargando } = this.state;
    if (error === null) return this.props.children;

    const deCarga = esErrorDeCarga(error);
    return (
      <main className="app-fatal">
        <div className="app-empty" role="alert">
          <AlertTriangle size={34} aria-hidden="true" />
          <h1>{recargando ? 'Actualizando el sitio…' : 'Algo salió mal'}</h1>
          {!recargando && (
            <>
              <p>
                {deCarga
                  ? 'Publicamos una versión nueva del sitio y esta página no pudo cargarse. Recarga para ver la versión actual.'
                  : 'Ocurrió un error inesperado al mostrar esta página. Recarga para intentarlo de nuevo.'}
              </p>
              <div className="app-actions">
                <button type="button" className="btn btn--navy" onClick={() => window.location.reload()}>
                  Recargar
                </button>
                <a className="btn btn--ghost" href="/">
                  Ir al inicio
                </a>
              </div>
            </>
          )}
        </div>
      </main>
    );
  }
}
