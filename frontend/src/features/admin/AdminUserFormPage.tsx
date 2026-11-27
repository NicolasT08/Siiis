import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Save, UserPlus } from 'lucide-react';
import { api, esVistaAdmin } from '../../lib/api';
import type { Rol, UsuarioAdmin } from '../../lib/api';
import { puedeGestionar, rolesAsignables, useAuth } from '../../lib/auth';
import { erroresDeCampo, mensajeError } from '../../lib/errors';
import { etiquetaRol, fecha, nombrePersona } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { Aviso, Cargando, ErrorCampo, ErrorCarga } from '../../components/ui';
import { CamposPerfil, FotoPerfil } from '../account/ProfilePage';
import type { Resultado } from '../account/ProfilePage';
import { leerPerfil } from '../account/perfil';
import { problemaPassword } from '../auth/passwordRules';

/** Datos que llegan desde una inscripción aceptada para precargar la cuenta (DEC-B52). */
export type Precarga = { correo: string; nombre: string; apellido: string; telefono: string | null; programa_academico: string; semestre: number };

function SelectorRol({ actual, asignables, campos }: { actual: Rol; asignables: Rol[]; campos: Record<string, string> }) {
  return (
    <div className="app-field">
      <label htmlFor="perfil-rol">Rol</label>
      <select id="perfil-rol" name="rol" defaultValue={actual} aria-invalid={!!campos.rol} aria-describedby="perfil-rol-e">
        {asignables.map((r) => (
          <option key={r} value={r}>
            {etiquetaRol(r)}
          </option>
        ))}
      </select>
      <ErrorCampo id="perfil-rol-e" mensaje={campos.rol} />
    </div>
  );
}

function CrearUsuario() {
  const { usuario: actor } = useAuth();
  const navigate = useNavigate();
  const precarga = (useLocation().state as { precarga?: Precarga } | null)?.precarga;
  const asignables = rolesAsignables(actor?.rol);
  const [guardando, setGuardando] = useState(false);
  const [resultado, setResultado] = useState<Resultado>(null);
  const [campos, setCampos] = useState<Record<string, string>>({});
  // CamposPerfil usa la forma de UsuarioAdmin para precargar; con una inscripción se rellena con sus datos.
  const base = precarga
    ? ({ ...precarga, biografia: null, foto_perfil: null } as unknown as UsuarioAdmin)
    : null;

  const crear = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const datos = new FormData(event.currentTarget);
    const correo = String(datos.get('correo') ?? '').trim();
    const password = String(datos.get('password') ?? '');
    const errores: Record<string, string> = {};
    if (!correo) errores.correo = 'Este campo es obligatorio.';
    const problema = problemaPassword(password);
    if (problema) errores.password = problema;
    setCampos(errores);
    setResultado(null);
    if (Object.keys(errores).length) return;

    setGuardando(true);
    try {
      const { usuario } = await api.register({ ...leerPerfil(datos), correo, password, rol: String(datos.get('rol')) as Rol });
      navigate(`/admin/usuarios/${encodeURIComponent(usuario.id)}`, { replace: true, state: { creado: true } });
    } catch (e) {
      setCampos(erroresDeCampo(e));
      setResultado({ tipo: 'error', texto: mensajeError(e) });
      setGuardando(false);
    }
  };

  return (
    <form className="app-card app-form" onSubmit={(e) => void crear(e)} noValidate>
      <h2>
        <UserPlus size={20} aria-hidden="true" /> Crear usuario
      </h2>
      {precarga && <Aviso tipo="info">Datos tomados de la solicitud de inscripción. Revísalos y define una contraseña inicial para enviársela al aspirante.</Aviso>}
      <div className="app-form-grid">
        <div className="app-field">
          <label htmlFor="nuevo-correo">Correo</label>
          <input id="nuevo-correo" name="correo" type="email" required defaultValue={precarga?.correo ?? ''} aria-invalid={!!campos.correo} aria-describedby="nuevo-correo-e" />
          <ErrorCampo id="nuevo-correo-e" mensaje={campos.correo} />
        </div>
        <div className="app-field">
          <label htmlFor="nuevo-password">Contraseña inicial</label>
          <input id="nuevo-password" name="password" type="text" autoComplete="new-password" required aria-invalid={!!campos.password} aria-describedby="nuevo-password-e" />
          <span className="app-hint">8+ caracteres con letra y número. La persona puede cambiarla en "Mi perfil".</span>
          <ErrorCampo id="nuevo-password-e" mensaje={campos.password} />
        </div>
        <SelectorRol actual={'estudiante'} asignables={asignables} campos={campos} />
      </div>
      <CamposPerfil usuario={base} campos={campos} deshabilitado={guardando} />
      {resultado && <Aviso tipo={resultado.tipo}>{resultado.texto}</Aviso>}
      <button type="submit" className="btn btn--bronze" disabled={guardando}>
        <Save size={16} aria-hidden="true" /> {guardando ? 'Creando…' : 'Crear usuario'}
      </button>
    </form>
  );
}

