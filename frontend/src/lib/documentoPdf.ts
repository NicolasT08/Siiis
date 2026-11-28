import type { Adjunto } from './api';
import { tamano } from './format';
import { MAX_PDF } from './archivos';

// El contenido de proyectos, artículos y noticias es texto Markdown, un PDF o ambos (DEC-B70). El PDF se guarda como
// un adjunto más (11a): el PDF principal es el primer adjunto PDF; los demás adjuntos son extras (sobre todo imágenes).

export const esAdjuntoPdf = (a: Adjunto): boolean => /\.pdf$/i.test(a.archivo) || /\.pdf$/i.test(a.nombre_archivo) || /\.pdf(\?|$)/i.test(a.url);

/** Primer adjunto PDF del documento, o null si aún no tiene. */
export function pdfPrincipal(adjuntos: Adjunto[]): Adjunto | null {
  return adjuntos.find(esAdjuntoPdf) ?? null;
}

/** Documento PDF principal: solo PDF y máximo 3 MB. Devuelve el problema o null. */
export function validarPdfDocumento(archivo: File): string | null {
  if (archivo.size === 0) return 'El archivo está vacío.';
  if (archivo.type !== 'application/pdf' && !/\.pdf$/i.test(archivo.name)) return 'El documento debe ser un PDF.';
  if (archivo.size > MAX_PDF) return `El PDF pesa ${tamano(archivo.size)}; el máximo es 3 MB.`;
  return null;
}

/**
 * Enlace de descarga. El atributo download no funciona entre dominios, así que en las URL de Cloudinary de tipo
 * image/upload se pide la descarga con fl_attachment; en otras se deja la URL tal cual.
 */
export function urlDescarga(url: string): string {
  return url.includes('/image/upload/') ? url.replace('/image/upload/', '/image/upload/fl_attachment/') : url;
}
