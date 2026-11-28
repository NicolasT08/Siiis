import { imagenCloudinary, LOGO_BLANCO } from '../lib/cloudinary';

// Logo oficial (Cloudinary del proyecto). No sustituir por recortes del mockup. Se pide a 2x de los 44 px en que se ve.
const LOGO_URL = imagenCloudinary(LOGO_BLANCO, 96);

export default function BrandLogo({
  size = 'md',
  showAcronym = false,
}: {
  size?: 'md' | 'lg';
  showAcronym?: boolean;
}) {
  return (
    <span className={`brand-logo brand-logo--${size}`}>
      <img className="brand-logo__mark" src={LOGO_URL} alt="" width="44" height="44" fetchPriority="high" decoding="async" />
      <span className="brand-logo__text">
        <small>Semillero</small>
        <strong>SIIIS</strong>
      </span>
      {showAcronym && (
        <span className="brand-logo__acronym" id="brand-acronym" role="tooltip">
          <span><b>S</b>emillero</span>
          <span><b>I</b>nvestigación</span>
          <span><b>I</b>nnovación</span>
          <span><b>I</b>ngeniería</span>
          <span><b>S</b>istemas</span>
        </span>
      )}
    </span>
  );
}
