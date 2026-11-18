import { useCallback, useEffect, useState } from 'react';
import { api } from '../../lib/api';
import type { Miembro } from '../../lib/api';

export type MembersState =
  | { status: 'loading' }
  | { status: 'error'; error: unknown }
  | { status: 'ready'; miembros: Miembro[]; demo: boolean };

/**
 * Modo demostración: SOLO en `npm run dev` y con VITE_USERS_DEMO=true en .env.local.
 * En producción `import.meta.env.DEV` es false y los datos de ejemplo ni se empaquetan.
 */
const DEMO = import.meta.env.DEV && import.meta.env.VITE_USERS_DEMO === 'true';
const LOADING: MembersState = { status: 'loading' };

const porNombre = (a: Miembro, b: Miembro) =>
  `${a.nombre} ${a.apellido}`.localeCompare(`${b.nombre} ${b.apellido}`, 'es');

export function useMembers() {
  const [intento, setIntento] = useState(0);
  // El resultado recuerda a qué intento pertenece: si no coincide con el actual, se está cargando.
  const [resultado, setResultado] = useState<{ intento: number; state: MembersState } | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    const cargar = DEMO
      ? import('./demoMembers').then((m) => ({ data: m.DEMO_MIEMBROS }))
      : api.usuarios(controller.signal);

    cargar
      .then(({ data }) =>
        setResultado({ intento, state: { status: 'ready', miembros: [...data].sort(porNombre), demo: DEMO } }),
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
