import { useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { LogOut, Menu, User, X } from 'lucide-react';
import { nombreCompleto, useAuth } from '../lib/auth';
import AuthModal from './AuthModal';
import BrandLogo from './BrandLogo';

const links = [
  { to: '/', label: 'Inicio' },
  { to: '/acerca-de', label: 'Acerca de' },
  { to: '/usuarios', label: 'Usuarios' },
  { to: '/articulos', label: 'Artículos' },
  { to: '/proyectos', label: 'Proyectos' },
  { to: '/blog', label: 'Blog' },
  { to: '/contacto', label: 'Contacto' },
  { to: '/registro', label: 'Registro' },
];

export default function Navbar() {
  const [isAuthOpen, setIsAuthOpen] = useState(false);
  const { usuario, logout } = useAuth();
  const { pathname } = useLocation();
  // El menú móvil queda abierto solo en la ruta donde se abrió: al navegar se cierra solo.
  const [menuOpenAt, setMenuOpenAt] = useState<string | null>(null);
  const menuOpen = menuOpenAt === pathname;
  const setMenuOpen = (open: boolean) => setMenuOpenAt(open ? pathname : null);

  return (
    <>
      <header className="topbar">
        <nav className="nav container" aria-label="Navegación principal">
          <NavLink
            to="/"
            className="nav__brand"
            aria-label="SIIIS, ir al inicio"
            aria-describedby="brand-acronym"
          >
            <BrandLogo showAcronym />
          </NavLink>

          <button
            type="button"
            className="nav__toggle"
            aria-expanded={menuOpen}
            aria-controls="nav-menu"
            aria-label={menuOpen ? 'Cerrar menú' : 'Abrir menú'}
            onClick={() => setMenuOpen(!menuOpen)}
          >
            {menuOpen ? <X size={22} /> : <Menu size={22} />}
          </button>

          <div id="nav-menu" className={`nav__menu${menuOpen ? ' is-open' : ''}`}>
            <div className="nav__links">
              {links.map((link) => (
                <NavLink
                  key={link.to}
                  to={link.to}
                  end={link.to === '/'}
                  className={({ isActive }) => (isActive ? 'nav__link nav__link--active' : 'nav__link')}
                >
                  {link.label}
                </NavLink>
              ))}
            </div>

            {usuario ? (
              <div className="nav__user">
                <span className="nav__user-name">{nombreCompleto(usuario)}</span>
                <button type="button" className="nav__cta" onClick={() => void logout()}>
                  <LogOut size={16} aria-hidden="true" /> Cerrar sesión
                </button>
              </div>
            ) : (
              <button type="button" className="nav__cta" onClick={() => setIsAuthOpen(true)}>
                <User size={16} aria-hidden="true" /> Iniciar sesión
              </button>
            )}
          </div>
        </nav>
      </header>

      <AuthModal isOpen={isAuthOpen} onClose={() => setIsAuthOpen(false)} />
    </>
  );
}
