import type { Miembro } from '../../lib/api';

/**
 * DATOS DE DEMOSTRACIÓN — personas ficticias, solo para ver el diseño en desarrollo
 * (VITE_USERS_DEMO=true). Nunca se cargan en producción (ver useMembers.ts).
 * Fotos de ejemplo: randomuser.me (retratos de uso libre para maquetas).
 */
const base = { estado: true } as const;
const foto = (ruta: string) => ({ url: `https://randomuser.me/api/portraits/${ruta}.jpg` });

export const DEMO_MIEMBROS: Miembro[] = [
  {
    ...base,
    id: 'demo-u1',
    foto_perfil: foto('women/44'),
    nombre: 'Laura Camila',
    apellido: 'Rincón Ávila',
    rol: 'docente',
    programa_academico: 'Ingeniería de Sistemas y Computación',
    semestre: null,
    biografia: 'Coordina las líneas de inteligencia artificial y acompaña a los estudiantes en sus primeros artículos.',
  },
  {
    ...base,
    id: 'demo-u2',
    foto_perfil: foto('men/32'),
    nombre: 'Andrés Felipe',
    apellido: 'Moreno Castro',
    rol: 'estudiante',
    programa_academico: 'Ingeniería de Sistemas y Computación',
    semestre: 8,
    biografia: 'Trabaja en análisis de grafos sociales y visualización de datos abiertos de Boyacá.',
  },
  {
    ...base,
    id: 'demo-u3',
    foto_perfil: foto('women/68'),
    nombre: 'Valentina',
    apellido: 'Suárez Pinto',
    rol: 'estudiante',
    programa_academico: 'Ingeniería de Sistemas y Computación',
    semestre: 6,
    biografia: 'Desarrolla la plataforma web del semillero y le interesa la experiencia de usuario.',
  },
  {
    ...base,
    id: 'demo-u4',
    foto_perfil: foto('men/75'),
    nombre: 'Julián David',
    apellido: 'Herrera Mesa',
    rol: 'administrador',
    programa_academico: null,
    semestre: null,
    biografia: 'Gestiona las publicaciones, las inscripciones y la comunicación del semillero.',
  },
  {
    ...base,
    id: 'demo-u5',
    foto_perfil: foto('women/90'),
    nombre: 'Sofía',
    apellido: 'Patiño Vargas',
    rol: 'investigador',
    programa_academico: 'Maestría en Ingeniería',
    semestre: null,
    biografia: 'Investiga visión por computador aplicada a la preservación del patrimonio histórico.',
  },
  {
    ...base,
    id: 'demo-u6',
    foto_perfil: foto('men/11'),
    nombre: 'Mateo',
    apellido: 'Quintero Lozano',
    rol: 'estudiante',
    programa_academico: 'Ingeniería de Sistemas y Computación',
    semestre: 4,
    biografia: null,
    estado: false,
  },
];
