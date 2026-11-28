// Cliente único de la API SIIIS. Contrato: docs/api.md (rutas en inglés, campos exactos).

import { calcularVencimiento, leerVencimiento } from './sesion';

/** VITE_API_URL si se define; si no, el backend local en desarrollo y la ruta relativa en producción. */
const API_POR_DEFECTO = import.meta.env.DEV ? 'http://localhost:8000/api/v1' : '/api/v1';
export const API_URL = (import.meta.env.VITE_API_URL || API_POR_DEFECTO).replace(/\/+$/, '');

// ---------------------------------------------------------------------------------------------------
// Tipos del contrato
// ---------------------------------------------------------------------------------------------------

export const ROLES = ['superadministrador', 'administrador', 'docente_director', 'estudiante_lider', 'estudiante'] as const;
export type Rol = (typeof ROLES)[number];

export type Foto = { url: string } | null;

/** `usuario` de POST /auth/login y GET /auth/me. */
export type UsuarioSesion = {
  id: string;
  nombre: string;
  apellido: string;
  rol: Rol;
  foto_perfil: Foto;
};

/** Director del semillero (GET /home/director, 8b): nunca trae el correo. */
export type DirectorPublico = { id: string; nombre: string; apellido: string; foto_perfil: Foto };

export type LoginResponse = { token: string; expira_en: number; usuario: UsuarioSesion };

/** Vista pública de un integrante (6c). Nunca trae correo, teléfono ni estado. */
export type UsuarioPublico = {
  id: string;
  nombre: string;
  apellido: string;
  rol: Rol;
  programa_academico: string | null;
  semestre: number | null;
  foto_perfil: Foto;
  biografia: string | null;
};

/** Vista de administrador (6b): la reciben los administradores y el propio usuario. */
export type UsuarioAdmin = UsuarioPublico & {
  correo: string;
  telefono: string | null;
  estado: boolean;
  estado_cuenta: string;
  fecha_ingreso: string | null;
  fecha_salida: string | null;
  ultimo_acceso: string | null;
};

export type Meta = { page: number; per_page: number; total: number; total_pages: number };
export type Paginado<T> = { data: T[]; meta: Meta };

export type MultimediaItem = {
  id: string;
  tipo: string;
  url: string;
  descripcion: string | null;
  orden: number | null;
};

/** Secciones del inicio que se administran desde el panel (8a, DEC-B62). */
export type SeccionInicio = 'slider_principal' | 'video_presentacion';

/** Vista de administración de un elemento de Multimedia (nunca trae public_id). */
export type MultimediaAdmin = MultimediaItem & { seccion: SeccionInicio; fecha_subida: string | null };

export type Modulo = 'projects' | 'articles';
export type CategoriaDocumento = 'proyecto' | 'articulo' | 'noticia';
export type EstadoProyecto = 'en_revision' | 'ejecucion' | 'finalizado';
export type EstadoArticulo = 'en_revision' | 'publicado';
export type EstadoDocumento = EstadoProyecto | EstadoArticulo;

export const ESTADOS_POR_MODULO: Record<Modulo, readonly EstadoDocumento[]> = {
  projects: ['en_revision', 'ejecucion', 'finalizado'],
  articles: ['en_revision', 'publicado'],
};

export type PersonaDocumento = {
  id: string;
  nombre: string;
  apellido: string;
  rol: Rol;
  foto_perfil: Foto;
};

export type DocumentoResumen = {
  id: string;
  categoria: CategoriaDocumento;
  titulo: string;
  descripcion: string;
  estado: EstadoDocumento;
  visible: boolean;
  autor: PersonaDocumento | null;
  integrantes: PersonaDocumento[];
  fecha_creacion: string;
  fecha_actualizacion: string;
};

export type Adjunto = { archivo: string; url: string; nombre_archivo: string };
export type Permisos = { editar: boolean; borrar: boolean; publicar: boolean };

export type Documento = DocumentoResumen & {
  contenido: string | null;
  archivos_adjuntos: Adjunto[];
  permisos: Permisos;
};

export type DocumentoEntrada = {
  categoria?: CategoriaDocumento;
  titulo?: string;
  descripcion?: string;
  contenido?: string | null;
  integrantes?: string[];
  estado?: EstadoDocumento;
  visible?: boolean;
};

