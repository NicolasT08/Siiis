import { useCallback, useEffect, useState } from 'react';

export type Estado<T> = { status: 'loading' } | { status: 'error'; error: unknown } | { status: 'ready'; data: T };

/**
 * Carga datos de la API y los vuelve a pedir cuando cambian las dependencias o al llamar a `recargar`.
 * Cancela la petición anterior (AbortController) para que una respuesta vieja no pise a la nueva.
 */
export function useApi<T>(cargar: (signal: AbortSignal) => Promise<T>, deps: readonly unknown[]) {
  const [intento, setIntento] = useState(0);
  const [resultado, setResultado] = useState<{ clave: string; estado: Estado<T> } | null>(null);
  const clave = JSON.stringify([...deps, intento]);

  useEffect(() => {
    const controller = new AbortController();
    cargar(controller.signal)
      .then((data) => setResultado({ clave, estado: { status: 'ready', data } }))
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        setResultado({ clave, estado: { status: 'error', error } });
      });
    return () => controller.abort();
    // `cargar` cambia en cada render; las dependencias reales son `clave`.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [clave]);

  const recargar = useCallback(() => setIntento((n) => n + 1), []);
  const estado: Estado<T> = resultado?.clave === clave ? resultado.estado : { status: 'loading' };
  // Mientras recarga se conservan los últimos datos para no parpadear.
  const datos = estado.status === 'ready' ? estado.data : resultado?.estado.status === 'ready' ? resultado.estado.data : null;

  return { estado, datos, recargar };
}
