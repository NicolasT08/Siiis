import { Link } from 'react-router-dom';
import { etiquetaRol } from '../../lib/format';
import { nombreCompleto, useAuth } from '../../lib/auth';
import { seccionesAdmin } from './secciones';

export default function AdminHomePage() {
  const { usuario } = useAuth();
  if (!usuario) return null;
  return (
    <>
      <div className="app-panel-head">
        <div>
          <h1>Administración</h1>
          <p>
            {nombreCompleto(usuario)} · {etiquetaRol(usuario.rol)}. Solo ves las secciones que tu rol permite.
          </p>
        </div>
      </div>
      <div className="app-dash">
        {seccionesAdmin(usuario.rol).map((s) => (
          <Link key={s.to} to={s.to}>
            <strong>{s.label}</strong>
            <span>{s.texto}</span>
          </Link>
        ))}
      </div>
    </>
  );
}
