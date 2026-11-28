/**
 * Datos del sitio en un solo lugar. Para cambiar las redes sociales, basta con poner la URL oficial en `url`:
 * las que queden vacías se muestran como icono sin enlace.
 */
export const SITIO = {
  nombre: 'SIIIS',
  universidad: 'Universidad Pedagógica y Tecnológica de Colombia (UPTC)',
  direccion: 'Avenida Central del Norte 39-115, 150003 Tunja, Boyacá, Colombia',
  telefono: { texto: '+57 313 842 6821', href: 'tel:+573138426821' },
  /** Correo para consultas y derechos sobre datos personales (/privacidad). */
  correoContacto: 'semillerosiiisup@gmail.com',
  /** Dominio que exige la inscripción (INSCRIPCION_DOMINIOS_CORREO del backend). */
  dominioInscripcion: 'uptc.edu.co',
};

export type RedSocial = 'facebook' | 'x' | 'instagram' | 'linkedin' | 'youtube';

// TODO: completar con los perfiles oficiales cuando el semillero los confirme.
export const REDES_SOCIALES: { red: RedSocial; nombre: string; url: string }[] = [
  { red: 'facebook', nombre: 'Facebook', url: '' },
  { red: 'x', nombre: 'X', url: '' },
  { red: 'instagram', nombre: 'Instagram', url: '' },
  { red: 'linkedin', nombre: 'LinkedIn', url: '' },
  { red: 'youtube', nombre: 'YouTube', url: '' },
];

/** Texto legal aprobado (docs/api.md 13a). No cambiarlo sin aprobación del equipo. */
export const TEXTO_AUTORIZACION =
  'Autorizo al Semillero de Investigación SIIIS de la Universidad Pedagógica y Tecnológica de Colombia (UPTC) para recolectar, almacenar y usar los datos personales y documentos que envío en este formulario, con la única finalidad de evaluar mi solicitud de ingreso al semillero y comunicarme su resultado, conforme a la Ley 1581 de 2012. Sé que puedo consultar, actualizar o pedir la eliminación de mis datos escribiendo al correo de contacto del semillero.';

/** Áreas de interés: la lista blanca del backend (DEC-B63). */
export const AREAS_INTERES = [
  { value: 'desarrollo_software', label: 'Desarrollo de software' },
  { value: 'transformacion_digital', label: 'Transformación digital' },
  { value: 'tic', label: 'Tecnologías de información y comunicación (TIC)' },
  { value: 'innovacion', label: 'Innovación' },
  { value: 'inteligencia_artificial', label: 'Inteligencia artificial' },
  { value: 'ciencia_datos', label: 'Ciencia de datos' },
];
