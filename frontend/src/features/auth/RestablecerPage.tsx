import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { KeyRound } from 'lucide-react';
import { api } from '../../lib/api';
import { erroresDeCampo, mensajeError } from '../../lib/errors';
import { Aviso, ErrorCampo } from '../../components/ui';
import { problemaPassword } from './passwordRules';

/** /restablecer?token=… — enlace del correo de recuperación (DEC-B10, POST /auth/reset-password). */
export default function RestablecerPage() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const tokenValido = /^[a-f0-9]{64}$/.test(token);
  const [enviando, setEnviando] = useState(false);
  const [ok, setOk] = useState('');
  const [error, setError] = useState('');
  const [campos, setCampos] = useState<Record<string, string>>({});

  const enviar = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const datos = new FormData(event.currentTarget);
    const password = String(datos.get('password') ?? '');
    const confirmar = String(datos.get('confirmar') ?? '');
    const errores: Record<string, string> = {};
    const problema = problemaPassword(password);
    if (problema) errores.password = problema;
    else if (password !== confirmar) errores.confirmar = 'Las contraseñas no coinciden.';
    setError('');
    setCampos(errores);
    if (Object.keys(errores).length) return;

    setEnviando(true);
    try {
      const { mensaje } = await api.resetPassword(token, password);
      setOk(mensaje);
    } catch (e) {
      setError(mensajeError(e));
      setCampos(erroresDeCampo(e));
    } finally {
      setEnviando(false);
    }
  };

  return (
    <section className="container app-page app-narrow">
      <div className="app-card">
        <h1>
          <KeyRound size={22} aria-hidden="true" /> Restablecer contraseña
        </h1>
        {!tokenValido ? (
          <Aviso tipo="error">
            El enlace no es válido o está incompleto. Pide uno nuevo desde "¿Olvidaste tu contraseña?" al iniciar sesión.
          </Aviso>
        ) : ok ? (
          <>
            <Aviso tipo="ok">{ok}</Aviso>
            <Link className="btn btn--navy" to="/login">
              Iniciar sesión
            </Link>
          </>
        ) : (
          <form className="app-form" onSubmit={(e) => void enviar(e)} noValidate>
            <p className="app-hint">Mínimo 8 caracteres, con al menos una letra y un número.</p>
            <div className="app-field">
              <label htmlFor="reset-password">Nueva contraseña</label>
              <input id="reset-password" name="password" type="password" autoComplete="new-password" required aria-describedby="reset-password-e" aria-invalid={!!campos.password} />
              <ErrorCampo id="reset-password-e" mensaje={campos.password ?? campos.token} />
            </div>
            <div className="app-field">
              <label htmlFor="reset-confirmar">Repite la contraseña</label>
              <input id="reset-confirmar" name="confirmar" type="password" autoComplete="new-password" required aria-describedby="reset-confirmar-e" aria-invalid={!!campos.confirmar} />
              <ErrorCampo id="reset-confirmar-e" mensaje={campos.confirmar} />
            </div>
            {error && <Aviso tipo="error">{error}</Aviso>}
            <button type="submit" className="btn btn--navy" disabled={enviando}>
              {enviando ? 'Guardando…' : 'Guardar contraseña'}
            </button>
          </form>
        )}
      </div>
    </section>
  );
}
