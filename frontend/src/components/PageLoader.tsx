import { imagenCloudinary, LOGO_AZUL } from '../lib/cloudinary';

type PageLoaderProps = {
  visible: boolean;
};

// Logo de hasta 110 px (--loader-logo-size): se pide a 2x.
const LOGO_URL = imagenCloudinary(LOGO_AZUL, 240);

export default function PageLoader({ visible }: PageLoaderProps) {
  return (
    <div className={`loader-overlay ${visible ? 'is-visible' : ''}`} role="status" aria-live="polite" aria-label="Cargando contenido">
      <div className="loader">
        <svg className="ring-svg" viewBox="0 0 66 66" aria-hidden="true">
          <circle className="ring-circle" cx="33" cy="33" r="26" />
        </svg>

        <div className="coin-stage">
          <div className="coin-shadow" />

          <div className="coin-bounce">
            <div className="coin-spin">
              <div className="coin-face front">
                <img src={LOGO_URL} alt="Logo del semillero" draggable={false} onError={(event) => {
                  const target = event.currentTarget;
                  target.style.display = 'none';
                  const parent = target.parentElement;
                  if (parent) {
                    parent.style.background = '#eef1f4';
                  }
                }} />
              </div>

              <div className="coin-face back">
                <img src={LOGO_URL} alt="Logo del semillero" draggable={false} onError={(event) => {
                  const target = event.currentTarget;
                  target.style.display = 'none';
                  const parent = target.parentElement;
                  if (parent) {
                    parent.style.background = '#eef1f4';
                  }
                }} />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
