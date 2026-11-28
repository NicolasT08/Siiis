import { createContext, useContext } from 'react';
import type { Rol, UsuarioSesion } from './api';

export type AuthContextValue = {
  usuario: UsuarioSesion | null;
  /** true mientras se restaura la sesión guardada (GET /auth/me) al cargar la página. */
  cargando: boolean;
  /** true si la última sesión terminó por un 401 (vencida, cerrada o usuario desactivado). */
  sesionExpirada: boolean;
  login: (correo: string, password: string, remember: boolean) => Promise<UsuarioSesion>;
  /** Mismo flujo de sesión que `login`, con el `credential` de Google Identity Services (RF-17). */
  loginConGoogle: (idToken: string, remember: boolean) => Promise<UsuarioSesion>;
  logout: () => Promise<void>;
  /** Vuelve a leer /auth/me (p. ej. tras cambiar el nombre o la foto). */
  refrescar: () => Promise<void>;
  /** Aplica un cambio ya confirmado por la API al usuario de la sesión, sin esperar a /auth/me (p. ej. quitar la foto). */
  actualizarSesion: (cambios: Partial<UsuarioSesion>) => void;
};

export const AuthContext = createContext<AuthContextValue | null>(null);

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useAuth debe usarse dentro de <AuthProvider>');
  return context;
}

export function nombreCompleto(usuario: { nombre: string; apellido: string }): string {
  return `${usuario.nombre} ${usuario.apellido}`.trim();
}

// Roles y permisos (docs/api.md "Roles y permisos", DEC-B16, B17, B33, B44, B46, B52). La interfaz solo
// muestra u oculta; el backend vuelve a validar siempre.

/** Crean y gestionan usuarios, revisan inscripciones y leen el buzón de contacto. */
export const ROLES_ADMINISTRATIVOS: readonly Rol[] = ['superadministrador', 'administrador'];
/** Revisan y publican contenido y moderan reseñas. */
export const ROLES_REVISORES: readonly Rol[] = ['superadministrador', 'administrador', 'docente_director'];

export const esAdministrativo = (rol: Rol | undefined): boolean => !!rol && ROLES_ADMINISTRATIVOS.includes(rol);
export const esRevisor = (rol: Rol | undefined): boolean => !!rol && ROLES_REVISORES.includes(rol);

/** Roles que un actor puede asignar al crear o editar usuarios (DEC-B17). */
export function rolesAsignables(rol: Rol | undefined): Rol[] {
  if (rol === 'superadministrador') return ['superadministrador', 'administrador', 'docente_director', 'estudiante_lider', 'estudiante'];
  if (rol === 'administrador') return ['docente_director', 'estudiante_lider', 'estudiante'];
  return [];
}

/** ¿Puede el actor gestionar a un usuario con ese rol? */
export function puedeGestionar(actor: Rol | undefined, objetivo: Rol): boolean {
  return rolesAsignables(actor).includes(objetivo);
}
