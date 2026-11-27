import { Link } from 'react-router-dom';
import { FilePlus2, FileText, Pencil } from 'lucide-react';
import { api } from '../../lib/api';
import type { DocumentoResumen } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { ETIQUETA_CATEGORIA, fecha, moduloDeCategoria, nombrePersona, rutaDocumento } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { Cargando, ErrorCarga, Vacio } from '../../components/ui';
import { EstadoBadge } from '../content/DocumentCard';

type Fila = DocumentoResumen & { comoAutor: boolean };

/** Todo lo del usuario, como autor o como integrante, también sus borradores (DEC-B36). */
async function misDocumentos(id: string, signal: AbortSignal): Promise<Fila[]> {
  const [pa, pi, aa, ai] = await Promise.all([
    api.documents('projects', { autor: id, per_page: 50 }, signal),
    api.documents('projects', { integrante: id, per_page: 50 }, signal),
    api.documents('articles', { autor: id, per_page: 50 }, signal),
    api.documents('articles', { integrante: id, per_page: 50 }, signal),
  ]);
  const filas = new Map<string, Fila>();
  [...pi.data, ...ai.data].forEach((d) => filas.set(d.id, { ...d, comoAutor: false }));
  [...pa.data, ...aa.data].forEach((d) => filas.set(d.id, { ...d, comoAutor: true }));
  return [...filas.values()].sort((a, b) => b.fecha_actualizacion.localeCompare(a.fecha_actualizacion));
}

export default function MyContentPage() {
  const { usuario } = useAuth();
  const id = usuario?.id ?? '';
  const { estado, recargar } = useApi((signal) => misDocumentos(id, signal), [id]);

  return (
    <section className="container app-page">
      <div className="app-panel-head">
        <div>
          <h1>Mis contenidos</h1>
          <p>Lo que creas queda en revisión hasta que un revisor lo publique. Puedes editarlo mientras siga en revisión.</p>
        </div>
        <div className="app-actions">
          <Link className="btn btn--navy" to="/mis-contenidos/nuevo/proyecto">
            <FilePlus2 size={16} aria-hidden="true" /> Nuevo proyecto
          </Link>
          <Link className="btn btn--navy" to="/mis-contenidos/nuevo/articulo">
            <FilePlus2 size={16} aria-hidden="true" /> Nuevo artículo
          </Link>
          <Link className="btn btn--navy" to="/mis-contenidos/nuevo/noticia">
            <FilePlus2 size={16} aria-hidden="true" /> Nueva noticia
          </Link>
        </div>
      </div>

      {estado.status === 'loading' && <Cargando />}
      {estado.status === 'error' && <ErrorCarga error={estado.error} onRetry={recargar} />}
      {estado.status === 'ready' && estado.data.length === 0 && (
        <Vacio icono={<FileText size={30} aria-hidden="true" />} titulo="Aún no tienes contenidos">
          Crea tu primer proyecto, artículo o noticia con los botones de arriba.
        </Vacio>
      )}
      {estado.status === 'ready' && estado.data.length > 0 && (
        <div className="app-table-wrap">
          <table className="app-table">
            <thead>
              <tr>
                <th scope="col">Título</th>
                <th scope="col">Tipo</th>
                <th scope="col">Estado</th>
                <th scope="col">Participación</th>
                <th scope="col">Actualizado</th>
                <th scope="col">
                  <span className="visually-hidden">Acciones</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {estado.data.map((d) => {
                const modulo = moduloDeCategoria(d.categoria);
                return (
                  <tr key={d.id}>
                    <td>
                      <Link to={rutaDocumento(modulo, d.id)}>{d.titulo}</Link>
                    </td>
                    <td>{ETIQUETA_CATEGORIA[d.categoria]}</td>
                    <td>
                      <EstadoBadge estado={d.estado} visible={d.visible} />
                    </td>
                    <td>{d.comoAutor ? 'Autor' : `Integrante (autor: ${nombrePersona(d.autor)})`}</td>
                    <td>{fecha(d.fecha_actualizacion)}</td>
                    <td>
                      {/* El autor edita solo en revisión (DEC-B34); el editor vuelve a comprobar `permisos`. */}
                      {d.comoAutor && d.estado === 'en_revision' && (
                        <Link className="btn btn--ghost btn--sm" to={`/mis-contenidos/${modulo}/${encodeURIComponent(d.id)}/editar`}>
                          <Pencil size={14} aria-hidden="true" /> Editar
                        </Link>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
