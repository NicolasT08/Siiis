import type { ReactNode } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';
import type { Rol } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Cargando } from './ui';

/**
 * Ruta protegida: sin sesión envía a /login (y vuelve aquí al entrar); con un rol que no corresponde muestra un
 * aviso. El backend valida el rol de todas formas; esto solo evita pantallas que no se pueden usar.
 */
export default function RequireAuth({ roles, children }: { roles?: readonly Rol[]; children: ReactNode }) {
  const { usuario, cargando } = useAuth();
  const location = useLocation();

  if (cargando) return <Cargando texto="Comprobando tu sesión…" />;
  if (!usuario) return <Navigate to="/login" replace state={{ from: `${location.pathname}${location.search}` }} />;
  if (roles && !roles.includes(usuario.rol)) {
    return (
      <section className="container app-page">
        <div className="app-empty" role="alert">
          <ShieldAlert size={32} aria-hidden="true" />
          <h1>No tienes acceso a esta sección</h1>
          <p>Tu rol no permite usar esta pantalla. Si crees que es un error, habla con un administrador.</p>
          <Link className="btn btn--navy" to="/">
            Volver al inicio
          </Link>
        </div>
      </section>
    );
  }
  return <>{children}</>;
}
