import { createContext, useContext } from 'react';
import type { Usuario } from './api';

export type AuthContextValue = {
  usuario: Usuario | null;
  login: (correo: string, password: string, remember: boolean) => Promise<Usuario>;
  logout: () => Promise<void>;
};

export const AuthContext = createContext<AuthContextValue | null>(null);

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useAuth debe usarse dentro de <AuthProvider>');
  return context;
}

export function nombreCompleto(usuario: Usuario): string {
  return `${usuario.nombre} ${usuario.apellido}`.trim();
}
