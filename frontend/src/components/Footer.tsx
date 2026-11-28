import { Link } from 'react-router-dom';
import { ArrowRight, Landmark, Mail, MapPin, Phone, Share2 } from 'lucide-react';
import { SITIO } from '../config/sitio';
import BrandLogo from './BrandLogo';
import SocialLinks from './SocialIcons';

export default function Footer() {
  return (
    <footer className="site-footer">
      <div className="container footer__grid">
        <div className="footer__brand">
          <BrandLogo size="lg" />
        </div>

        <div className="footer__block">
          <h4>
            <Landmark size={18} aria-hidden="true" /> Información de contacto
          </h4>
          <ul>
            <li>
              <MapPin size={16} aria-hidden="true" />
              <span>
                {SITIO.universidad}. {SITIO.direccion}
              </span>
            </li>
            <li>
              <Phone size={16} aria-hidden="true" />
              <a href={SITIO.telefono.href} className="footer__link">
                {SITIO.telefono.texto}
              </a>
            </li>
          </ul>
        </div>

        <div className="footer__block">
          <h4>
            <Mail size={18} aria-hidden="true" /> Formulario de contacto
          </h4>
          <Link to="/contacto" className="footer__link footer__link--arrow">
            Ir a Contacto <ArrowRight size={15} aria-hidden="true" />
          </Link>
          <Link to="/inscripcion" className="footer__link footer__link--arrow">
            Inscríbete al semillero <ArrowRight size={15} aria-hidden="true" />
          </Link>
        </div>

        <div className="footer__block">
          <h4>
            <Share2 size={18} aria-hidden="true" /> Redes sociales
          </h4>
          <SocialLinks />
        </div>

        <p className="footer__copy">
          Copyright © {new Date().getFullYear()} {SITIO.nombre}
          <br />
          Todos los derechos reservados
          <br />
          <span className="footer__legal">
            <Link to="/privacidad" className="footer__link">
              Política de privacidad
            </Link>
            <Link to="/terminos" className="footer__link">
              Condiciones de uso
            </Link>
          </span>
        </p>
      </div>
    </footer>
  );
}
