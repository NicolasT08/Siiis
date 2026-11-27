import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Mail, MailOpen, Reply } from 'lucide-react';
import { api } from '../../lib/api';
import type { EstadoMensaje, MensajeContacto } from '../../lib/api';
import { mensajeError } from '../../lib/errors';
import { fechaHora } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { Aviso, Cargando, ErrorCarga, Paginacion, Vacio } from '../../components/ui';

/** Buzón de contacto (DEC-B46): leer, marcar leído / no leído y responder por correo. */
export default function AdminMessagesPage() {
  const [params, setParams] = useSearchParams();
  const estadoParam = params.get('estado') ?? '';
  const estado: EstadoMensaje | '' = estadoParam === 'leido' || estadoParam === 'no_leido' ? estadoParam : '';
  const page = Math.max(1, Number(params.get('page')) || 1);
  const { estado: carga, datos, recargar } = useApi((signal) => api.messages({ page, per_page: 20, estado }, signal), [page, estado]);
  const [abierto, setAbierto] = useState<string | null>(null);
  const [error, setError] = useState('');

  const cambiar = (cambio: Record<string, string>) => {
    const next = new URLSearchParams(params);
    Object.entries({ page: '1', ...cambio }).forEach(([k, v]) => (v && v !== '1' ? next.set(k, v) : next.delete(k)));
    setParams(next, { replace: true });
  };

  const marcar = async (m: MensajeContacto, nuevo: EstadoMensaje) => {
    setError('');
    try {
      await api.updateMessage(m.id, nuevo);
      recargar();
    } catch (e) {
      setError(mensajeError(e));
    }
  };

  const abrir = (m: MensajeContacto) => {
    const cerrar = abierto === m.id;
    setAbierto(cerrar ? null : m.id);
    if (!cerrar && m.estado === 'no_leido') void marcar(m, 'leido');
  };

  return (
    <>
      <div className="app-panel-head">
        <div>
          <h1>Mensajes de contacto</h1>
          <p>Al abrir un mensaje se marca como leído. "Responder" abre tu correo con el remitente.</p>
        </div>
      </div>
      <div className="app-filters">
        <div className="app-field">
          <label htmlFor="m-estado">Estado</label>
          <select id="m-estado" value={estado} onChange={(e) => cambiar({ estado: e.target.value })}>
            <option value="">Todos</option>
            <option value="no_leido">No leídos</option>
            <option value="leido">Leídos</option>
          </select>
        </div>
      </div>
      {error && <Aviso tipo="error">{error}</Aviso>}
      {carga.status === 'loading' && !datos && <Cargando />}
      {carga.status === 'error' && <ErrorCarga error={carga.error} onRetry={recargar} />}
      {datos && datos.data.length === 0 && <Vacio titulo="No hay mensajes" />}
      {datos && datos.data.length > 0 && (
        <div className="app-table-wrap">
          <table className="app-table">
            <thead>
              <tr>
                <th scope="col">Asunto</th>
                <th scope="col">Remitente</th>
                <th scope="col">Fecha</th>
                <th scope="col">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {datos.data.map((m) => (
                <tr key={m.id} className={m.estado === 'no_leido' ? 'is-unread' : ''}>
                  <td>
                    <button type="button" className="btn btn--ghost btn--sm" aria-expanded={abierto === m.id} onClick={() => abrir(m)}>
                      {m.estado === 'no_leido' ? <Mail size={14} aria-hidden="true" /> : <MailOpen size={14} aria-hidden="true" />}
                      {m.asunto}
                    </button>
                    {abierto === m.id && <p className="app-pre">{m.mensaje}</p>}
                  </td>
                  <td>
                    {m.nombre}
                    <br />
                    <span className="app-hint">{m.correo}</span>
                  </td>
                  <td>{fechaHora(m.fecha)}</td>
                  <td>
                    <div className="app-actions">
                      <a className="btn btn--navy btn--sm" href={`mailto:${m.correo}?subject=${encodeURIComponent(`Re: ${m.asunto}`)}`}>
                        <Reply size={14} aria-hidden="true" /> Responder
                      </a>
                      <button type="button" className="btn btn--ghost btn--sm" onClick={() => void marcar(m, m.estado === 'leido' ? 'no_leido' : 'leido')}>
                        Marcar {m.estado === 'leido' ? 'no leído' : 'leído'}
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
