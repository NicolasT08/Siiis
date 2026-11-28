import { useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Check, Download, UserPlus, X } from 'lucide-react';
import { AREAS_INTERES } from '../../config/sitio';
import { api } from '../../lib/api';
import type { EstadoSolicitud, InscripcionDetalle } from '../../lib/api';
import { mensajeError } from '../../lib/errors';
import { ETIQUETA_SOLICITUD, fechaHora } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { Aviso, Cargando, ErrorCarga, Paginacion, Vacio } from '../../components/ui';
import type { Precarga } from './AdminUserFormPage';

const TAG: Record<EstadoSolicitud, string> = { pendiente: 'app-tag--warn', aceptada: 'app-tag--ok', rechazada: 'app-tag--bad' };

export function AdminInscriptionsPage() {
  const [params, setParams] = useSearchParams();
  const estadoParam = params.get('estado') ?? 'pendiente';
  const estado: EstadoSolicitud | '' = ['pendiente', 'aceptada', 'rechazada'].includes(estadoParam) ? (estadoParam as EstadoSolicitud) : '';
  const page = Math.max(1, Number(params.get('page')) || 1);
  const { estado: carga, datos, recargar } = useApi((signal) => api.inscriptions({ page, per_page: 20, estado_solicitud: estado }, signal), [page, estado]);

  const cambiar = (cambio: Record<string, string>) => {
    const next = new URLSearchParams(params);
    Object.entries({ page: '1', ...cambio }).forEach(([k, v]) => (v && v !== '1' ? next.set(k, v) : next.delete(k)));
    setParams(next, { replace: true });
  };

  return (
    <>
      <div className="app-panel-head">
        <div>
          <h1>Inscripciones</h1>
          <p>Aceptar no crea la cuenta: después crea el usuario con los datos de la solicitud. Las rechazadas se borran a mano a los 6 meses.</p>
        </div>
      </div>
      <div className="app-filters">
        <div className="app-field">
          <label htmlFor="i-estado">Estado</label>
          <select id="i-estado" value={estado || 'todas'} onChange={(e) => cambiar({ estado: e.target.value })}>
            <option value="pendiente">Pendientes</option>
            <option value="aceptada">Aceptadas</option>
            <option value="rechazada">Rechazadas</option>
            <option value="todas">Todas</option>
          </select>
        </div>
      </div>
      {carga.status === 'loading' && !datos && <Cargando />}
      {carga.status === 'error' && <ErrorCarga error={carga.error} onRetry={recargar} />}
      {datos && datos.data.length === 0 && <Vacio titulo="No hay solicitudes con ese filtro" />}
      {datos && datos.data.length > 0 && (
        <div className="app-table-wrap">
          <table className="app-table">
            <thead>
              <tr>
                <th scope="col">Aspirante</th>
                <th scope="col">Correo</th>
                <th scope="col">Programa</th>
                <th scope="col">Semestre</th>
                <th scope="col">Fecha</th>
                <th scope="col">Estado</th>
              </tr>
            </thead>
            <tbody>
              {datos.data.map((i) => (
                <tr key={i.id}>
                  <td>
                    <Link to={`/admin/inscripciones/${encodeURIComponent(i.id)}`}>{i.nombre_completo}</Link>
                  </td>
                  <td>{i.correo}</td>
                  <td>{i.programa_academico}</td>
                  <td>{i.semestre}</td>
                  <td>{fechaHora(i.fecha_solicitud)}</td>
                  <td>
                    <span className={`app-tag ${TAG[i.estado_solicitud]}`}>{ETIQUETA_SOLICITUD[i.estado_solicitud]}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Paginacion meta={datos?.meta ?? null} onPage={(p) => cambiar({ page: String(p) })} />
    </>
  );
}

/** Nombre y apellido a partir de `nombre_completo` (la primera mitad de las palabras como nombre). */
function separarNombre(completo: string): { nombre: string; apellido: string } {
  const partes = completo.trim().split(/\s+/);
  if (partes.length === 1) return { nombre: partes[0], apellido: '' };
  const corte = Math.ceil(partes.length / 2);
  return { nombre: partes.slice(0, corte).join(' '), apellido: partes.slice(corte).join(' ') };
}

export function AdminInscriptionDetailPage() {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const { estado, recargar } = useApi((signal) => api.inscription(id, signal), [id]);
  const [actual, setActual] = useState<InscripcionDetalle | null>(null);
  const [ocupado, setOcupado] = useState(false);
  const [aviso, setAviso] = useState<{ tipo: 'ok' | 'error'; texto: string } | null>(null);
  const ins = actual ?? (estado.status === 'ready' ? estado.data.inscripcion : null);

  const resolver = async (decision: 'aceptada' | 'rechazada') => {
    if (!ins) return;
    if (!window.confirm(`¿${decision === 'aceptada' ? 'Aceptar' : 'Rechazar'} la solicitud de ${ins.nombre_completo}? Se le avisará por correo y no se puede deshacer.`)) return;
    setOcupado(true);
    setAviso(null);
    try {
      const { inscripcion } = await api.resolveInscription(ins.id, decision);
      setActual(inscripcion);
      setAviso({ tipo: 'ok', texto: decision === 'aceptada' ? 'Solicitud aceptada. Ahora crea la cuenta del nuevo integrante.' : 'Solicitud rechazada.' });
    } catch (e) {
      setAviso({ tipo: 'error', texto: mensajeError(e) });
    } finally {
      setOcupado(false);
    }
  };

  const crearCuenta = () => {
    if (!ins) return;
    const precarga: Precarga = {
      correo: ins.correo,
      ...separarNombre(ins.nombre_completo),
      telefono: ins.telefono,
      programa_academico: ins.programa_academico,
      semestre: ins.semestre,
    };
    navigate('/admin/usuarios/nuevo', { state: { precarga } });
  };

  return (
    <>
      <Link to="/admin/inscripciones" className="project-detail__back">
        <ArrowLeft size={16} aria-hidden="true" /> Inscripciones
      </Link>
      {estado.status === 'loading' && <Cargando />}
      {estado.status === 'error' && <ErrorCarga error={estado.error} onRetry={recargar} />}
      {ins && (
        <div className="app-stack">
          <div className="app-card">
            <div className="app-panel-head">
              <div>
                <h1>{ins.nombre_completo}</h1>
                <p>Solicitud del {fechaHora(ins.fecha_solicitud)}</p>
              </div>
              <span className={`app-tag ${TAG[ins.estado_solicitud]}`}>{ETIQUETA_SOLICITUD[ins.estado_solicitud]}</span>
            </div>
            <dl className="app-dl">
              <dt>Correo</dt>
              <dd>
                <a href={`mailto:${ins.correo}`}>{ins.correo}</a>
              </dd>
              <dt>Teléfono</dt>
              <dd>{ins.telefono ?? '—'}</dd>
              <dt>Programa</dt>
              <dd>{ins.programa_academico}</dd>
              <dt>Semestre</dt>
              <dd>{ins.semestre}</dd>
              <dt>Código estudiantil</dt>
              <dd>{ins.codigo_estudiantil ?? '—'}</dd>
              <dt>Áreas de interés</dt>
              <dd>
                {ins.areas_interes?.length
                  ? ins.areas_interes.map((a) => AREAS_INTERES.find((x) => x.value === a)?.label ?? a).join(', ')
                  : '—'}
              </dd>
            </dl>
            <h2>Carta de motivación</h2>
            <p className="app-pre">{ins.carta_motivacion}</p>
          </div>

          <div className="app-card">
            <h2>Documentos (hoja de vida)</h2>
            <p className="app-hint">Los enlaces son privados y vencen en 1 hora; si vencen, recarga la página para obtener unos nuevos.</p>
            {ins.documentos_adjuntos.length === 0 && <p className="app-hint">El aspirante no adjuntó la hoja de vida ni otros documentos (son opcionales).</p>}
            <ul className="app-files">
              {ins.documentos_adjuntos.map((d, i) => (
                <li key={`${d.nombre_archivo}-${i}`}>
                  {d.url ? (
                    <a className="btn btn--ghost" href={d.url} target="_blank" rel="noopener noreferrer">
                      <Download size={16} aria-hidden="true" /> {d.nombre_archivo}
                    </a>
                  ) : (
                    <span>{d.nombre_archivo}</span>
                  )}
                </li>
              ))}
            </ul>
            <button type="button" className="btn btn--ghost btn--sm" onClick={() => recargar()}>
              Renovar enlaces
            </button>
          </div>

          {aviso && <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>}
          <div className="app-actions">
            {ins.estado_solicitud === 'pendiente' && (
              <>
                <button type="button" className="btn btn--navy" disabled={ocupado} onClick={() => void resolver('aceptada')}>
                  <Check size={16} aria-hidden="true" /> Aceptar
                </button>
                <button type="button" className="btn btn--danger" disabled={ocupado} onClick={() => void resolver('rechazada')}>
                  <X size={16} aria-hidden="true" /> Rechazar
                </button>
              </>
            )}
            {ins.estado_solicitud === 'aceptada' && (
              <button type="button" className="btn btn--bronze" onClick={crearCuenta}>
                <UserPlus size={16} aria-hidden="true" /> Crear cuenta con estos datos
              </button>
            )}
          </div>
        </div>
      )}
    </>
  );
}
