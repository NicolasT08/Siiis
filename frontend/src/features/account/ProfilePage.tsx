import { useState } from 'react';
import type { ChangeEvent, FormEvent } from 'react';
import { Camera, KeyRound, Save, Trash2, UserCog } from 'lucide-react';
import { api, esVistaAdmin } from '../../lib/api';
import type { UsuarioAdmin } from '../../lib/api';
import { nombreCompleto, useAuth } from '../../lib/auth';
import { validarFoto } from '../../lib/archivos';
import { erroresDeCampo, mensajeError } from '../../lib/errors';
import { etiquetaRol, iniciales } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { Aviso, Cargando, ErrorCampo, ErrorCarga } from '../../components/ui';
import ConfirmDialog from '../../components/ConfirmDialog';
import { problemaPassword } from '../auth/passwordRules';
import { leerPerfil } from './perfil';
import { imagenCloudinary } from '../../lib/cloudinary';

export type Resultado = { tipo: 'ok' | 'error'; texto: string } | null;

/** Campos de perfil editables (6e). Lo usan "Mi perfil" y la edición de usuarios del panel. */
export function CamposPerfil({ usuario, campos, deshabilitado }: { usuario: UsuarioAdmin | null; campos: Record<string, string>; deshabilitado: boolean }) {
  const f = (c: string) => ({ id: `perfil-${c}`, name: c, disabled: deshabilitado, 'aria-invalid': !!campos[c], 'aria-describedby': `perfil-${c}-e` });
  return (
    <div className="app-form-grid">
      <div className="app-field">
        <label htmlFor="perfil-nombre">Nombre</label>
        <input {...f('nombre')} defaultValue={usuario?.nombre ?? ''} required maxLength={80} autoComplete="given-name" />
        <ErrorCampo id="perfil-nombre-e" mensaje={campos.nombre} />
      </div>
      <div className="app-field">
        <label htmlFor="perfil-apellido">Apellido</label>
        <input {...f('apellido')} defaultValue={usuario?.apellido ?? ''} required maxLength={80} autoComplete="family-name" />
        <ErrorCampo id="perfil-apellido-e" mensaje={campos.apellido} />
      </div>
      <div className="app-field">
        <label htmlFor="perfil-programa_academico">Programa académico</label>
        <input {...f('programa_academico')} defaultValue={usuario?.programa_academico ?? ''} maxLength={120} />
        <ErrorCampo id="perfil-programa_academico-e" mensaje={campos.programa_academico} />
      </div>
      <div className="app-field">
        <label htmlFor="perfil-semestre">Semestre</label>
        <select {...f('semestre')} defaultValue={usuario?.semestre ? String(usuario.semestre) : ''}>
          <option value="">Sin semestre</option>
          {Array.from({ length: 10 }, (_, i) => i + 1).map((s) => (
            <option key={s} value={s}>
              {s}° semestre
            </option>
          ))}
        </select>
        <ErrorCampo id="perfil-semestre-e" mensaje={campos.semestre} />
      </div>
      <div className="app-field">
        <label htmlFor="perfil-telefono">Teléfono (no es público)</label>
        <input {...f('telefono')} type="tel" defaultValue={usuario?.telefono ?? ''} maxLength={25} autoComplete="tel" />
        <ErrorCampo id="perfil-telefono-e" mensaje={campos.telefono} />
      </div>
      <div className="app-field app-field--wide">
        <label htmlFor="perfil-biografia">Biografía</label>
        <textarea {...f('biografia')} defaultValue={usuario?.biografia ?? ''} rows={4} maxLength={1000} />
        <ErrorCampo id="perfil-biografia-e" mensaje={campos.biografia} />
      </div>
    </div>
  );
}

/**
 * Subir o cambiar la foto (6g) y, en el propio perfil, quitarla (6h, DEC-B74). En la edición de usuarios del panel
 * no aparece "Quitar foto": nadie quita la foto de otro.
 */
