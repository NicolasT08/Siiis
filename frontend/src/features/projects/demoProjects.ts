import type { Proyecto } from '../../lib/api';

/**
 * DATOS DE DEMOSTRACIÓN — solo para ver el diseño en desarrollo (VITE_PROJECTS_DEMO=true).
 * No son registros reales y nunca se cargan en producción (ver useProjects.ts).
 * Las imágenes son recortes de baja resolución del mockup: sustituir por las oficiales.
 */
export const DEMO_PROYECTOS: Proyecto[] = [
  {
    id: 'demo-1',
    titulo: 'Sistema de información para la gestión de archivos de la Academia Boyacense de Historia',
    resumen:
      'Implementa un sistema con IA y aprendizaje profundo para la preservación y accesibilidad del patrimonio histórico y cultural de Boyacá.',
    categoria: 'Inteligencia Artificial',
    estado: 'aprobado',
    autor: { nombre: 'Equipo SIIIS', foto_url: null },
    fecha_publicacion: '2025-06-09',
    imagen: { url: '/assets/proyectos/proyecto-academia-historia.png' },
    destacado: true,
  },
  {
    id: 'demo-2',
    titulo: 'Modelo computacional del comportamiento político',
    resumen:
      'Análisis de grafos sociales para la ideología en la polarización política mediante técnicas de scraping.',
    categoria: 'Software',
    estado: 'pendiente',
    autor: { nombre: 'Investigador de ejemplo', foto_url: null },
    fecha_publicacion: '2025-10-15',
    imagen: { url: '/assets/proyectos/proyecto-grafos-politicos.png' },
  },
  {
    id: 'demo-3',
    titulo: 'Mapa interactivo del patrimonio cultural de Boyacá',
    resumen:
      'Plataforma georreferenciada para la visualización y análisis del patrimonio histórico y cultural del departamento.',
    categoria: 'Análisis Social',
    estado: 'en_desarrollo',
    autor: { nombre: 'Equipo SIIIS', foto_url: null },
    fecha_publicacion: '2025-08-20',
    imagen: { url: '/assets/proyectos/proyecto-patrimonio-boyaca.png' },
  },
  {
    id: 'demo-4',
    titulo: 'Clasificador de documentos académicos con procesamiento de lenguaje natural',
    resumen: 'Proyecto de ejemplo sin imagen, para comprobar el diseño de respaldo de las tarjetas.',
    categoria: 'Inteligencia Artificial',
    estado: 'aprobado',
    autor: { nombre: 'Investigadora de ejemplo', foto_url: null },
    fecha_publicacion: '2024-11-02',
    imagen: null,
  },
  {
    id: 'demo-5',
    titulo: 'Plataforma de seguimiento a semilleros de investigación',
    resumen: 'Proyecto de ejemplo para comprobar filtros por año, categoría y estado.',
    categoria: 'Software',
    estado: 'rechazado',
    autor: { nombre: 'Equipo SIIIS', foto_url: null },
    fecha_publicacion: '2024-03-18',
    imagen: null,
  },
];
