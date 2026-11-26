// Logo oficial (Cloudinary del proyecto). No sustituir por recortes del mockup.
const LOGO_URL = 'https://res.cloudinary.com/we3ya7sq/image/upload/v1789264275/Logo_Png_Blanco.png';

export default function BrandLogo({
  size = 'md',
  showAcronym = false,
}: {
  size?: 'md' | 'lg';
  showAcronym?: boolean;
}) {
  return (
    <span className={`brand-logo brand-logo--${size}`}>
      <img className="brand-logo__mark" src={LOGO_URL} alt="" width="44" height="44" />
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