export function FotoPerfil({ usuario, onCambio }: { usuario: UsuarioAdmin; onCambio: (u: UsuarioAdmin) => void }) {
  const { usuario: sesion, actualizarSesion } = useAuth();
  const [subiendo, setSubiendo] = useState(false);
  const [resultado, setResultado] = useState<Resultado>(null);
  const [confirmando, setConfirmando] = useState(false);
  const [quitando, setQuitando] = useState(false);
  const [errorQuitar, setErrorQuitar] = useState('');
  const puedeQuitar = !!usuario.foto_perfil && sesion?.id === usuario.id;
  const ocupado = subiendo || quitando;

  const quitar = async () => {
    setQuitando(true);
    setErrorQuitar('');
    try {
      const { usuario: nuevo } = await api.removePhoto(usuario.id);
      onCambio(nuevo);
      // El menú muestra las iniciales enseguida, sin esperar a /auth/me.
      actualizarSesion({ foto_perfil: null });
      setConfirmando(false);
      setResultado({ tipo: 'ok', texto: 'Quitaste tu foto de perfil. Puedes subir otra cuando quieras.' });
    } catch (e) {
      setErrorQuitar(mensajeError(e));
    } finally {
      setQuitando(false);
    }
  };

  const elegir = async (event: ChangeEvent<HTMLInputElement>) => {
    const archivo = event.target.files?.[0];
    event.target.value = '';
    if (!archivo) return;
    const problema = validarFoto(archivo);
    if (problema) {
      setResultado({ tipo: 'error', texto: problema });
      return;
    }
    setSubiendo(true);
    setResultado(null);
    try {
      const { usuario: nuevo } = await api.uploadPhoto(usuario.id, archivo);
      onCambio(nuevo);
      setResultado({ tipo: 'ok', texto: 'Foto actualizada. Se recorta a un cuadrado de 400 × 400.' });
    } catch (e) {
      setResultado({ tipo: 'error', texto: mensajeError(e) });
    } finally {
      setSubiendo(false);
    }
  };

  return (
    <div className="app-profile__photo">
      {usuario.foto_perfil ? (
        <img src={imagenCloudinary(usuario.foto_perfil.url, 320)} decoding="async" alt={`Foto de ${nombreCompleto(usuario)}`} />
      ) : (
        <span className="avatar--initials" aria-hidden="true">
          {iniciales(usuario)}
        </span>
      )}
      <div className="app-actions app-profile__photo-actions">
        <label className={`btn btn--navy btn-archivo${ocupado ? ' is-disabled' : ''}`}>
          <Camera size={16} aria-hidden="true" /> {subiendo ? 'Subiendo…' : usuario.foto_perfil ? 'Cambiar foto' : 'Subir foto'}
          <input type="file" accept="image/jpeg,image/png,image/webp" className="visually-hidden" disabled={ocupado} onChange={(e) => void elegir(e)} />
        </label>
        {puedeQuitar && (
          <button
            type="button"
            className="btn btn--danger"
            disabled={ocupado}
            onClick={() => {
              setErrorQuitar('');
              setConfirmando(true);
            }}
          >
            <Trash2 size={16} aria-hidden="true" /> Quitar foto
          </button>
        )}
      </div>
      <p className="app-hint">JPG, PNG o WebP de hasta 2 MB.</p>
      {resultado && <Aviso tipo={resultado.tipo}>{resultado.texto}</Aviso>}
      {confirmando && (
        <ConfirmDialog
          titulo="¿Quitar tu foto de perfil?"
          confirmar="Quitar foto"
          ocupado={quitando}
          error={errorQuitar}
          onConfirmar={() => void quitar()}
          onCancelar={() => setConfirmando(false)}
        >
          <p>Se borrará tu foto y en su lugar se mostrarán tus iniciales en tu perfil y en el menú. Puedes subir otra cuando quieras.</p>
        </ConfirmDialog>
      )}
    </div>
  );
}

