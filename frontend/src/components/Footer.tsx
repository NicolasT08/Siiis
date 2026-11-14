import { Link } from 'react-router-dom';
import { ArrowRight, Landmark, Mail, MapPin, Phone, Share2 } from 'lucide-react';
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
              <span>Avenida Central del Norte 39-115, 150003 Tunja, Boyacá, Colombia</span>
            </li>
            <li>
              <Phone size={16} aria-hidden="true" />
              <a href="tel:+573138426821" className="footer__link">
                +57 313 842 6821
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
        </div>

        <div className="footer__block">
          <h4>
            <Share2 size={18} aria-hidden="true" /> Redes sociales
          </h4>
          <SocialLinks />
        </div>

        <p className="footer__copy">
          Copyright © 2025 SIIIS
          <br />
          Todos los derechos reservados
        </p>
      </div>
    </footer>
  );
}
