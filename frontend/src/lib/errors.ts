import { ApiError } from './api';

/** Mensajes en español por código (docs/api.md, "Formato de error"). El `message` del backend tiene prioridad. */
const POR_CODIGO: Record<string, string> = {
  VALIDATION_ERROR: 'Revisa los datos marcados en el formulario.',
  INVALID_JSON: 'La petición no es válida. Recarga la página e intenta de nuevo.',
  INVALID_CREDENTIALS: 'Correo o contraseña incorrectos.',
  UNAUTHORIZED: 'Tu sesión terminó. Inicia sesión de nuevo.',
  FORBIDDEN: 'No tienes permiso para hacer esto.',
  ACCOUNT_BLOCKED: 'Tu cuenta está bloqueada. Escribe al semillero.',
  ACCOUNT_PENDING: 'Tu cuenta está pendiente de verificación.',
  ACCOUNT_INACTIVE: 'Tu cuenta está inactiva.',
  INVALID_GOOGLE_TOKEN: 'No se pudo completar el inicio de sesión con Google. Intenta de nuevo.',
  GOOGLE_DOMAIN_NOT_ALLOWED: 'Para entrar con Google usa tu cuenta institucional @uptc.edu.co.',
  NOT_A_MEMBER: 'Tu cuenta institucional todavía no está registrada en el semillero.',
  GOOGLE_UNAVAILABLE: 'Google no responde en este momento. Intenta de nuevo o entra con tu correo y contraseña.',
  INVALID_OR_EXPIRED_TOKEN: 'El enlace no es válido, venció o ya se usó. Pide uno nuevo.',
  EMAIL_TAKEN: 'Ya existe una cuenta con ese correo.',
  FILE_TOO_LARGE: 'El archivo supera el tamaño máximo permitido.',
  UNSUPPORTED_MEDIA_TYPE: 'El formato del archivo no es válido. Usa PDF, JPG, PNG o WebP.',
  CLOUDINARY_UNAVAILABLE: 'No se pudo guardar el archivo en este momento. Intenta de nuevo en unos minutos.',
  LAST_SUPERADMIN: 'No puedes quitar el rol ni desactivar al último superadministrador activo.',
  ATTACHMENT_LIMIT: 'El documento ya tiene el máximo de archivos adjuntos.',
  MULTIMEDIA_LIMIT: 'La sección ya tiene el máximo de elementos. Borra uno antes de subir otro.',
  INSCRIPTION_PENDING: 'Ya hay una solicitud pendiente con ese correo. Te responderemos pronto.',
  INSCRIPTION_CLOSED: 'Esta solicitud ya fue resuelta.',
  NOT_FOUND: 'No encontramos lo que buscas.',
  METHOD_NOT_ALLOWED: 'Esta acción no está disponible.',
  RATE_LIMITED: 'Hiciste demasiados intentos.',
  DB_UNAVAILABLE: 'El servicio no está disponible en este momento. Intenta más tarde.',
  INTERNAL_ERROR: 'Ocurrió un error inesperado. Intenta de nuevo.',
  NETWORK_ERROR: 'No se pudo conectar con el servidor. Revisa tu conexión e intenta de nuevo.',
};

const POR_ESTADO: Record<number, string> = {
  400: POR_CODIGO.VALIDATION_ERROR,
  401: POR_CODIGO.UNAUTHORIZED,
  403: POR_CODIGO.FORBIDDEN,
  404: POR_CODIGO.NOT_FOUND,
  409: 'La acción entra en conflicto con el estado actual. Recarga e intenta de nuevo.',
  413: POR_CODIGO.FILE_TOO_LARGE,
  415: POR_CODIGO.UNSUPPORTED_MEDIA_TYPE,
  429: POR_CODIGO.RATE_LIMITED,
  502: POR_CODIGO.CLOUDINARY_UNAVAILABLE,
  503: POR_CODIGO.DB_UNAVAILABLE,
};

function espera(segundos: number): string {
  if (segundos < 60) return `${segundos} segundos`;
  const minutos = Math.ceil(segundos / 60);
  return minutos === 1 ? '1 minuto' : `${minutos} minutos`;
}

/** Texto general del error para mostrar en un aviso. */
export function mensajeError(error: unknown): string {
  if (!(error instanceof ApiError)) return POR_CODIGO.INTERNAL_ERROR;
  const base = error.message || POR_CODIGO[error.code] || POR_ESTADO[error.status] || POR_CODIGO.INTERNAL_ERROR;
  if (error.status === 429 && error.retryAfter) return `${base} Espera ${espera(error.retryAfter)} y vuelve a intentarlo.`;
  return base;
}

/** Mensajes por campo de un 400 VALIDATION_ERROR (vacío en cualquier otro caso). */
export function erroresDeCampo(error: unknown): Record<string, string> {
  return error instanceof ApiError ? error.fields : {};
}

export function esNoEncontrado(error: unknown): boolean {
  return error instanceof ApiError && error.status === 404;
}
