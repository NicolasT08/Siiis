import { Link, useSearchParams } from 'react-router-dom';
import { Pencil } from 'lucide-react';
import { api, ESTADOS_POR_MODULO } from '../../lib/api';
import type { EstadoDocumento, Modulo } from '../../lib/api';
import { ETIQUETA_CATEGORIA, ETIQUETA_ESTADO, fecha, nombrePersona, rutaDocumento } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { Cargando, ErrorCarga, Paginacion, Vacio } from '../../components/ui';
import AccionesRevision from '../content/AccionesRevision';
import { EstadoBadge } from '../content/DocumentCard';

/**
 * Contenido para revisores (DEC-B33, B36): por defecto lo que está en revisión. Los revisores ven todo y pueden
 * filtrar por `visible`; publicar, ocultar, devolver a revisión y borrar usan los mismos botones del detalle.
 */
export default function AdminContentPage() {
  const [params, setParams] = useSearchParams();
  const modulo: Modulo = params.get('modulo') === 'articles' ? 'articles' : 'projects';
  const estadoParam = params.get('estado') ?? 'en_revision';
  const estado = ESTADOS_POR_MODULO[modulo].includes(estadoParam as EstadoDocumento) ? (estadoParam as EstadoDocumento) : '';
  const visibleParam = params.get('visible');
  const visible = visibleParam === 'true' ? true : visibleParam === 'false' ? false : '';
  const page = Math.max(1, Number(params.get('page')) || 1);
  const { estado: carga, datos, recargar } = useApi((signal) => api.documents(modulo, { page, per_page: 20, estado, visible }, signal), [modulo, page, estado, visible]);

  const cambiar = (cambio: Record<string, string>) => {
    const next = new URLSearchParams(params);
    Object.entries({ page: '1', ...cambio }).forEach(([k, v]) => (v !== '' && v !== '1' ? next.set(k, v) : next.delete(k)));
    setParams(next, { replace: true });
  };

  return (
    <>
      <div className="app-panel-head">
        <div>
          <h1>Contenido</h1>
          <p>Publica lo que está en revisión, oculta lo publicado sin borrarlo o elimínalo definitivamente.</p>
        </div>
      </div>
      <div className="app-filters">
        <div className="app-field">
          <label htmlFor="c-modulo">Tipo</label>
          <select id="c-modulo" value={modulo} onChange={(e) => cambiar({ modulo: e.target.value, estado: 'en_revision' })}>
            <option value="projects">Proyectos</option>
            <option value="articles">Artículos y noticias</option>
          </select>
        </div>
        <div className="app-field">
          <label htmlFor="c-estado">Estado</label>
          {/* "Todos" se guarda como estado=todos para distinguirlo del valor por defecto (en revisión). */}
          <select id="c-estado" value={estado || 'todos'} onChange={(e) => cambiar({ estado: e.target.value })}>
            <option value="todos">Todos</option>
            {ESTADOS_POR_MODULO[modulo].map((e) => (
              <option key={e} value={e}>
                {ETIQUETA_ESTADO[e]}
              </option>
            ))}
          </select>
        </div>
        <div className="app-field">
          <label htmlFor="c-visible">Visibilidad</label>
          <select id="c-visible" value={visibleParam ?? ''} onChange={(e) => cambiar({ visible: e.target.value })}>
            <option value="">Visibles y ocultos</option>
            <option value="true">Visibles</option>
            <option value="false">Ocultos</option>
          </select>
        </div>
      </div>

      {carga.status === 'loading' && !datos && <Cargando />}
      {carga.status === 'error' && <ErrorCarga error={carga.error} onRetry={recargar} />}
      {datos && datos.data.length === 0 && <Vacio titulo="No hay contenido con esos filtros" />}
      {datos && datos.data.length > 0 && (
        <div className="app-table-wrap">
          <table className="app-table">
            <thead>
              <tr>
                <th scope="col">Título</th>
                <th scope="col">Tipo</th>
                <th scope="col">Autor</th>
                <th scope="col">Estado</th>
                <th scope="col">Actualizado</th>
                <th scope="col">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {datos.data.map((d) => (
                <tr key={d.id}>
                  <td>
                    <Link to={rutaDocumento(modulo, d.id)}>{d.titulo}</Link>
                  </td>
                  <td>{ETIQUETA_CATEGORIA[d.categoria]}</td>
                  <td>{nombrePersona(d.autor)}</td>
                  <td>
                    <EstadoBadge estado={d.estado} visible={d.visible} />
                  </td>
                  <td>{fecha(d.fecha_actualizacion)}</td>
                  <td>
                    <div className="app-actions">
                      <Link className="btn btn--ghost btn--sm" to={`/mis-contenidos/${modulo}/${encodeURIComponent(d.id)}/editar`}>
                        <Pencil size={14} aria-hidden="true" /> Editar
                      </Link>
                      <AccionesRevision modulo={modulo} documento={d} puedePublicar puedeBorrar onCambio={() => recargar()} />
                    </div>
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
