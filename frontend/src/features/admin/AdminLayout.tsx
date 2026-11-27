import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../../lib/auth';
import { seccionesAdmin } from './secciones';

export default function AdminLayout() {
  const { usuario } = useAuth();
  return (
    <section className="container app-page">
      <nav className="app-tabs" aria-label="Secciones de administración">
        <NavLink to="/admin" end className={({ isActive }) => (isActive ? 'is-active' : '')}>
          Panel
        </NavLink>
        {seccionesAdmin(usuario?.rol).map((s) => (
          <NavLink key={s.to} to={s.to} className={({ isActive }) => (isActive ? 'is-active' : '')}>
            {s.label}
          </NavLink>
        ))}
      </nav>
      <Outlet />
    </section>
  );
}
