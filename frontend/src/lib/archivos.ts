import { tamano } from './format';

// Límites del backend (DEC-B29, DEC-B40, DEC-B42). Se validan en el navegador para avisar antes de subir; el backend
// revisa el contenido real y responde 413/415 si algo no cumple.
export const MAX_PDF = 3 * 1024 * 1024;
export const MAX_IMAGEN = 5 * 1024 * 1024;
export const MAX_FOTO_PERFIL = 2 * 1024 * 1024;
/** MULTIMEDIA_VIDEO_MAX_BYTES del backend (DEC-B62); si el servidor tiene otro valor, responde 413 con el suyo. */
export const MAX_VIDEO = 50 * 1024 * 1024;

const VIDEOS = ['video/mp4', 'video/webm', 'video/quicktime'];

const IMAGENES = ['image/jpeg', 'image/png', 'image/webp'];
const EXT_IMAGEN = /\.(jpe?g|png|webp)$/i;

function esPdf(archivo: File): boolean {
  return archivo.type === 'application/pdf' || /\.pdf$/i.test(archivo.name);
}

function esImagen(archivo: File): boolean {
  return IMAGENES.includes(archivo.type) || EXT_IMAGEN.test(archivo.name);
}

/** Adjunto o documento de inscripción: PDF ≤ 3 MB o imagen ≤ 5 MB. Devuelve el problema o null. */
export function validarArchivo(archivo: File): string | null {
  if (archivo.size === 0) return 'El archivo está vacío.';
  if (esPdf(archivo)) return archivo.size > MAX_PDF ? `El PDF pesa ${tamano(archivo.size)}; el máximo es 3 MB.` : null;
  if (esImagen(archivo)) return archivo.size > MAX_IMAGEN ? `La imagen pesa ${tamano(archivo.size)}; el máximo es 5 MB.` : null;
  return 'Formato no admitido. Usa PDF, JPG, PNG o WebP.';
}

/** Foto de perfil: JPEG, PNG o WebP ≤ 2 MB. */
export function validarFoto(archivo: File): string | null {
  if (!esImagen(archivo)) return 'La foto debe ser JPG, PNG o WebP.';
  if (archivo.size > MAX_FOTO_PERFIL) return `La foto pesa ${tamano(archivo.size)}; el máximo es 2 MB.`;
  return null;
}

/** Foto del carrusel: JPEG, PNG o WebP ≤ 5 MB (las mismas reglas de las imágenes adjuntas). */
export function validarFotoInicio(archivo: File): string | null {
  if (!esImagen(archivo)) return 'La foto debe ser JPG, PNG o WebP.';
  if (archivo.size > MAX_IMAGEN) return `La foto pesa ${tamano(archivo.size)}; el máximo es 5 MB.`;
  return null;
}

/** Video de presentación: MP4, WebM o MOV ≤ 50 MB. */
export function validarVideo(archivo: File): string | null {
  if (!VIDEOS.includes(archivo.type) && !/\.(mp4|webm|mov)$/i.test(archivo.name)) return 'El video debe ser MP4, WebM o MOV.';
  if (archivo.size > MAX_VIDEO) return `El video pesa ${tamano(archivo.size)}; el máximo es 50 MB.`;
  return null;
}
