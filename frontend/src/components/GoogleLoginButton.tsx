import { useEffect, useRef, useState } from 'react';
import { SITIO } from '../config/sitio';

/** ID de cliente de Google; sin él no se muestra el botón (la ruta del backend tampoco existe). */
const GOOGLE_CLIENT_ID = (import.meta.env.VITE_GOOGLE_CLIENT_ID ?? '').trim();

const SCRIPT_GIS = 'https://accounts.google.com/gsi/client';

type RespuestaCredencial = { credential?: string };
type GoogleId = {
  initialize: (opciones: {
    client_id: string;
    callback: (respuesta: RespuestaCredencial) => void;
    hd?: string;
    ux_mode?: 'popup' | 'redirect';
    auto_select?: boolean;
    cancel_on_tap_outside?: boolean;
    context?: 'signin' | 'signup' | 'use';
  }) => void;
  renderButton: (elemento: HTMLElement, opciones: Record<string, string | number>) => void;
  cancel: () => void;
};
type VentanaConGoogle = Window & { google?: { accounts?: { id?: GoogleId } } };

let carga: Promise<GoogleId> | null = null;

/** Carga el script de Google Identity Services una sola vez. */
function cargarGis(): Promise<GoogleId> {
  const existente = (window as VentanaConGoogle).google?.accounts?.id;
  if (existente) return Promise.resolve(existente);
  if (carga) return carga;
  carga = new Promise<GoogleId>((resolve, reject) => {
    const script = document.createElement('script');
    script.src = SCRIPT_GIS;
    script.async = true;
    script.defer = true;
    script.onload = () => {
      const gis = (window as VentanaConGoogle).google?.accounts?.id;
      if (gis) resolve(gis);
      else reject(new Error('Google Identity Services no se inicializó.'));
    };
    script.onerror = () => {
      carga = null;
      reject(new Error('No se pudo cargar Google Identity Services.'));
    };
    document.head.appendChild(script);
  });
  return carga;
}

type Props = {
  /** Recibe el `credential` (id_token) de Google para enviarlo a POST /auth/google. */
  onCredencial: (idToken: string) => void;
  onError: (mensaje: string) => void;
  deshabilitado?: boolean;
};

/**
 * Botón oficial "Iniciar sesión con Google" (RF-17, DEC-B61). Pide a Google mostrar primero las cuentas del dominio
 * institucional (`hd`); es solo una sugerencia: el backend exige el dominio de todas formas.
 */
export default function GoogleLoginButton({ onCredencial, onError, deshabilitado = false }: Props) {
  const contenedor = useRef<HTMLDivElement>(null);
  const [listo, setListo] = useState(false);
  // Los callbacks cambian en cada render del modal; Google guarda el primero que recibe.
  const callbacks = useRef({ onCredencial, onError });
  useEffect(() => {
    callbacks.current = { onCredencial, onError };
  }, [onCredencial, onError]);

  useEffect(() => {
    if (!GOOGLE_CLIENT_ID) return;
    let vigente = true;
    cargarGis()
      .then((gis) => {
        if (!vigente || !contenedor.current) return;
        gis.initialize({
          client_id: GOOGLE_CLIENT_ID,
          hd: SITIO.dominioInscripcion,
          ux_mode: 'popup',
          context: 'signin',
          cancel_on_tap_outside: true,
          callback: (respuesta) => {
            if (respuesta.credential) callbacks.current.onCredencial(respuesta.credential);
            else callbacks.current.onError('Google no devolvió una credencial. Intenta de nuevo.');
          },
        });
        gis.renderButton(contenedor.current, {
          type: 'standard',
          theme: 'outline',
          size: 'large',
          text: 'signin_with',
          shape: 'rectangular',
          logo_alignment: 'left',
          locale: 'es',
          width: Math.min(contenedor.current.offsetWidth || 320, 400),
        });
        setListo(true);
      })
      .catch(() => {
        if (vigente) callbacks.current.onError('No se pudo cargar el inicio de sesión con Google. Entra con tu correo y contraseña.');
      });
    return () => {
      vigente = false;
    };
  }, []);

  if (!GOOGLE_CLIENT_ID) return null;

  return (
    <div className="google-login" aria-busy={!listo}>
      <p className="google-login__divider">
        <span>o</span>
      </p>
      <div
        ref={contenedor}
        className={`google-login__button${deshabilitado ? ' is-disabled' : ''}`}
        aria-label="Iniciar sesión con Google"
      />
      <p className="google-login__hint">Con tu cuenta institucional @{SITIO.dominioInscripcion}.</p>
    </div>
  );
}
