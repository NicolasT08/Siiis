import { useCallback, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { api, ApiError, setUnauthorizedHandler, tokenStorage } from '../lib/api';
import type { UsuarioSesion } from '../lib/api';
import { AuthContext } from '../lib/auth';

export default function AuthProvider({ children }: { children: ReactNode }) {
  const [usuario, setUsuario] = useState<UsuarioSesion | null>(null);
  const [cargando, setCargando] = useState(() => tokenStorage.get() !== null);
  const [sesionExpirada, setSesionExpirada] = useState(false);

  // Cualquier 401 de una petición con token: la sesión ya no sirve (vencida, cerrada o usuario desactivado).
  // Las rutas protegidas (RequireAuth) envían entonces al login.
  useEffect(() => {
    setUnauthorizedHandler(() => {
      tokenStorage.clear();
      setUsuario(null);
      setSesionExpirada(true);
    });
    return () => setUnauthorizedHandler(null);
  }, []);

  // Restaura la sesión al recargar (GET /auth/me).
  useEffect(() => {
    if (!tokenStorage.get()) return;
    const controller = new AbortController();
    api
      .me(controller.signal)
      .then(({ usuario: actual }) => setUsuario(actual))
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        // Un 401 ya lo maneja el handler global; ante otro error se deja la sesión sin restaurar.
        if (!(error instanceof ApiError && error.status === 401)) setUsuario(null);
      })
      .finally(() => {
        if (!controller.signal.aborted) setCargando(false);
      });
    return () => controller.abort();
  }, []);

  const login = useCallback(async (correo: string, password: string, remember: boolean) => {
    const resultado = await api.login(correo, password);
    tokenStorage.save(resultado.token, remember);
    setSesionExpirada(false);
    setUsuario(resultado.usuario);
    return resultado.usuario;
  }, []);

  const loginConGoogle = useCallback(async (idToken: string, remember: boolean) => {
    const resultado = await api.loginGoogle(idToken);
    tokenStorage.save(resultado.token, remember);
    setSesionExpirada(false);
    setUsuario(resultado.usuario);
    return resultado.usuario;
  }, []);

  const logout = useCallback(async () => {
    const habiaToken = tokenStorage.get() !== null;
    if (habiaToken) await api.logout().catch(() => undefined);
    tokenStorage.clear();
    setSesionExpirada(false);
    setUsuario(null);
  }, []);

  const refrescar = useCallback(async () => {
    if (!tokenStorage.get()) return;
    const { usuario: actual } = await api.me();
    setUsuario(actual);
  }, []);

  const value = useMemo(
    () => ({ usuario, cargando, sesionExpirada, login, loginConGoogle, logout, refrescar }),
    [usuario, cargando, sesionExpirada, login, loginConGoogle, logout, refrescar],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
