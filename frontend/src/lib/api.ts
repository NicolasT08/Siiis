// Cliente de la API SIIIS. Contrato: backend/docs/api.md.

export const API_URL = (import.meta.env.VITE_API_URL ?? '/api/v1').replace(/\/+$/, '');

export type Usuario = {
  id: string;
  nombre: string;
  apellido: string;
  rol: string;
  foto_perfil: { url: string } | null;
};

export type LoginResponse = {
  token: string;
  expira_en: number;
  usuario: Usuario;
};

export type MultimediaItem = {
  id: string;
  tipo: string;
  url: string;
  descripcion: string | null;
  orden: number | null;
};

/**
 * Proyecto del semillero. PENDIENTE DE BACKEND: el contrato (docs/api.md) deja "documentos" para
 * fases siguientes; esta forma es la que espera el frontend en `GET /proyectos`. Ajustar aquí si
 * el backend define otros nombres de campos.
 */
export type Proyecto = {
  id: string;
  titulo: string;
  resumen: string;
  categoria: string;
  /** Valores conocidos: aprobado, pendiente, en_desarrollo, rechazado. Otros se muestran tal cual. */
  estado: string;
  autor: { nombre: string; foto_url: string | null };
  /** Fecha ISO 8601. */
  fecha_publicacion: string;
  imagen: { url: string } | null;
  /** Lo marca un administrador; el frontend no elige destacados por su cuenta. */
  destacado?: boolean;
};

/**
 * Integrante del semillero para la página pública de Usuarios. PENDIENTE DE BACKEND: no existe
 * aún `GET /usuarios`; estos campos salen de la colección Usuarios (ver scripts/crear_superadmin).
 * No incluye el correo: vive en Credenciales y no conviene publicarlo sin permiso.
 */
export type Miembro = {
  id: string;
  nombre: string;
  apellido: string;
  rol: string;
  programa_academico: string | null;
  semestre: number | null;
  biografia: string | null;
  foto_perfil: { url: string } | null;
  /** true = activo en el semillero. */
  estado: boolean;
};

/** Error con el formato {"error": {"code", "message", "fields"?}} del backend. */
export class ApiError extends Error {
  readonly code: string;
  readonly status: number;
  readonly fields: Record<string, string>;

  constructor(code: string, message: string, status: number, fields: Record<string, string> = {}) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.fields = fields;
  }
}

const NETWORK_MESSAGE = 'No se pudo conectar con el servidor. Intenta de nuevo.';
const UNEXPECTED_MESSAGE = 'Ocurrió un error inesperado. Intenta de nuevo.';

type RequestOptions = {
  method?: 'GET' | 'POST';
  body?: unknown;
  token?: string | null;
  signal?: AbortSignal;
};

async function request<T>(path: string, { method = 'GET', body, token, signal }: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = `Bearer ${token}`;

  let response: Response;
  try {
    response = await fetch(`${API_URL}${path}`, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal,
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error;
    throw new ApiError('NETWORK_ERROR', NETWORK_MESSAGE, 0);
  }

  if (response.status === 204) return undefined as T;

  const data: unknown = await response.json().catch(() => null);

  if (!response.ok) {
    const error = (data as { error?: { code?: string; message?: string; fields?: Record<string, string> } } | null)
      ?.error;
    throw new ApiError(
      error?.code ?? 'INTERNAL_ERROR',
      error?.message ?? UNEXPECTED_MESSAGE,
      response.status,
      error?.fields ?? {},
    );
  }

  return data as T;
}

export const api = {
  login: (correo: string, password: string) =>
    request<LoginResponse>('/auth/login', { method: 'POST', body: { correo, password } }),

  me: (token: string, signal?: AbortSignal) => request<{ usuario: Usuario }>('/auth/me', { token, signal }),

  logout: (token: string) => request<void>('/auth/logout', { method: 'POST', token }),

  forgotPassword: (correo: string) =>
    request<{ mensaje: string }>('/auth/forgot-password', { method: 'POST', body: { correo } }),

  slider: (signal?: AbortSignal) => request<{ data: MultimediaItem[] }>('/home/slider', { signal }),

  proyectos: (signal?: AbortSignal) =>
    request<{ data: Proyecto[] }>('/proyectos', { signal, token: tokenStorage.get() }),

  usuarios: (signal?: AbortSignal) =>
    request<{ data: Miembro[] }>('/usuarios', { signal, token: tokenStorage.get() }),
};

/** Texto para mostrar en un formulario: el `message` y, si hay, los mensajes por campo. */
export function errorText(error: unknown): string {
  if (!(error instanceof ApiError)) return UNEXPECTED_MESSAGE;
  const fields = Object.values(error.fields);
  return fields.length ? `${error.message} ${fields.join(' ')}` : error.message;
}

// Token: localStorage con "Recordarme", sessionStorage sin él (api.md, POST /auth/login).
const TOKEN_KEY = 'siiis_token';

export const tokenStorage = {
  get(): string | null {
    try {
      return localStorage.getItem(TOKEN_KEY) ?? sessionStorage.getItem(TOKEN_KEY);
    } catch {
      return null;
    }
  },
  save(token: string, remember: boolean) {
    tokenStorage.clear();
    try {
      (remember ? localStorage : sessionStorage).setItem(TOKEN_KEY, token);
    } catch {
      // Almacenamiento no disponible (modo privado): la sesión dura hasta recargar.
    }
  },
  clear() {
    try {
      localStorage.removeItem(TOKEN_KEY);
      sessionStorage.removeItem(TOKEN_KEY);
    } catch {
      // Nada que limpiar.
    }
  },
};