export type EstadoResena = 'visible' | 'oculta';
export type Resena = {
  id: string;
  usuario_id: string | null;
  nombre_visible: string;
  comentario: string;
  calificacion: number | null;
  estado: EstadoResena;
  fecha: string;
};
export type ResenasRespuesta = Paginado<Resena> & { resumen: { promedio: number | null; calificaciones: number } };

export type EstadoSolicitud = 'pendiente' | 'aceptada' | 'rechazada';
export type InscripcionResumen = {
  id: string;
  nombre_completo: string;
  correo: string;
  telefono: string | null;
  programa_academico: string;
  semestre: number;
  /** Opcionales nuevos (DEC-B63); null / [] en solicitudes anteriores. */
  codigo_estudiantil: string | null;
  areas_interes: string[];
  estado_solicitud: EstadoSolicitud;
  fecha_solicitud: string;
};
export type DocumentoInscripcion = { nombre_archivo: string; formato?: string; url?: string; expira?: string };
export type InscripcionDetalle = InscripcionResumen & {
  carta_motivacion?: string;
  documentos_adjuntos: DocumentoInscripcion[];
};
export type InscripcionEntrada = {
  nombre_completo: string;
  correo: string;
  codigo_estudiantil: string;
  programa_academico: string;
  semestre: number;
  areas_interes: string[];
  carta_motivacion: string;
  /** Obligatoria, no se guarda (DEC-B63). */
  disponibilidad: boolean;
  /** De 0 a 3 documentos de apoyo (opcionales, DECISION-005 corregida). */
  documentos: File[];
};

export type EstadoMensaje = 'leido' | 'no_leido';
export type MensajeContacto = {
  id: string;
  nombre: string;
  correo: string;
  asunto: string;
  mensaje: string;
  estado: EstadoMensaje;
  fecha: string;
};

export type PerfilEntrada = {
  nombre?: string;
  apellido?: string;
  programa_academico?: string | null;
  semestre?: number | null;
  biografia?: string | null;
  telefono?: string | null;
  rol?: Rol;
  estado?: boolean;
};

export type RegistroEntrada = {
  correo: string;
  password: string;
  rol: Rol;
  nombre: string;
  apellido: string;
  programa_academico?: string | null;
  semestre?: number | null;
  biografia?: string | null;
  telefono?: string | null;
};

// ---------------------------------------------------------------------------------------------------
// Errores
// ---------------------------------------------------------------------------------------------------

/** Error con el formato {"error": {"code", "message", "fields"?}} del backend. */
export class ApiError extends Error {
  readonly code: string;
  readonly status: number;
  readonly fields: Record<string, string>;
  /** Segundos de `Retry-After` en un 429. */
  readonly retryAfter: number | null;

  constructor(code: string, message: string, status: number, fields: Record<string, string> = {}, retryAfter: number | null = null) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.fields = fields;
    this.retryAfter = retryAfter;
  }
}

const NETWORK_MESSAGE = 'No se pudo conectar con el servidor. Revisa tu conexión e intenta de nuevo.';

// ---------------------------------------------------------------------------------------------------
// Token y sesión vencida
// ---------------------------------------------------------------------------------------------------

// Token: localStorage con "Recordarme", sessionStorage sin él (api.md, POST /auth/login). Junto al token se guarda
// el instante en que vence (RNF-05, `expira_en`), en el mismo almacenamiento.
const TOKEN_KEY = 'siiis_token';
const VENCE_KEY = 'siiis_token_vence';

/** Copia en memoria por si el almacenamiento no está disponible (modo privado): dura hasta recargar. */
let venceEnMemoria: number | null = null;

export const tokenStorage = {
  get(): string | null {
    try {
      return localStorage.getItem(TOKEN_KEY) ?? sessionStorage.getItem(TOKEN_KEY);
    } catch {
      return null;
    }
  },
  /** Instante (ms desde epoch) en que vence el token guardado; null si no se conoce. */
  vencimiento(): number | null {
    try {
      const guardado = localStorage.getItem(TOKEN_KEY) !== null ? localStorage.getItem(VENCE_KEY) : sessionStorage.getItem(VENCE_KEY);
      return leerVencimiento(guardado) ?? venceEnMemoria;
    } catch {
      return venceEnMemoria;
    }
  },
  /** `expiraEn`: segundos de validez que devolvió el login. */
  save(token: string, remember: boolean, expiraEn: number) {
    tokenStorage.clear();
    const vence = calcularVencimiento(expiraEn, Date.now());
    venceEnMemoria = vence;
    try {
      const almacen = remember ? localStorage : sessionStorage;
      almacen.setItem(TOKEN_KEY, token);
      almacen.setItem(VENCE_KEY, String(vence));
    } catch {
      // Almacenamiento no disponible (modo privado): la sesión dura hasta recargar.
    }
  },
  clear() {
    venceEnMemoria = null;
    try {
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem(VENCE_KEY);
      sessionStorage.removeItem(TOKEN_KEY);
      sessionStorage.removeItem(VENCE_KEY);
    } catch {
      // Nada que limpiar.
    }
  },
};

