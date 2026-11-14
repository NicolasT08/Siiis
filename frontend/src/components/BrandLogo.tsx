// Logo oficial (Cloudinary del proyecto). No sustituir por recortes del mockup.
const LOGO_URL = 'https://res.cloudinary.com/we3ya7sq/image/upload/v1789264275/Logo_Png_Blanco.png';

export default function BrandLogo({ size = 'md' }: { size?: 'md' | 'lg' }) {
  return (
    <span className={`brand-logo brand-logo--${size}`}>
      <span className="brand-logo__mark">
        <img src={LOGO_URL} alt="" width="40" height="40" />
      </span>
      <span className="brand-logo__text">
        <strong>SIIIS</strong>
        <small>Semillero de Investigación</small>
      </span>
    </span>
  );
}
