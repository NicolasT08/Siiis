import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import { ChevronDown, FileText, LayoutDashboard, LogOut, Menu, User, X } from 'lucide-react';
import { esRevisor, nombreCompleto, useAuth } from '../lib/auth';
import AuthModal from './AuthModal';
import BrandLogo from './BrandLogo';

const links = [
  { to: '/', label: 'Inicio' },
  { to: '/acerca-de', label: 'Acerca de' },
  { to: '/usuarios', label: 'Integrantes' },
  { to: '/proyectos', label: 'Proyectos' },
  { to: '/articulos', label: 'Artículos' },
  { to: '/noticias', label: 'Noticias' },
  { href: 'https://semillerosiiis.blogspot.com/', label: 'Blog' },
  { to: '/contacto', label: 'Contacto' },
  { to: '/inscripcion', label: 'Inscripción' },
];

function MenuUsuario() {
  const { usuario, logout } = useAuth();
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const [abiertoEn, setAbiertoEn] = useState<string | null>(null);
  const abierto = abiertoEn === pathname;
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!abierto) return;
    const cerrar = (event: MouseEvent | KeyboardEvent) => {
      if (event instanceof KeyboardEvent ? event.key === 'Escape' : !ref.current?.contains(event.target as Node)) {
        setAbiertoEn(null);
      }
    };
    document.addEventListener('mousedown', cerrar);
    document.addEventListener('keydown', cerrar);
    return () => {
      document.removeEventListener('mousedown', cerrar);
      document.removeEventListener('keydown', cerrar);
    };
  }, [abierto]);

  if (!usuario) return null;

  const salir = async () => {
    setAbiertoEn(null);
    await logout();
    navigate('/');
  };

  return (
    <div className="nav__user" ref={ref}>
      <button
        type="button"
        className="nav__user-toggle"
        aria-expanded={abierto}
        aria-controls="menu-usuario"
        onClick={() => setAbiertoEn(abierto ? null : pathname)}
      >
        {usuario.foto_perfil ? (
          <img className="nav__user-avatar" src={usuario.foto_perfil.url} alt="" />
        ) : (
          <User size={16} aria-hidden="true" />
        )}
        <span className="nav__user-name">{nombreCompleto(usuario)}</span>
        <ChevronDown size={15} aria-hidden="true" />
      </button>
      {abierto && (
        <ul id="menu-usuario" className="nav__user-menu">
          <li>
            <Link to="/mi-perfil">
              <User size={16} aria-hidden="true" /> Mi perfil
            </Link>
          </li>
          <li>
            <Link to="/mis-contenidos">
              <FileText size={16} aria-hidden="true" /> Mis contenidos
            </Link>
          </li>
          {esRevisor(usuario.rol) && (
            <li>
              <Link to="/admin">
                <LayoutDashboard size={16} aria-hidden="true" /> Administración
              </Link>
            </li>
          )}
          <li>
            <button type="button" onClick={() => void salir()}>
              <LogOut size={16} aria-hidden="true" /> Cerrar sesión
            </button>
          </li>
        </ul>
      )}
    </div>
  );
}

export default function Navbar() {
  const [isAuthOpen, setIsAuthOpen] = useState(false);
  const { usuario } = useAuth();
  const { pathname } = useLocation();
  // El menú móvil queda abierto solo en la ruta donde se abrió: al navegar se cierra solo.
  const [menuOpenAt, setMenuOpenAt] = useState<string | null>(null);
  const menuOpen = menuOpenAt === pathname;
  const setMenuOpen = (open: boolean) => setMenuOpenAt(open ? pathname : null);

  return (
    <>
      <header className="topbar">
        <nav className="nav container" aria-label="Navegación principal">
          <NavLink to="/" className="nav__brand" aria-label="SIIIS, ir al inicio" aria-describedby="brand-acronym">
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
              {links.map((link) =>
                'href' in link ? (
                  <a key={link.href} href={link.href} className="nav__link" target="_blank" rel="noopener noreferrer">
                    {link.label}
                  </a>
                ) : (
                <NavLink
                  key={link.to}
                  to={link.to}
                  end={link.to === '/'}
                  className={({ isActive }) => (isActive ? 'nav__link nav__link--active' : 'nav__link')}
                >
                  {link.label}
                </NavLink>
                ),
              )}
            </div>

            {usuario ? (
              <MenuUsuario />
            ) : (
              <button type="button" className="nav__cta" onClick={() => setIsAuthOpen(true)}>
                <User size={16} aria-hidden="true" /> Iniciar sesión
              </button>
            )}
          </div>
        </nav>
      </header>

      {isAuthOpen && <AuthModal onClose={() => setIsAuthOpen(false)} />}
    </>
  );
}