/** Lo registra AuthProvider: se llama cuando una petición CON token recibe 401 (sesión vencida o cerrada). */
let onUnauthorized: (() => void) | null = null;
export function setUnauthorizedHandler(handler: (() => void) | null) {
  onUnauthorized = handler;
}

// ---------------------------------------------------------------------------------------------------
// Petición base
// ---------------------------------------------------------------------------------------------------

type Query = Record<string, string | number | boolean | null | undefined>;

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  /** Objeto → JSON; FormData → multipart (el navegador arma el boundary). */
  body?: unknown;
  query?: Query;
  signal?: AbortSignal;
  /** false para no enviar el token aunque exista (login). */
  auth?: boolean;
};

function queryString(query: Query | undefined): string {
  if (!query) return '';
  const params = new URLSearchParams();
  for (const [clave, valor] of Object.entries(query)) {
    if (valor === undefined || valor === null || valor === '') continue;
    params.set(clave, String(valor));
  }
  const texto = params.toString();
  return texto ? `?${texto}` : '';
}

async function request<T>(path: string, { method = 'GET', body, query, signal, auth = true }: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  const esFormulario = body instanceof FormData;
  if (body !== undefined && !esFormulario) headers['Content-Type'] = 'application/json';
  const token = auth ? tokenStorage.get() : null;
  if (token) headers.Authorization = `Bearer ${token}`;

  let response: Response;
  try {
    response = await fetch(`${API_URL}${path}${queryString(query)}`, {
      method,
      headers,
      body: body === undefined ? undefined : esFormulario ? (body as FormData) : JSON.stringify(body),
      signal,
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error;
    throw new ApiError('NETWORK_ERROR', NETWORK_MESSAGE, 0);
  }

  if (response.status === 204) return undefined as T;

  const data: unknown = await response.json().catch(() => null);

  if (!response.ok) {
    const error = (data as { error?: { code?: string; message?: string; fields?: Record<string, string> } } | null)?.error;
    const retry = Number(response.headers.get('Retry-After'));
    const apiError = new ApiError(
      error?.code ?? (response.status >= 500 ? 'INTERNAL_ERROR' : 'HTTP_ERROR'),
      error?.message ?? '',
      response.status,
      error?.fields ?? {},
      Number.isFinite(retry) && retry > 0 ? retry : null,
    );
    if (response.status === 401 && token) onUnauthorized?.();
    throw apiError;
  }

  return data as T;
}

// ---------------------------------------------------------------------------------------------------
// Rutas (docs/api.md)
// ---------------------------------------------------------------------------------------------------

function formulario(campos: Record<string, string | Blob | (string | Blob)[]>): FormData {
  const form = new FormData();
  for (const [clave, valor] of Object.entries(campos)) {
    if (Array.isArray(valor)) valor.forEach((v) => form.append(clave, v));
    else form.append(clave, valor);
  }
  return form;
}

const enc = encodeURIComponent;

export const api = {
  // 2–6a. Autenticación
  login: (correo: string, password: string) =>
    request<LoginResponse>('/auth/login', { method: 'POST', body: { correo, password }, auth: false }),
  /** Login con Google (2a): `id_token` es `credential` de Google Identity Services. */
  loginGoogle: (id_token: string) =>
    request<LoginResponse>('/auth/google', { method: 'POST', body: { id_token }, auth: false }),
  me: (signal?: AbortSignal) => request<{ usuario: UsuarioSesion }>('/auth/me', { signal }),
  logout: () => request<void>('/auth/logout', { method: 'POST' }),
  forgotPassword: (correo: string) =>
    request<{ mensaje: string }>('/auth/forgot-password', { method: 'POST', body: { correo }, auth: false }),
  resetPassword: (token: string, password: string) =>
    request<{ mensaje: string }>('/auth/reset-password', { method: 'POST', body: { token, password }, auth: false }),
  changePassword: (password_actual: string, password_nueva: string) =>
    request<{ mensaje: string }>('/auth/change-password', { method: 'POST', body: { password_actual, password_nueva } }),
  register: (datos: RegistroEntrada) =>
    request<{ usuario: UsuarioAdmin }>('/auth/register', { method: 'POST', body: datos }),

  // 6c–6g. Usuarios
  users: (query: { page?: number; per_page?: number; rol?: Rol | ''; estado?: boolean | '' } = {}, signal?: AbortSignal) =>
    request<Paginado<UsuarioPublico | UsuarioAdmin>>('/users', { query, signal }),
  user: (id: string, signal?: AbortSignal) =>
    request<{ usuario: UsuarioPublico | UsuarioAdmin }>(`/users/${enc(id)}`, { signal }),
  /**
   * Sección pública "Integrantes": sin token, para recibir siempre la vista pública (solo activos, DEC-B20 y
   * DEC-B72) aunque quien mira tenga sesión de administrador.
   */
  members: (query: { page?: number; per_page?: number; rol?: Rol | '' } = {}, signal?: AbortSignal) =>
    request<Paginado<UsuarioPublico>>('/users', { query, signal, auth: false }),
  member: (id: string, signal?: AbortSignal) =>
    request<{ usuario: UsuarioPublico }>(`/users/${enc(id)}`, { signal, auth: false }),
  updateUser: (id: string, datos: PerfilEntrada) =>
    request<{ usuario: UsuarioAdmin }>(`/users/${enc(id)}`, { method: 'PATCH', body: datos }),
  deactivateUser: (id: string) => request<{ usuario: UsuarioAdmin }>(`/users/${enc(id)}`, { method: 'DELETE' }),
  uploadPhoto: (id: string, foto: File) =>
    request<{ usuario: UsuarioAdmin }>(`/users/${enc(id)}/photo`, { method: 'POST', body: formulario({ foto }) }),

  // 7–8. Inicio
  slider: (signal?: AbortSignal) => request<{ data: MultimediaItem[] }>('/home/slider', { signal }),
  videos: (signal?: AbortSignal) => request<{ data: MultimediaItem[] }>('/home/videos', { signal }),
  /** 8b. Director del semillero (DEC-B67); 404 si no está configurado. */
  director: (signal?: AbortSignal) => request<{ director: DirectorPublico }>('/home/director', { signal }),

  // 8a. Administración de fotos y videos del inicio (superadministrador y administrador)
  multimedia: (seccion: SeccionInicio, signal?: AbortSignal) =>
    request<{ data: MultimediaAdmin[] }>('/multimedia', { query: { seccion }, signal }),
  /** `reemplaza` (DEC-B69): id del elemento que el backend borra solo si la subida sale bien. */
  uploadMultimedia: (datos: { seccion: SeccionInicio; descripcion: string; orden?: number | null; archivo: File; reemplaza?: string }) =>
    request<{ multimedia: MultimediaAdmin }>('/multimedia', {
      method: 'POST',
      body: formulario({
        seccion: datos.seccion,
        descripcion: datos.descripcion,
        ...(datos.orden ? { orden: String(datos.orden) } : {}),
        ...(datos.reemplaza ? { reemplaza: datos.reemplaza } : {}),
        archivo: datos.archivo,
      }),
    }),
  updateMultimedia: (id: string, cambios: { descripcion?: string; orden?: number }) =>
    request<{ multimedia: MultimediaAdmin }>(`/multimedia/${enc(id)}`, { method: 'PATCH', body: cambios }),
  reorderMultimedia: (seccion: SeccionInicio, ids: string[]) =>
    request<{ data: MultimediaAdmin[] }>('/multimedia/orden', { method: 'PUT', body: { seccion, ids } }),
  deleteMultimedia: (id: string) => request<void>(`/multimedia/${enc(id)}`, { method: 'DELETE' }),

  // 9–11. Proyectos, artículos y noticias
  documents: (
    modulo: Modulo,
    query: {
      page?: number;
      per_page?: number;
      estado?: EstadoDocumento | '';
      categoria?: 'articulo' | 'noticia' | '';
      autor?: string;
      integrante?: string;
      visible?: boolean | '';
    } = {},
    signal?: AbortSignal,
  ) => request<Paginado<DocumentoResumen>>(`/${modulo}`, { query, signal }),
  document: (modulo: Modulo, id: string, signal?: AbortSignal) =>
    request<{ documento: Documento }>(`/${modulo}/${enc(id)}`, { signal }),
  createDocument: (modulo: Modulo, datos: DocumentoEntrada) =>
    request<{ documento: Documento }>(`/${modulo}`, { method: 'POST', body: datos }),
  updateDocument: (modulo: Modulo, id: string, datos: DocumentoEntrada) =>
    request<{ documento: Documento }>(`/${modulo}/${enc(id)}`, { method: 'PATCH', body: datos }),
  deleteDocument: (modulo: Modulo, id: string) => request<void>(`/${modulo}/${enc(id)}`, { method: 'DELETE' }),
  uploadAttachment: (modulo: Modulo, id: string, archivo: File) =>
    request<{ documento: Documento }>(`/${modulo}/${enc(id)}/files`, { method: 'POST', body: formulario({ archivo }) }),
  deleteAttachment: (modulo: Modulo, id: string, archivo: string) =>
    request<{ documento: Documento }>(`/${modulo}/${enc(id)}/files/${enc(archivo)}`, { method: 'DELETE' }),

  // 12. Reseñas
  reviews: (query: { page?: number; per_page?: number; estado?: EstadoResena | '' } = {}, signal?: AbortSignal) =>
    request<ResenasRespuesta>('/reviews', { query, signal }),
  createReview: (datos: { nombre_visible?: string; comentario: string; calificacion?: number | null }) =>
    request<{ resena: Resena }>('/reviews', { method: 'POST', body: datos }),
  updateReview: (id: string, estado: EstadoResena) =>
    request<{ resena: Resena }>(`/reviews/${enc(id)}`, { method: 'PATCH', body: { estado } }),
  deleteReview: (id: string) => request<void>(`/reviews/${enc(id)}`, { method: 'DELETE' }),

  // 13. Inscripciones
  createInscription: (datos: InscripcionEntrada) =>
    request<{ inscripcion: InscripcionDetalle; mensaje: string }>('/inscriptions', {
      method: 'POST',
      auth: false,
      body: formulario({
        nombre_completo: datos.nombre_completo,
        correo: datos.correo,
        ...(datos.codigo_estudiantil ? { codigo_estudiantil: datos.codigo_estudiantil } : {}),
        programa_academico: datos.programa_academico,
        semestre: String(datos.semestre),
        carta_motivacion: datos.carta_motivacion,
        disponibilidad: datos.disponibilidad ? 'true' : 'false',
        ...(datos.areas_interes.length ? { 'areas_interes[]': datos.areas_interes } : {}),
        ...(datos.documentos.length ? { 'documentos[]': datos.documentos } : {}),
      }),
    }),
  inscriptions: (query: { page?: number; per_page?: number; estado_solicitud?: EstadoSolicitud | '' } = {}, signal?: AbortSignal) =>
    request<Paginado<InscripcionResumen>>('/inscriptions', { query, signal }),
  inscription: (id: string, signal?: AbortSignal) =>
    request<{ inscripcion: InscripcionDetalle }>(`/inscriptions/${enc(id)}`, { signal }),
  resolveInscription: (id: string, estado_solicitud: 'aceptada' | 'rechazada') =>
    request<{ inscripcion: InscripcionDetalle }>(`/inscriptions/${enc(id)}`, { method: 'PATCH', body: { estado_solicitud } }),

  // 14. Contacto
  contact: (datos: { nombre: string; correo: string; asunto: string; mensaje: string }) =>
    request<{ mensaje: string }>('/contact', { method: 'POST', body: datos, auth: false }),
  messages: (query: { page?: number; per_page?: number; estado?: EstadoMensaje | '' } = {}, signal?: AbortSignal) =>
    request<Paginado<MensajeContacto>>('/contact/messages', { query, signal }),
  updateMessage: (id: string, estado: EstadoMensaje) =>
    request<{ mensaje_contacto: MensajeContacto }>(`/contact/messages/${enc(id)}`, { method: 'PATCH', body: { estado } }),
};

export function esVistaAdmin(usuario: UsuarioPublico | UsuarioAdmin): usuario is UsuarioAdmin {
  return 'correo' in usuario;
}
