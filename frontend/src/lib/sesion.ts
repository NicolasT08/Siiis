/**
 * Vencimiento de la sesión en el navegador (RNF-05, auditoría A-04). El backend nunca emite tokens de más de 8 h
 * (`expira_en`, en segundos); aquí se guarda el instante de vencimiento para cerrar la sesión a tiempo aunque el
 * usuario no haga ninguna petición. Funciones puras: no tocan el almacenamiento ni el reloj del sistema.
 */

/** Máximo de RNF-05: aunque la API respondiera otra cosa, la sesión no pasa de 8 h. */
export const SESION_MAX_SEGUNDOS = 8 * 60 * 60;

/** Mensaje del login cuando la sesión termina sola (por tiempo o por un 401). */
export const MENSAJE_SESION_EXPIRADA = 'Tu sesión expiró. Inicia sesión de nuevo';

/** setTimeout no admite más de 2^31-1 ms (~24,8 días); 8 h quedan muy por debajo. */
const MAX_TIMEOUT_MS = 2_147_483_647;

/** Instante (ms desde epoch) en que vence un token emitido `ahora` con `expiraEn` segundos de validez. */
export function calcularVencimiento(expiraEn: number, ahora: number): number {
  const segundos = Number.isFinite(expiraEn) && expiraEn > 0 ? Math.min(expiraEn, SESION_MAX_SEGUNDOS) : SESION_MAX_SEGUNDOS;
  return ahora + segundos * 1000;
}

/** Lee el vencimiento guardado; null si falta o no es un número válido (sesiones guardadas antes de este cambio). */
export function leerVencimiento(valor: string | null): number | null {
  if (valor === null || !/^\d+$/.test(valor)) return null;
  const n = Number(valor);
  return Number.isSafeInteger(n) ? n : null;
}

/** true si la sesión ya venció. Sin vencimiento conocido no se da por vencida: la API responderá 401 si lo está. */
export function sesionVencida(vencimiento: number | null, ahora: number): boolean {
  return vencimiento !== null && ahora >= vencimiento;
}

/** Milisegundos hasta el vencimiento para programar el cierre (0 si ya venció; null si no hay vencimiento). */
export function msHastaVencer(vencimiento: number | null, ahora: number): number | null {
  if (vencimiento === null) return null;
  return Math.min(Math.max(0, vencimiento - ahora), MAX_TIMEOUT_MS);
}