function CambiarPassword() {
  const [enviando, setEnviando] = useState(false);
  const [resultado, setResultado] = useState<Resultado>(null);
  const [campos, setCampos] = useState<Record<string, string>>({});

  const enviar = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    const datos = new FormData(form);
    const actual = String(datos.get('password_actual') ?? '');
    const nueva = String(datos.get('password_nueva') ?? '');
    const errores: Record<string, string> = {};
    if (!actual) errores.password_actual = 'Escribe tu contraseña actual.';
    const problema = problemaPassword(nueva);
    if (problema) errores.password_nueva = problema;
    else if (nueva === actual) errores.password_nueva = 'La nueva contraseña debe ser distinta de la actual.';
    else if (nueva !== String(datos.get('confirmar') ?? '')) errores.confirmar = 'Las contraseñas no coinciden.';
    setCampos(errores);
    setResultado(null);
    if (Object.keys(errores).length) return;

    setEnviando(true);
    try {
      const { mensaje } = await api.changePassword(actual, nueva);
      form.reset();
      setResultado({ tipo: 'ok', texto: mensaje });
    } catch (e) {
      setCampos(erroresDeCampo(e));
      setResultado({ tipo: 'error', texto: mensajeError(e) });
    } finally {
      setEnviando(false);
    }
  };

  return (
    <form className="app-card app-form" onSubmit={(e) => void enviar(e)} noValidate>
      <h2>
        <KeyRound size={20} aria-hidden="true" /> Cambiar contraseña
      </h2>
      <p className="app-hint">Mínimo 8 caracteres, con al menos una letra y un número. Se cerrarán tus sesiones en otros dispositivos.</p>
      {(['password_actual', 'password_nueva', 'confirmar'] as const).map((c) => (
        <div className="app-field" key={c}>
          <label htmlFor={`pw-${c}`}>{c === 'password_actual' ? 'Contraseña actual' : c === 'password_nueva' ? 'Nueva contraseña' : 'Repite la nueva contraseña'}</label>
          <input
            id={`pw-${c}`}
            name={c}
            type="password"
            autoComplete={c === 'password_actual' ? 'current-password' : 'new-password'}
            aria-invalid={!!campos[c]}
            aria-describedby={`pw-${c}-e`}
          />
          <ErrorCampo id={`pw-${c}-e`} mensaje={campos[c]} />
        </div>
      ))}
      {resultado && <Aviso tipo={resultado.tipo}>{resultado.texto}</Aviso>}
      <button type="submit" className="btn btn--navy" disabled={enviando}>
        {enviando ? 'Guardando…' : 'Cambiar contraseña'}
      </button>
    </form>
  );
}

export default function ProfilePage() {
  const { usuario: sesion, refrescar } = useAuth();
  const id = sesion?.id ?? '';
  // El propio usuario recibe la vista completa (correo, teléfono) en GET /users/{id} (6d).
  const { estado, recargar } = useApi((signal) => api.user(id, signal), [id]);
  const [actualizado, setActualizado] = useState<UsuarioAdmin | null>(null);
  const [guardando, setGuardando] = useState(false);
  const [resultado, setResultado] = useState<Resultado>(null);
  const [campos, setCampos] = useState<Record<string, string>>({});

  const cargado = estado.status === 'ready' && esVistaAdmin(estado.data.usuario) ? estado.data.usuario : null;
  const usuario = actualizado ?? cargado;

  const cambiarUsuario = (nuevo: UsuarioAdmin) => {
    setActualizado(nuevo);
    void refrescar().catch(() => undefined);
  };

  const guardar = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!usuario) return;
    const datos = leerPerfil(new FormData(event.currentTarget));
    setGuardando(true);
    setResultado(null);
    setCampos({});
    try {
      const { usuario: nuevo } = await api.updateUser(usuario.id, datos);
      cambiarUsuario(nuevo);
      setResultado({ tipo: 'ok', texto: 'Tus datos se guardaron.' });
    } catch (e) {
      setCampos(erroresDeCampo(e));
      setResultado({ tipo: 'error', texto: mensajeError(e) });
    } finally {
      setGuardando(false);
    }
  };

  return (
    <section className="container app-page">
      <div className="app-panel-head">
        <div>
          <h1>Mi perfil</h1>
          <p>Así te ven los demás en la página de integrantes. Tu correo y tu teléfono no son públicos.</p>
        </div>
      </div>
      {estado.status === 'loading' && <Cargando texto="Cargando tu perfil…" />}
      {estado.status === 'error' && <ErrorCarga error={estado.error} onRetry={recargar} />}
      {usuario && (
        <div className="app-stack">
          <div className="app-card app-profile">
            <FotoPerfil usuario={usuario} onCambio={cambiarUsuario} />
            <form className="app-form" onSubmit={(e) => void guardar(e)} noValidate key={usuario.fecha_ingreso ?? usuario.id}>
              <h2>
                <UserCog size={20} aria-hidden="true" /> Mis datos
              </h2>
              <dl className="app-dl">
                <dt>Correo</dt>
                <dd>{usuario.correo}</dd>
                <dt>Rol</dt>
                <dd>{etiquetaRol(usuario.rol)}</dd>
              </dl>
              <CamposPerfil usuario={usuario} campos={campos} deshabilitado={guardando} />
              {resultado && <Aviso tipo={resultado.tipo}>{resultado.texto}</Aviso>}
              <button type="submit" className="btn btn--bronze" disabled={guardando}>
                <Save size={16} aria-hidden="true" /> {guardando ? 'Guardando…' : 'Guardar cambios'}
              </button>
            </form>
          </div>
          <CambiarPassword />
        </div>
      )}
    </section>
  );
}
