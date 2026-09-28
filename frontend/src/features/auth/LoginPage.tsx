import { useState } from 'react';
import type { FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { errorText } from '../../lib/api';
import { useAuth } from '../../lib/auth';

export default function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [error, setError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setError('');
    setIsSubmitting(true);
    try {
      // Sin casilla "Recordarme" en esta página: el token va a sessionStorage.
      await login(String(form.get('email') ?? ''), String(form.get('password') ?? ''), false);
      navigate('/', { replace: true });
    } catch (err) {
      setError(errorText(err));
      setIsSubmitting(false);
    }
  };

  return (
    <section className="auth-page container">
      <div className="auth-card">
        <h1>Iniciar sesión</h1>
        <form onSubmit={handleSubmit} noValidate>
          {error && (
            <p className="form__error" role="alert">
              {error}
            </p>
          )}
          <label>
            Correo
            <input type="email" name="email" placeholder="correo@ejemplo.com" autoComplete="email" />
          </label>
          <label>
            Contraseña
            <input type="password" name="password" placeholder="********" autoComplete="current-password" />
          </label>
          <button type="submit" className="primary-btn" disabled={isSubmitting}>
            {isSubmitting ? 'Entrando…' : 'Entrar'}
          </button>
        </form>
        <p>
          ¿No tienes cuenta? <a href="/registro">Regístrate</a>
        </p>
      </div>
    </section>
  );
}