function EditarUsuario({ id }: { id: string }) {
  const { usuario: actor } = useAuth();
  const creado = (useLocation().state as { creado?: boolean } | null)?.creado === true;
  const { estado, recargar } = useApi((signal) => api.user(id, signal), [id]);
  const [actual, setActual] = useState<UsuarioAdmin | null>(null);
  const [guardando, setGuardando] = useState(false);
  const [resultado, setResultado] = useState<Resultado>(creado ? { tipo: 'ok', texto: 'Usuario creado. Ya puede iniciar sesión.' } : null);
  const [campos, setCampos] = useState<Record<string, string>>({});

  const cargado = estado.status === 'ready' && esVistaAdmin(estado.data.usuario) ? estado.data.usuario : null;
  const u = actual ?? cargado;

  if (estado.status === 'loading') return <Cargando />;
  if (estado.status === 'error') return <ErrorCarga error={estado.error} onRetry={recargar} />;
  if (!u) return <Aviso tipo="error">No tienes acceso a la vista de administración de este usuario.</Aviso>;
  if (!puedeGestionar(actor?.rol, u.rol) || u.id === actor?.id) {
    return <Aviso tipo="info">Tu rol no puede editar a {nombrePersona(u)} ({etiquetaRol(u.rol)}).</Aviso>;
  }

  const guardar = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const datos = new FormData(event.currentTarget);
    setGuardando(true);
    setResultado(null);
    setCampos({});
    try {
      // Reenviar rol/estado actuales no cuenta como cambio (6e): se manda el formulario completo.
      const { usuario: nuevo } = await api.updateUser(u.id, {
        ...leerPerfil(datos),
        rol: String(datos.get('rol')) as Rol,
        estado: datos.get('estado') === 'on',
      });
      setActual(nuevo);
      setResultado({ tipo: 'ok', texto: 'Cambios guardados. Si cambiaste el rol o el estado, sus sesiones se cerraron.' });
    } catch (e) {
      setCampos(erroresDeCampo(e));
      setResultado({ tipo: 'error', texto: mensajeError(e) });
    } finally {
      setGuardando(false);
    }
  };

  return (
    <div className="app-card app-profile">
      <FotoPerfil usuario={u} onCambio={setActual} />
      <form className="app-form" onSubmit={(e) => void guardar(e)} noValidate key={`${u.id}-${u.rol}-${u.estado}`}>
        <h2>{nombrePersona(u)}</h2>
        <dl className="app-dl">
          <dt>Correo</dt>
          <dd>{u.correo}</dd>
          <dt>Ingreso</dt>
          <dd>{fecha(u.fecha_ingreso) || '—'}</dd>
          {u.fecha_salida && (
            <>
              <dt>Salida</dt>
              <dd>{fecha(u.fecha_salida)}</dd>
            </>
          )}
        </dl>
        <div className="app-form-grid">
          <SelectorRol actual={u.rol} asignables={rolesAsignables(actor?.rol)} campos={campos} />
          <label className="app-check">
            <input type="checkbox" name="estado" defaultChecked={u.estado} /> Activo en el semillero
          </label>
        </div>
        <CamposPerfil usuario={u} campos={campos} deshabilitado={guardando} />
        {resultado && <Aviso tipo={resultado.tipo}>{resultado.texto}</Aviso>}
        <button type="submit" className="btn btn--bronze" disabled={guardando}>
          <Save size={16} aria-hidden="true" /> {guardando ? 'Guardando…' : 'Guardar cambios'}
        </button>
      </form>
    </div>
  );
}

export default function AdminUserFormPage() {
  const { id } = useParams();
  return (
    <>
      <Link to="/admin/usuarios" className="project-detail__back">
        <ArrowLeft size={16} aria-hidden="true" /> Usuarios
      </Link>
      {id ? <EditarUsuario id={id} /> : <CrearUsuario />}
    </>
  );
}
