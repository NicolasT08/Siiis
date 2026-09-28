import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { api, errorText } from '../lib/api';
import { useAuth } from '../lib/auth';

type AuthModalProps = {
  isOpen: boolean;
  onClose: () => void;
};

export default function AuthModal({ isOpen, onClose }: AuthModalProps) {
  const loginFormRef = useRef<HTMLFormElement | null>(null);
  const forgotFormRef = useRef<HTMLFormElement | null>(null);
  const loginInputRef = useRef<HTMLInputElement | null>(null);
  const forgotInputRef = useRef<HTMLInputElement | null>(null);
  const lastFocusedRef = useRef<HTMLElement | null>(null);

  const [showPassword, setShowPassword] = useState(false);
  const [mode, setMode] = useState<'login' | 'forgot' | 'success'>('login');
  const [forgotMessage, setForgotMessage] = useState('');
  const [error, setError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const { login } = useAuth();

  useEffect(() => {
    if (!isOpen) return;

    lastFocusedRef.current = document.activeElement as HTMLElement | null;
    document.body.style.overflow = 'hidden';

    const focusable = mode === 'login' ? loginInputRef.current : forgotInputRef.current;
    focusable?.focus();

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onClose();
        return;
      }

      if (event.key !== 'Tab') return;

      const container =
        mode === 'login' ? document.getElementById('loginModal') : document.getElementById('forgotModal');
      const focusables = container
        ? Array.from(
            container.querySelectorAll<HTMLElement>(
              'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
            ),
          )
        : [];

      if (!focusables.length) return;

      const first = focusables[0];
      const last = focusables[focusables.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      document.body.style.overflow = '';
      lastFocusedRef.current?.focus();
    };
  }, [isOpen, mode, onClose]);

  useEffect(() => {
    if (!isOpen) {
      setShowPassword(false);
      setMode('login');
      setForgotMessage('');
      setError('');
      setIsSubmitting(false);
    }
  }, [isOpen]);

  const handleLoginSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setError('');
    setIsSubmitting(true);
    try {
      await login(String(form.get('email') ?? ''), String(form.get('password') ?? ''), form.get('remember') === 'on');
      onClose();
    } catch (err) {
      setError(errorText(err));
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleForgotSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const correo = String(new FormData(event.currentTarget).get('email') ?? '');
    setError('');
    setIsSubmitting(true);
    try {
      const { mensaje } = await api.forgotPassword(correo);
      setForgotMessage(mensaje);
      setMode('success');
    } catch (err) {
      setError(errorText(err));
    } finally {
      setIsSubmitting(false);
    }
  };

  const openForgot = () => {
    setForgotMessage('');
    setError('');
    setMode('forgot');
    forgotFormRef.current?.reset();
  };

  const backToLogin = () => {
    setError('');
    setMode('login');
  };

  if (!isOpen) return null;

  return (
    <>
      <div
        className={`modal-overlay ${isOpen ? 'is-visible' : ''}`}
        aria-hidden={!isOpen}
        onClick={(event) => {
          if (event.target === event.currentTarget) onClose();
        }}
      >
        <div className="modal" id="loginModal" role="dialog" aria-modal="true" aria-labelledby="loginTitle">
          <button type="button" className="modal__close" onClick={onClose} aria-label="Cerrar">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M6 6L18 18M18 6L6 18" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
            </svg>
          </button>

          <div className="modal__visual">
            <svg className="modal__constellation" viewBox="0 0 320 420" preserveAspectRatio="xMidYMid slice" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
              <line x1="30" y1="60" x2="90" y2="120" stroke="#f9bc3d" strokeOpacity="0.25" />
              <line x1="90" y1="120" x2="60" y2="200" stroke="#f9bc3d" strokeOpacity="0.2" />
              <line x1="250" y1="300" x2="300" y2="360" stroke="#f9bc3d" strokeOpacity="0.22" />
              <line x1="220" y1="80" x2="270" y2="40" stroke="#f9bc3d" strokeOpacity="0.2" />
              <circle cx="30" cy="60" r="3" fill="#f9bc3d" />
              <circle cx="90" cy="120" r="2" fill="#f9bc3d" />
              <circle cx="60" cy="200" r="2.4" fill="#f9bc3d" />
              <circle cx="250" cy="300" r="2.6" fill="#f9bc3d" />
              <circle cx="300" cy="360" r="2" fill="#f9bc3d" />
              <circle cx="220" cy="80" r="2.2" fill="#f9bc3d" />
              <circle cx="270" cy="40" r="1.8" fill="#f9bc3d" />
            </svg>

            <div className="modal__brand">
              <span className="modal__brand-badge">
                <img
                  src="https://res.cloudinary.com/we3ya7sq/image/upload/v1789264275/Logo_Png_Blanco.png"
                  alt=""
                  width="26"
                  height="26"
                />
              </span>
              <span>SIIIS</span>
            </div>

            <div>
              <svg className="modal__seed" viewBox="0 0 76 76" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle className="bulb" cx="38" cy="48" r="16" />
                <path d="M38 32C38 20 30 15 18 12" />
                <path d="M38 32C38 20 46 15 58 12" />
              </svg>

              <h2 id="loginTitle">Bienvenido</h2>
              <p>Sigue con tus proyectos, artículos y la comunidad del Semillero.</p>
            </div>

            <footer>Semillero de Investigación, Innovación e Ingeniería de Sistemas</footer>
          </div>

          <div className="modal__form-panel">
            <h1>Inicia sesión</h1>
            <p className="lead">Usa tu correo institucional para continuar.</p>

            <form ref={loginFormRef} id="loginForm" onSubmit={handleLoginSubmit} noValidate>
              {mode === 'login' && error && (
                <p className="form__error" role="alert">
                  {error}
                </p>
              )}
              <div className="field">
                <label htmlFor="loginEmail">Correo electrónico</label>
                <div className="field__control">
                  <input
                    ref={loginInputRef}
                    type="email"
                    id="loginEmail"
                    name="email"
                    placeholder="correo@uptc.edu.co"
                    autoComplete="email"
                    required
                  />
                </div>
              </div>

              <div className="field field--password">
                <label htmlFor="loginPassword">Contraseña</label>
                <div className="field__control">
                  <input
                    type={showPassword ? 'text' : 'password'}
                    id="loginPassword"
                    name="password"
                    placeholder="Tu contraseña"
                    autoComplete="current-password"
                    required
                  />
                  <button
                    type="button"
                    className="field__toggle"
                    aria-pressed={showPassword}
                    aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                    onClick={() => setShowPassword((current) => !current)}
                  >
                    <svg className="icon-on" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path d="M1.5 12s4-7.5 10.5-7.5S22.5 12 22.5 12s-4 7.5-10.5 7.5S1.5 12 1.5 12Z" stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round" />
                      <circle cx="12" cy="12" r="3" stroke="currentColor" strokeWidth="1.6" />
                    </svg>
                    <svg className="icon-off" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path d="M3 3l18 18M10.6 10.7a3 3 0 0 0 4.2 4.2M7.4 7.5C4.9 9 3 12 3 12s4 7.5 10.5 7.5c1.9 0 3.6-.5 5-1.2M16.8 16.9c2.8-1.6 4.7-4.9 4.7-4.9s-1.7-3.2-4.9-5.3" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                  </button>
                </div>
              </div>

              <div className="form__row">
                <label className="form__remember">
                  <input type="checkbox" name="remember" />
                  Recordarme
                </label>
                <button type="button" className="form__forgot" onClick={openForgot}>
                  ¿Olvidaste tu contraseña?
                </button>
              </div>

              <button type="submit" className="form__submit" disabled={isSubmitting}>
                {isSubmitting && mode === 'login' ? 'Ingresando…' : 'Ingresar'}
              </button>

              <p className="form__switch">
                ¿No estás en el Semillero?
                <a href="https://siiis.com.co/">Inscríbete</a>
              </p>
            </form>
          </div>
        </div>
      </div>

      <div
        className={`modal-overlay ${mode === 'forgot' || mode === 'success' ? 'is-visible' : ''}`}
        aria-hidden={mode !== 'forgot' && mode !== 'success'}
        onClick={(event) => {
          if (event.target === event.currentTarget) onClose();
        }}
      >
        <div className="modal modal--compact" id="forgotModal" role="dialog" aria-modal="true" aria-labelledby="forgotTitle">
          <button type="button" className="modal__close" onClick={onClose} aria-label="Cerrar">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M6 6L18 18M18 6L6 18" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
            </svg>
          </button>

          <div className="modal__visual">
            <svg className="modal__constellation" viewBox="0 0 320 200" preserveAspectRatio="xMidYMid slice" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
              <line x1="30" y1="40" x2="80" y2="80" stroke="#f9bc3d" strokeOpacity="0.22" />
              <line x1="250" y1="150" x2="290" y2="180" stroke="#f9bc3d" strokeOpacity="0.2" />
              <circle cx="30" cy="40" r="2.4" fill="#f9bc3d" />
              <circle cx="80" cy="80" r="2" fill="#f9bc3d" />
              <circle cx="250" cy="150" r="2.2" fill="#f9bc3d" />
              <circle cx="290" cy="180" r="1.8" fill="#f9bc3d" />
            </svg>

            <div className="modal__brand">
              <span className="modal__brand-badge">
                <img
                  src="https://res.cloudinary.com/we3ya7sq/image/upload/v1789264275/Logo_Png_Blanco.png"
                  alt=""
                  width="26"
                  height="26"
                />
              </span>
              <span>SIIIS</span>
            </div>

            <svg className="modal__seed" viewBox="0 0 76 76" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
              <circle className="bulb" cx="38" cy="48" r="16" />
              <path d="M38 32C38 20 30 15 18 12" />
              <path d="M38 32C38 20 46 15 58 12" />
            </svg>

            <h2 id="forgotTitle">Recupera tu acceso</h2>
            <p>Te enviaremos un enlace para restablecer tu contraseña.</p>
          </div>

          <div className="modal__form-panel">
            {mode !== 'success' ? (
              <div id="forgotFormView">
                <form ref={forgotFormRef} id="forgotForm" onSubmit={handleForgotSubmit} noValidate>
                  {error && (
                    <p className="form__error" role="alert">
                      {error}
                    </p>
                  )}
                  <div className="field">
                    <label htmlFor="forgotEmail">Correo electrónico</label>
                    <div className="field__control">
                      <input
                        ref={forgotInputRef}
                        type="email"
                        id="forgotEmail"
                        name="email"
                        placeholder="nombre@ejemplo.com"
                        autoComplete="email"
                        required
                      />
                    </div>
                  </div>

                  <button type="submit" className="form__submit" disabled={isSubmitting}>
                    {isSubmitting ? 'Enviando…' : 'Enviar enlace'}
                  </button>
                </form>

                <p className="form__switch">
                  ¿Ya la recordaste? <button type="button" className="form__switch-link" onClick={backToLogin}>Inicia sesión</button>
                </p>
              </div>
            ) : (
              <div id="forgotSuccessView" className="forgot-success">
                <div className="forgot-success__icon">
                  <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M5 13l4 4L19 7" stroke="#f9bc3d" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                </div>
                <h3>Revisa tu correo</h3>
                <p>{forgotMessage}</p>
                <button type="button" className="form__submit" onClick={backToLogin}>
                  Volver a iniciar sesión
                </button>
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  );
}
