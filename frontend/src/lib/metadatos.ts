/**
 * Título y descripción de cada página principal (SEO), sin librerías: AppLayout los aplica al cambiar de ruta.
 * Las descripciones van en español y con 155 caracteres como máximo.
 */
export const SITIO_URL = 'https://siiis.com.co';

const MARCA = 'SIIIS — Semillero de Investigación UPTC';

export const DESCRIPCION_GENERAL =
  'Semillero de Investigación en Ingeniería de Sistemas, Información e Innovación Social de la UPTC, en Tunja, Boyacá.';

type Metadatos = { titulo: string; descripcion: string };

const POR_RUTA: { ruta: RegExp; titulo?: string; descripcion: string }[] = [
  { ruta: /^\/$/, descripcion: DESCRIPCION_GENERAL },
  {
    ruta: /^\/acerca-de\/?$/,
    titulo: 'Acerca de',
    descripcion: 'Conoce la historia, la misión, la visión y el equipo del Semillero de Investigación SIIIS de la UPTC.',
  },
  {
    ruta: /^\/usuarios(\/|$)/,
    titulo: 'Integrantes',
    descripcion: 'Integrantes del Semillero SIIIS: estudiantes, docentes e investigadores de Ingeniería de Sistemas de la UPTC.',
  },
  {
    ruta: /^\/proyectos(\/|$)/,
    titulo: 'Proyectos',
    descripcion: 'Proyectos de investigación, desarrollo e innovación del Semillero SIIIS en sistemas de información, IA y análisis social.',
  },
  {
    ruta: /^\/articulos(\/|$)/,
    titulo: 'Artículos',
    descripcion: 'Artículos, investigaciones y publicaciones del Semillero de Investigación SIIIS de la UPTC.',
  },
  {
    ruta: /^\/noticias\/?$/,
    titulo: 'Noticias',
    descripcion: 'Noticias y novedades del Semillero de Investigación SIIIS de la UPTC.',
  },
  {
    ruta: /^\/resenas\/?$/,
    titulo: 'Reseñas',
    descripcion: 'Opiniones y reseñas de la comunidad sobre el Semillero de Investigación SIIIS de la UPTC.',
  },
  {
    ruta: /^\/contacto\/?$/,
    titulo: 'Contacto',
    descripcion: 'Escríbele al Semillero SIIIS de la UPTC: dirección en Tunja, teléfono, correo y formulario de contacto.',
  },
  {
    ruta: /^\/inscripcion\/?$/,
    titulo: 'Inscripción',
    descripcion: 'Inscríbete en el Semillero de Investigación SIIIS con tu correo institucional de la UPTC.',
  },
  { ruta: /^\/login\/?$/, titulo: 'Iniciar sesión', descripcion: 'Inicia sesión en el sitio del Semillero SIIIS de la UPTC.' },
  {
    ruta: /^\/privacidad\/?$/,
    titulo: 'Política de privacidad',
    descripcion: 'Cómo el Semillero SIIIS recolecta, usa y protege los datos personales, conforme a la Ley 1581 de 2012.',
  },
  {
    ruta: /^\/terminos\/?$/,
    titulo: 'Términos de uso',
    descripcion: 'Términos y condiciones de uso del sitio web del Semillero de Investigación SIIIS de la UPTC.',
  },
];

export function metadatosDeRuta(pathname: string): Metadatos {
  const encontrada = POR_RUTA.find((m) => m.ruta.test(pathname));
  return {
    titulo: encontrada?.titulo ? `${encontrada.titulo} | ${MARCA}` : MARCA,
    descripcion: encontrada?.descripcion ?? DESCRIPCION_GENERAL,
  };
}

function ponerMeta(atributo: 'name' | 'property', clave: string, valor: string) {
  let meta = document.head.querySelector<HTMLMetaElement>(`meta[${atributo}="${clave}"]`);
  if (!meta) {
    meta = document.createElement('meta');
    meta.setAttribute(atributo, clave);
    document.head.appendChild(meta);
  }
  meta.content = valor;
}

/** Aplica título, descripción, Open Graph y URL canónica de la ruta actual. */
export function aplicarMetadatos(pathname: string) {
  const { titulo, descripcion } = metadatosDeRuta(pathname);
  const url = `${SITIO_URL}${pathname}`;
  document.title = titulo;
  ponerMeta('name', 'description', descripcion);
  ponerMeta('property', 'og:title', titulo);
  ponerMeta('property', 'og:description', descripcion);
  ponerMeta('property', 'og:url', url);
  let canonica = document.head.querySelector<HTMLLinkElement>('link[rel="canonical"]');
  if (!canonica) {
    canonica = document.createElement('link');
    canonica.rel = 'canonical';
    document.head.appendChild(canonica);
  }
  canonica.href = url;
}
