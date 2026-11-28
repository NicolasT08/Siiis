/**
 * Transformaciones de entrega de Cloudinary en el navegador. La API ya entrega las imágenes con `f_auto,q_auto`
 * (backend: Siiis\Support\CloudinaryUrl); aquí se agrega además un ancho máximo (`c_limit,w_N`, nunca agranda)
 * para no descargar una foto de 4000 px donde se muestra a 1000. Las URL que no son de Cloudinary, los PDF y
 * las que ya traen un tamaño se devuelven tal cual.
 */
const PATRON = /^(https:\/\/res\.cloudinary\.com\/[^/?#]+\/(image|video)\/upload\/)([^?#]*)(.*)$/;
const SEGMENTO_TRANSFORMACION = /^[a-z]{1,3}_[^,/]+(,[a-z]{1,3}_[^,/]+)*$/;

function partes(url: string) {
  const m = PATRON.exec(url);
  if (!m) return null;
  const [, base, recurso, ruta, resto] = m;
  const [primero, ...demas] = ruta.split('/');
  const transformacion = SEGMENTO_TRANSFORMACION.test(primero) ? primero : '';
  return { base, recurso, transformacion, ruta: transformacion ? demas.join('/') : ruta, resto };
}

/** Imagen de Cloudinary limitada a `ancho` px, con formato y calidad automáticos. */
export function imagenCloudinary(url: string | undefined, ancho: number): string | undefined {
  if (!url) return url;
  const p = partes(url);
  if (!p || p.recurso !== 'image' || /\.pdf$/i.test(p.ruta)) return url;
  // Si la URL ya trae un tamaño o recorte, se respeta.
  if (/(^|,)(w|h|c)_/.test(p.transformacion)) return url;
  const base = p.transformacion || 'f_auto,q_auto';
  return `${p.base}${base},c_limit,w_${Math.round(ancho)}/${p.ruta}${p.resto}`;
}

/** `srcset` con varios anchos de la misma imagen de Cloudinary; '' si la URL no es de Cloudinary. */
export function srcsetCloudinary(url: string | undefined, anchos: number[]): string {
  if (!url) return '';
  const variantes = anchos.map((w) => [imagenCloudinary(url, w), w] as const);
  if (variantes.every(([u]) => u === url)) return '';
  return variantes.map(([u, w]) => `${u} ${w}w`).join(', ');
}

/** Portada (poster) de un video de Cloudinary: un fotograma del segundo 1, en JPG/WebP y con ancho máximo. */
export function posterVideoCloudinary(url: string | undefined, ancho: number): string | undefined {
  if (!url) return undefined;
  const p = partes(url);
  if (!p || p.recurso !== 'video') return undefined;
  const ruta = p.ruta.replace(/\.[A-Za-z0-9]{2,5}$/, '') + '.jpg';
  return `${p.base}so_1,f_auto,q_auto,c_limit,w_${Math.round(ancho)}/${ruta}`;
}

/** Logos oficiales del semillero (Cloudinary del proyecto). */
export const LOGO_BLANCO = 'https://res.cloudinary.com/we3ya7sq/image/upload/v1789264275/Logo_Png_Blanco.png';
export const LOGO_AZUL = 'https://res.cloudinary.com/we3ya7sq/image/upload/v1788989589/Logo_Azul.png';
