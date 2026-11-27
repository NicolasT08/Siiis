import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Pencil, UserCheck, UserPlus, UserX } from 'lucide-react';
import { api, esVistaAdmin, ROLES } from '../../lib/api';
import type { Rol, UsuarioAdmin } from '../../lib/api';
import { puedeGestionar, useAuth } from '../../lib/auth';
import { mensajeError } from '../../lib/errors';
import { etiquetaRol, fecha, nombrePersona } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { Aviso, Cargando, ErrorCarga, Paginacion, Vacio } from '../../components/ui';

export default function AdminUsersPage() {
  const { usuario: actor } = useAuth();
  const [params, setParams] = useSearchParams();
  const page = Math.max(1, Number(params.get('page')) || 1);
  const rolParam = params.get('rol') ?? '';
  const rol = (ROLES as readonly string[]).includes(rolParam) ? (rolParam as Rol) : '';
  const estadoParam = params.get('estado');
  const estado = estadoParam === 'true' ? true : estadoParam === 'false' ? false : '';
  const { estado: carga, datos, recargar } = useApi((signal) => api.users({ page, per_page: 20, rol, estado }, signal), [page, rol, estado]);
  const [ocupado, setOcupado] = useState<string | null>(null);
  const [aviso, setAviso] = useState<{ tipo: 'ok' | 'error'; texto: string } | null>(null);

  const cambiar = (cambio: Record<string, string>) => {
    const next = new URLSearchParams(params);
    Object.entries({ page: '1', ...cambio }).forEach(([k, v]) => (v && v !== '1' ? next.set(k, v) : next.delete(k)));
    setParams(next, { replace: true });
  };

  const alternarEstado = async (u: UsuarioAdmin) => {
    const desactivar = u.estado;
    if (desactivar && !window.confirm(`¿Desactivar a ${nombrePersona(u)}? Se cerrarán sus sesiones y dejará de aparecer en el sitio público.`)) return;
    setOcupado(u.id);
    setAviso(null);
    try {
      if (desactivar) await api.deactivateUser(u.id);
      else await api.updateUser(u.id, { estado: true });
      setAviso({ tipo: 'ok', texto: `${nombrePersona(u)} quedó ${desactivar ? 'inactivo' : 'activo de nuevo'}.` });
      recargar();
    } catch (e) {
      setAviso({ tipo: 'error', texto: mensajeError(e) });
    } finally {
      setOcupado(null);
    }
  };

  const filas = (datos?.data ?? []).filter(esVistaAdmin);

  return (
    <>
      <div className="app-panel-head">
        <div>
          <h1>Usuarios</h1>
          <p>Desactivar no borra a nadie: la persona deja de entrar y de aparecer en el sitio, y se puede reactivar.</p>
        </div>
        <Link className="btn btn--bronze" to="/admin/usuarios/nuevo">
          <UserPlus size={16} aria-hidden="true" /> Crear usuario
        </Link>
      </div>

      <div className="app-filters">
        <div className="app-field">
          <label htmlFor="f-rol">Rol</label>
          <select id="f-rol" value={rol} onChange={(e) => cambiar({ rol: e.target.value })}>
            <option value="">Todos</option>
            {ROLES.map((r) => (
              <option key={r} value={r}>
                {etiquetaRol(r)}
              </option>
            ))}
          </select>
        </div>
        <div className="app-field">
          <label htmlFor="f-estado">Estado</label>
          <select id="f-estado" value={estadoParam ?? ''} onChange={(e) => cambiar({ estado: e.target.value })}>
            <option value="">Activos e inactivos</option>
            <option value="true">Activos</option>
            <option value="false">Inactivos</option>
          </select>
        </div>
      </div>

      {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>}
      {carga.status === 'loading' && !datos && <Cargando />}
      {carga.status === 'error' && <ErrorCarga error={carga.error} onRetry={recargar} />}
      {datos && filas.length === 0 && <Vacio titulo="No hay usuarios con esos filtros" />}
      {filas.length > 0 && (
        <div className="app-table-wrap">
          <table className="app-table">
            <thead>
              <tr>
                <th scope="col">Nombre</th>
                <th scope="col">Correo</th>
                <th scope="col">Rol</th>
                <th scope="col">Estado</th>
                <th scope="col">Último acceso</th>
                <th scope="col">
                  <span className="visually-hidden">Acciones</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {filas.map((u) => {
                const propio = u.id === actor?.id;
                const gestionable = !propio && puedeGestionar(actor?.rol, u.rol);
                return (
                  <tr key={u.id}>
                    <td>
                      <Link to={`/usuarios/${encodeURIComponent(u.id)}`}>{nombrePersona(u)}</Link>
                    </td>
                    <td>{u.correo}</td>
                    <td>{etiquetaRol(u.rol)}</td>
                    <td>
                      <span className={`app-tag ${u.estado ? 'app-tag--ok' : 'app-tag--bad'}`}>{u.estado ? 'Activo' : 'Inactivo'}</span>
                    </td>
                    <td>{u.ultimo_acceso ? fecha(u.ultimo_acceso) : 'Nunca'}</td>
                    <td>
                      <div className="app-actions">
                        {(gestionable || propio) && (
                          <Link className="btn btn--ghost btn--sm" to={propio ? '/mi-perfil' : `/admin/usuarios/${encodeURIComponent(u.id)}`}>
                            <Pencil size={14} aria-hidden="true" /> Editar
                          </Link>
                        )}
                        {gestionable && (
                          <button
                            type="button"
                            className={`btn btn--sm ${u.estado ? 'btn--danger' : 'btn--navy'}`}
                            disabled={ocupado === u.id}
                            onClick={() => void alternarEstado(u)}
                          >
                            {u.estado ? <UserX size={14} aria-hidden="true" /> : <UserCheck size={14} aria-hidden="true" />}
                            {u.estado ? 'Desactivar' : 'Reactivar'}
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
      <Paginacion meta={datos?.meta ?? null} onPage={(p) => cambiar({ page: String(p) })} />
    </>
  );
}
