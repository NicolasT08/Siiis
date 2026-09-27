import { useState } from 'react';
import { NavLink } from 'react-router-dom';
import AuthModal from './AuthModal';

const links = [
  { to: '/', label: 'Inicio' },
  { to: '/proyectos', label: 'Proyectos' },
  { to: '/articulos', label: 'Artículos' },
  { to: '/usuarios', label: 'Usuarios' },
  { to: '/acerca-de', label: 'Acerca de' },
  { to: '/contacto', label: 'Contacto' },
  { to: '/blog', label: 'Blog' },
];

export default function Navbar() {
  const [isAuthOpen, setIsAuthOpen] = useState(false);

  return (
    <>
      <header className="topbar">
        <nav className="nav container" aria-label="Navegación principal">
          <div className="nav__brand">
            <span className="nav__brand-badge">
              <img
                src="https://res.cloudinary.com/we3ya7sq/image/upload/v1789264275/Logo_Png_Blanco.png"
                alt="Logo del semillero"
                width="16"
                height="16"
              />
            </span>
            <span>SIIIS</span>
          </div>

          <div className="nav__links">
            {links.map((link) => (
              <NavLink
                key={link.to}
                to={link.to}
                className={({ isActive }) => (isActive ? 'nav__link nav__link--active' : 'nav__link')}
              >
                {link.label}
              </NavLink>
            ))}
          </div>

          <button type="button" className="nav__cta" onClick={() => setIsAuthOpen(true)}>
            Iniciar sesión
          </button>
        </nav>
      </header>

      <AuthModal isOpen={isAuthOpen} onClose={() => setIsAuthOpen(false)} />
    </>
  );
}
