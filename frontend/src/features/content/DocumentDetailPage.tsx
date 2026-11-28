import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Calendar, FileSearch, FileText, Image as ImageIcon, Pencil, User } from 'lucide-react';
import ProjectsHero from '../projects/components/ProjectsHero';
import { api } from '../../lib/api';
import type { Modulo } from '../../lib/api';
import { esNoEncontrado } from '../../lib/errors';
import { fecha, nombrePersona } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { useAuth } from '../../lib/auth';
import { esAdjuntoPdf, pdfPrincipal } from '../../lib/documentoPdf';
import { ErrorCarga, Vacio } from '../../components/ui';
import AccionesRevision from './AccionesRevision';
import DocumentoContenido from './DocumentoContenido';
import { CategoriaChip, EstadoBadge, PersonaAvatar } from './DocumentCard';
import '../projects/projects.css';
import { imagenCloudinary } from '../../lib/cloudinary';

const esImagen = (url: string) => /\.(jpe?g|png|webp|gif)(\?|$)/i.test(url) || url.includes('/image/upload/') && !/\.pdf(\?|$)/i.test(url);

export default function DocumentDetailPage({ modulo }: { modulo: Modulo }) {
  const { id = '' } = useParams();
  const { usuario } = useAuth();
  const navigate = useNavigate();
  const listado = modulo === 'projects' ? '/proyectos' : '/articulos';
  const { estado, recargar } = useApi((signal) => api.document(modulo, id, signal), [modulo, id, usuario?.id]);
  const doc = estado.status === 'ready' ? estado.data.documento : null;
  // DEC-B70: el contenido es el texto Markdown, el PDF principal (primer PDF adjunto) o ambos; se muestra primero
  // el texto y después el PDF, cada uno solo si existe. Debajo van las imágenes y, si hay, otros archivos.
  const pdf = doc ? pdfPrincipal(doc.archivos_adjuntos) : null;
  const extras = doc ? doc.archivos_adjuntos.filter((a) => a.archivo !== pdf?.archivo) : [];
  const imagenes = extras.filter((a) => !esAdjuntoPdf(a) && esImagen(a.url));
  const otros = extras.filter((a) => !imagenes.includes(a));

  return (
    <div className="projects-page">
      <ProjectsHero title={doc?.titulo ?? (modulo === 'projects' ? 'Proyecto' : 'Publicación')} compact />
      <section className="container project-detail">
        <Link to={listado} className="project-detail__back">
          <ArrowLeft size={16} aria-hidden="true" /> Volver a {modulo === 'projects' ? 'proyectos' : 'artículos y noticias'}
        </Link>

        {estado.status === 'loading' && <div className="skeleton project-detail__skeleton" aria-label="Cargando" />}
        {estado.status === 'error' &&
          (esNoEncontrado(estado.error) ? (
            <Vacio icono={<FileSearch size={30} aria-hidden="true" />} titulo="No encontramos esta publicación">
              Puede que se haya retirado, que todavía esté en revisión o que el enlace no sea correcto.
            </Vacio>
          ) : (
            <ErrorCarga error={estado.error} onRetry={recargar} />
          ))}

        {doc && (
          <article className="project-detail__card">
            <div className="project-detail__body">
              <div className="project-detail__tags">
                <CategoriaChip categoria={doc.categoria} />
                <EstadoBadge estado={doc.estado} visible={doc.visible} />
              </div>
              <h2 className="project-detail__title">{doc.titulo}</h2>
              <ul className="project-detail__meta">
                <li>
                  <User size={16} aria-hidden="true" /> {nombrePersona(doc.autor)}
                </li>
                <li>
                  <Calendar size={16} aria-hidden="true" />
                  <time dateTime={doc.fecha_creacion}>{fecha(doc.fecha_creacion)}</time>
                  {doc.fecha_actualizacion !== doc.fecha_creacion && <> · actualizado {fecha(doc.fecha_actualizacion)}</>}
                </li>
              </ul>

              {(doc.permisos.editar || doc.permisos.borrar || doc.permisos.publicar) && (
                <div className="app-toolbar" aria-label="Acciones">
                  {doc.permisos.editar && (
                    <Link className="btn btn--navy" to={`/mis-contenidos/${modulo}/${encodeURIComponent(doc.id)}/editar`}>
                      <Pencil size={16} aria-hidden="true" /> Editar
                    </Link>
                  )}
                  <AccionesRevision
                    modulo={modulo}
                    documento={doc}
                    puedePublicar={doc.permisos.publicar}
                    puedeBorrar={doc.permisos.borrar}
                    onCambio={(nuevo) => (nuevo ? recargar() : navigate(listado, { replace: true }))}
                  />
                </div>
              )}

              <p className="project-detail__summary">{doc.descripcion}</p>

              <DocumentoContenido documento={doc} />

              {doc.integrantes.length > 0 && (
                <section className="app-section" aria-labelledby="integrantes-titulo">
                  <h3 id="integrantes-titulo">Integrantes</h3>
                  <ul className="app-people">
                    {doc.integrantes.map((p) => (
                      <li key={p.id}>
                        <Link to={`/usuarios/${encodeURIComponent(p.id)}`}>
                          <PersonaAvatar persona={p} />
                          {nombrePersona(p)}
                        </Link>
                      </li>
                    ))}
                  </ul>
                </section>
              )}

              {imagenes.length > 0 && (
                <section className="app-section" aria-labelledby="imagenes-titulo">
                  <h3 id="imagenes-titulo">
                    <ImageIcon size={18} aria-hidden="true" /> Imágenes
                  </h3>
                  <ul className="app-files">
                    {imagenes.map((a) => (
                      <li key={a.archivo}>
                        <a href={a.url} target="_blank" rel="noopener noreferrer">
                          <img src={imagenCloudinary(a.url, 640)} alt={a.nombre_archivo} loading="lazy" decoding="async" />
                        </a>
                      </li>
                    ))}
                  </ul>
                </section>
              )}

              {otros.length > 0 && (
                <section className="app-section" aria-labelledby="otros-titulo">
                  <h3 id="otros-titulo">
                    <FileText size={18} aria-hidden="true" /> Otros archivos
                  </h3>
                  <ul className="app-files">
                    {otros.map((a) => (
                      <li key={a.archivo}>
                        <a href={a.url} target="_blank" rel="noopener noreferrer">
                          <span>
                            <FileText size={16} aria-hidden="true" /> {a.nombre_archivo}
                          </span>
                        </a>
                      </li>
                    ))}
                  </ul>
                </section>
              )}
            </div>
          </article>
        )}
      </section>
    </div>
  );
}
