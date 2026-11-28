import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, ApiError, setUnauthorizedHandler, tokenStorage } from '../lib/api';
import type { UsuarioSesion } from '../lib/api';
import { AuthContext } from '../lib/auth';
import { msHastaVencer, sesionVencida } from '../lib/sesion';

/** Sesión guardada que todavía sirve (con token y sin vencer). */
const haySesionVigente = () => tokenStorage.get() !== null && !sesionVencida(tokenStorage.vencimiento(), Date.now());

export default function AuthProvider({ children }: { children: ReactNode }) {
  const navigate = useNavigate();
  const [usuario, setUsuario] = useState<UsuarioSesion | null>(null);
  const [cargando, setCargando] = useState(haySesionVigente);
  // Al abrir el sitio con una sesión guardada ya vencida, se cierra de una vez (sin esperar a la API) y el login
  // muestra el aviso.
  const [sesionExpirada, setSesionExpirada] = useState(() => {
    if (tokenStorage.get() === null || !sesionVencida(tokenStorage.vencimiento(), Date.now())) return false;
    tokenStorage.clear();
    return true;
  });
  // Mientras se restaura la sesión al abrir el sitio, un 401 no redirige: solo la cierra.
  const restaurando = useRef(cargando);

  /**
   * Cierra la sesión que terminó sola (8 h cumplidas o un 401 de la API, RNF-05). Con `redirigir`, lleva al login
   * con el aviso "Tu sesión expiró…" y, al volver a entrar, regresa a la página donde estaba.
   */
  const expirar = useCallback(
    (redirigir: boolean) => {
      tokenStorage.clear();
      setUsuario(null);
      setSesionExpirada(true);
      if (redirigir && window.location.pathname !== '/login') {
        navigate('/login', { replace: true, state: { from: `${window.location.pathname}${window.location.search}` } });
      }
    },
    [navigate],
  );

  // Cualquier 401 de una petición con token: la sesión ya no sirve (vencida, cerrada o usuario desactivado).
  useEffect(() => {
    setUnauthorizedHandler(() => expirar(!restaurando.current));
    return () => setUnauthorizedHandler(null);
  }, [expirar]);

  // Al abrir el sitio con una sesión vigente, se restaura (GET /auth/me).
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
        if (!controller.signal.aborted) {
          restaurando.current = false;
          setCargando(false);
        }
      });
    return () => controller.abort();
  }, []);

  // Cierre automático al cumplirse el vencimiento del token. Los temporizadores se congelan en pestañas
  // suspendidas o con el equipo dormido: al volver a la pestaña se comprueba otra vez.
  useEffect(() => {
    if (!usuario) return;
    const ms = msHastaVencer(tokenStorage.vencimiento(), Date.now());
    const timer = ms === null ? undefined : window.setTimeout(() => expirar(true), ms);
    const revisar = () => {
      if (document.visibilityState === 'visible' && sesionVencida(tokenStorage.vencimiento(), Date.now())) expirar(true);
    };
    document.addEventListener('visibilitychange', revisar);
    window.addEventListener('focus', revisar);
    return () => {
      window.clearTimeout(timer);
      document.removeEventListener('visibilitychange', revisar);
      window.removeEventListener('focus', revisar);
    };
  }, [usuario, expirar]);

  const login = useCallback(async (correo: string, password: string, remember: boolean) => {
    const resultado = await api.login(correo, password);
    tokenStorage.save(resultado.token, remember, resultado.expira_en);
    restaurando.current = false;
    setSesionExpirada(false);
    setUsuario(resultado.usuario);
    return resultado.usuario;
  }, []);

  const loginConGoogle = useCallback(async (idToken: string, remember: boolean) => {
    const resultado = await api.loginGoogle(idToken);
    tokenStorage.save(resultado.token, remember, resultado.expira_en);
    restaurando.current = false;
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

  const actualizarSesion = useCallback((cambios: Partial<UsuarioSesion>) => {
    setUsuario((actual) => (actual ? { ...actual, ...cambios } : actual));
  }, []);

  const value = useMemo(
    () => ({ usuario, cargando, sesionExpirada, login, loginConGoogle, logout, refrescar, actualizarSesion }),
    [usuario, cargando, sesionExpirada, login, loginConGoogle, logout, refrescar, actualizarSesion],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
