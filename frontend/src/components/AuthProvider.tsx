import { useCallback, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { api, ApiError, tokenStorage } from '../lib/api';
import type { Usuario } from '../lib/api';
import { AuthContext } from '../lib/auth';

export default function AuthProvider({ children }: { children: ReactNode }) {
  const [usuario, setUsuario] = useState<Usuario | null>(null);

  // Restaura la sesión al recargar (GET /auth/me). Con 401 el token ya no sirve y se borra.
  useEffect(() => {
    const token = tokenStorage.get();
    if (!token) return;

    const controller = new AbortController();
    api
      .me(token, controller.signal)
      .then(({ usuario: actual }) => setUsuario(actual))
      .catch((error: unknown) => {
        if (error instanceof ApiError && error.status === 401) tokenStorage.clear();
      });
    return () => controller.abort();
  }, []);

  const login = useCallback(async (correo: string, password: string, remember: boolean) => {
    const resultado = await api.login(correo, password);
    tokenStorage.save(resultado.token, remember);
    setUsuario(resultado.usuario);
    return resultado.usuario;
  }, []);

  const logout = useCallback(async () => {
    const token = tokenStorage.get();
    tokenStorage.clear();
    setUsuario(null);
    if (token) await api.logout(token).catch(() => undefined);
  }, []);

  const value = useMemo(() => ({ usuario, login, logout }), [usuario, login, logout]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
