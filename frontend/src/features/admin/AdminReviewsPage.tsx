import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Eye, EyeOff, Trash2 } from 'lucide-react';
import { api } from '../../lib/api';
import type { EstadoResena, Resena } from '../../lib/api';
import { mensajeError } from '../../lib/errors';
import { fechaHora } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { Aviso, Cargando, ErrorCarga, Paginacion, Vacio } from '../../components/ui';
import { Estrellas } from '../reviews/ReviewsSection';

/** Moderación de reseñas (DEC-B44): por defecto, las ocultas (las de visitantes esperan aprobación). */
export default function AdminReviewsPage() {
  const [params, setParams] = useSearchParams();
  const estadoParam = params.get('estado') ?? 'oculta';
  const estado: EstadoResena | '' = estadoParam === 'visible' || estadoParam === 'oculta' ? estadoParam : '';
  const page = Math.max(1, Number(params.get('page')) || 1);
  const { estado: carga, datos, recargar } = useApi((signal) => api.reviews({ page, per_page: 20, estado }, signal), [page, estado]);
  const [ocupado, setOcupado] = useState<string | null>(null);
  const [error, setError] = useState('');

  const cambiar = (cambio: Record<string, string>) => {
    const next = new URLSearchParams(params);
    Object.entries({ page: '1', ...cambio }).forEach(([k, v]) => (v && v !== '1' ? next.set(k, v) : next.delete(k)));
    setParams(next, { replace: true });
  };

  const accion = async (r: Resena, tarea: () => Promise<unknown>) => {
    setOcupado(r.id);
    setError('');
    try {
      await tarea();
      recargar();
    } catch (e) {
      setError(mensajeError(e));
    } finally {
      setOcupado(null);
    }
  };

  return (
    <>
      <div className="app-panel-head">
        <div>
          <h1>Reseñas</h1>
          <p>Las de visitantes llegan ocultas. Ocultar es lo normal; borra solo spam o contenido ofensivo.</p>
        </div>
      </div>
      <div className="app-filters">
        <div className="app-field">
          <label htmlFor="r-estado">Estado</label>
          <select id="r-estado" value={estado || 'todas'} onChange={(e) => cambiar({ estado: e.target.value })}>
            <option value="oculta">Ocultas (por revisar)</option>
            <option value="visible">Visibles</option>
            <option value="todas">Todas</option>
          </select>
        </div>
      </div>
      {datos && datos.resumen.promedio !== null && (
        <p className="app-hint">
          Promedio público: {datos.resumen.promedio} de 5 ({datos.resumen.calificaciones} calificaciones visibles).
        </p>
      )}
      {error && <Aviso tipo="error">{error}</Aviso>}
      {carga.status === 'loading' && !datos && <Cargando />}
      {carga.status === 'error' && <ErrorCarga error={carga.error} onRetry={recargar} />}
      {datos && datos.data.length === 0 && <Vacio titulo="No hay reseñas con ese filtro" />}
      {datos && datos.data.length > 0 && (
        <div className="app-table-wrap">
          <table className="app-table">
            <thead>
              <tr>
                <th scope="col">Reseña</th>
                <th scope="col">Autor</th>
                <th scope="col">Fecha</th>
                <th scope="col">Estado</th>
                <th scope="col">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {datos.data.map((r) => (
                <tr key={r.id}>
                  <td>
                    <p className="app-pre">{r.comentario}</p>
                    {r.calificacion !== null && <Estrellas valor={r.calificacion} />}
                  </td>
                  <td>
                    {r.nombre_visible}
                    <br />
                    <span className="app-hint">{r.usuario_id ? 'Integrante' : 'Visitante'}</span>
                  </td>
                  <td>{fechaHora(r.fecha)}</td>
                  <td>
                    <span className={`app-tag ${r.estado === 'visible' ? 'app-tag--ok' : 'app-tag--warn'}`}>{r.estado === 'visible' ? 'Visible' : 'Oculta'}</span>
                  </td>
                  <td>
                    <div className="app-actions">
                      {r.estado === 'oculta' ? (
                        <button type="button" className="btn btn--navy btn--sm" disabled={ocupado === r.id} onClick={() => void accion(r, () => api.updateReview(r.id, 'visible'))}>
                          <Eye size={14} aria-hidden="true" /> Publicar
                        </button>
                      ) : (
                        <button type="button" className="btn btn--bronze btn--sm" disabled={ocupado === r.id} onClick={() => void accion(r, () => api.updateReview(r.id, 'oculta'))}>
                          <EyeOff size={14} aria-hidden="true" /> Ocultar
                        </button>
                      )}
                      <button
                        type="button"
                        className="btn btn--danger btn--sm"
                        disabled={ocupado === r.id}
                        onClick={() => {
                          if (window.confirm('¿Borrar esta reseña para siempre?')) void accion(r, () => api.deleteReview(r.id));
                        }}
                      >
                        <Trash2 size={14} aria-hidden="true" /> Borrar
                      </button>
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
