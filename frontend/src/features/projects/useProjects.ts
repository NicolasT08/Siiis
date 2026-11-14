import { useCallback, useEffect, useState } from 'react';
import { api } from '../../lib/api';
import type { Proyecto } from '../../lib/api';

export type ProjectsState =
  | { status: 'loading' }
  | { status: 'error'; error: unknown }
  | { status: 'ready'; proyectos: Proyecto[]; demo: boolean };

/**
 * Modo demostración: SOLO en `npm run dev` y con VITE_PROJECTS_DEMO=true en .env.local.
 * En el build de producción `import.meta.env.DEV` es false y los datos de ejemplo ni se empaquetan.
 */
const DEMO = import.meta.env.DEV && import.meta.env.VITE_PROJECTS_DEMO === 'true';

function porFechaDesc(a: Proyecto, b: Proyecto): number {
  return (Date.parse(b.fecha_publicacion) || 0) - (Date.parse(a.fecha_publicacion) || 0);
}

const LOADING: ProjectsState = { status: 'loading' };

export function useProjects() {
  const [intento, setIntento] = useState(0);
  // El resultado recuerda a qué intento pertenece: si no coincide con el actual, se está cargando.
  const [resultado, setResultado] = useState<{ intento: number; state: ProjectsState } | null>(null);

  useEffect(() => {
    const controller = new AbortController();

    const cargar = DEMO
      ? import('./demoProjects').then((m) => ({ data: m.DEMO_PROYECTOS }))
      : api.proyectos(controller.signal);

    cargar
      .then(({ data }) =>
        setResultado({ intento, state: { status: 'ready', proyectos: [...data].sort(porFechaDesc), demo: DEMO } }),
      )
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        setResultado({ intento, state: { status: 'error', error } });
      });

    return () => controller.abort();
  }, [intento]);

  const reintentar = useCallback(() => setIntento((n) => n + 1), []);
  const state = resultado?.intento === intento ? resultado.state : LOADING;

  return { state, reintentar };
}
