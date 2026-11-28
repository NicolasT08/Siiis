/**
 * Recarga automática tras un despliegue (auditoría A-02): una pestaña abierta con el build anterior pide archivos
 * de /assets que ya no existen. Se recarga UNA sola vez; la marca en sessionStorage evita un ciclo si el archivo
 * sigue sin existir. La usan ErrorBoundary y el evento `vite:preloadError` (main.tsx).
 */

const MARCA_RECARGA = 'siiis_recarga_por_chunk';
/** Tras este tiempo, un fallo nuevo (p. ej. otro despliegue en la misma pestaña) puede volver a recargar una vez. */
const VENTANA_RECARGA_MS = 60_000;

/** Errores del navegador o de Vite al cargar un módulo o una hoja de estilos diferida. */
export function esErrorDeCarga(error: unknown): boolean {
  if (!(error instanceof Error)) return false;
  if (error.name === 'ChunkLoadError') return true;
  return /dynamically imported module|Importing a module script failed|error loading dynamically imported module|Unable to preload CSS|Failed to fetch module/i.test(
    error.message,
  );
}

/** Recarga la página si no se recargó ya por este motivo hace poco. Devuelve true si va a recargar. */
export function recargarUnaVez(): boolean {
  try {
    const previa = Number(sessionStorage.getItem(MARCA_RECARGA));
    if (Number.isFinite(previa) && previa > 0 && Date.now() - previa < VENTANA_RECARGA_MS) return false;
    sessionStorage.setItem(MARCA_RECARGA, String(Date.now()));
  } catch {
    // Sin sessionStorage no se puede evitar un ciclo: mejor mostrar el aviso.
    return false;
  }
  window.location.reload();
  return true;
}

