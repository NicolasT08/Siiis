import { Link } from 'react-router-dom';

const socialLinks = ['f', 'x', 'in', '◎', '◌'];

export default function Footer() {
  return (
    <footer className="site-footer">
      <div className="container">
        <div className="footer__top">
          <div className="footer__brand">
            <span className="brand-mark">
              <img
                src="https://res.cloudinary.com/we3ya7sq/image/upload/v1789264275/Logo_Png_Blanco.png"
                alt="Logo SIIIS"
                width="22"
                height="22"
              />
            </span>
            <div className="footer__brand-copy">
              <strong>SIIIS</strong>
            </div>
          </div>

          <div className="footer__columns">
            <div className="footer__info-block">
              <h4>Información de contacto</h4>
              <ul>
                <li>Avenida Central del Norte 39-115, 150003 Tunja, Tunja, Boyacá, Colombia</li>
                <li>+57 3138426821</li>
              </ul>
            </div>

            <div className="footer__info-block">
              <h4>Formulario de contacto</h4>
              <ul>
                <li>
                  <Link to="/contacto" className="footer__link">Ir a Contacto</Link>
                </li>
              </ul>
            </div>

            <div className="footer__info-block">
              <h4>Redes sociales</h4>
              <div className="socials">
                {socialLinks.map((item, index) => (
                  <span key={`${item}-${index}`} className="social-badge">
                    {item}
                  </span>
                ))}
              </div>
            </div>
          </div>
        </div>

        <div className="footer__bottom">
          <p>Copyright © 2025 SIIIS | Todos los derechos reservados</p>
        </div>
      </div>
    </footer>
  );
}
