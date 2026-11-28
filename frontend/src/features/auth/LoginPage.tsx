import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import AuthModal from '../../components/AuthModal';
import { useAuth } from '../../lib/auth';
import { MENSAJE_SESION_EXPIRADA } from '../../lib/sesion';

/**
 * /login: el mismo formulario del menú, abierto sobre la página. Aquí llegan las rutas protegidas sin sesión
 * (y la sesión vencida, con un aviso); al entrar se vuelve a la página que se pedía.
 */
export default function LoginPage() {
  const { usuario, cargando, sesionExpirada } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const desde = (location.state as { from?: string } | null)?.from ?? '/';

  if (!cargando && usuario) return <Navigate to={desde} replace />;

  return (
    <section className="container app-page" aria-label="Iniciar sesión">
      <AuthModal
        aviso={sesionExpirada ? MENSAJE_SESION_EXPIRADA : undefined}
        onClose={() => navigate('/', { replace: true })}
        onSuccess={() => navigate(desde, { replace: true })}
      />
    </section>
  );
}
