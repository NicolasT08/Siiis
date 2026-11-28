import { lazy, Suspense, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, BookOpen, FileText, GraduationCap, Newspaper, UserRoundSearch } from 'lucide-react';
import ProjectsHero from '../projects/components/ProjectsHero';
import { api } from '../../lib/api';
import type { DocumentoResumen } from '../../lib/api';
import { esNoEncontrado } from '../../lib/errors';
import { fecha, nombrePersona, rutaDocumento } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { Cargando, ErrorCarga, Vacio } from '../../components/ui';
import DocumentCard from '../content/DocumentCard';
import { abrirVistaPrevia } from '../content/vistaPrevia';
import MemberAvatar from './components/MemberAvatar';
import { etiquetaRol, nombreMiembro } from './memberUtils';
import '../projects/projects.css';
import './users.css';

// La vista previa trae el renderizador de Markdown: se descarga solo al abrir una publicación (RNF-06).
const VistaPreviaDocumento = lazy(() => import('../content/VistaPreviaDocumento'));

/** Cuántos artículos de otros integrantes se muestran en la columna lateral (RF-05). */
const ARTICULOS_INTERES = 5;

/** Publicaciones de un integrante como autor o integrante (RF-05), sin repetidos, de la más reciente a la más antigua. */
async function publicaciones(id: string, signal: AbortSignal): Promise<DocumentoResumen[]> {
  const respuestas = await Promise.all([
    api.documents('projects', { autor: id, per_page: 50 }, signal),
    api.documents('projects', { integrante: id, per_page: 50 }, signal),
    api.documents('articles', { autor: id, per_page: 50 }, signal),
    api.documents('articles', { integrante: id, per_page: 50 }, signal),
  ]);
  const porId = new Map<string, DocumentoResumen>();
  respuestas.flatMap((r) => r.data).forEach((d) => porId.set(d.id, d));
  return [...porId.values()].sort((a, b) => b.fecha_creacion.localeCompare(a.fecha_creacion));
}

/**
 * Artículos de interés (RF-05): los últimos artículos publicados en los que el integrante no es autor ni integrante.
 * Se piden algunos más de los que se muestran porque se descartan los suyos.
 */
async function articulosDeInteres(id: string, signal: AbortSignal): Promise<DocumentoResumen[]> {
  const { data } = await api.documents('articles', { categoria: 'articulo', estado: 'publicado', per_page: 20 }, signal);
  return data
    .filter((d) => d.visible && d.autor?.id !== id && !d.integrantes.some((p) => p.id === id))
    .slice(0, ARTICULOS_INTERES);
}

export default function MemberDetailPage() {
  const { id = '' } = useParams();
  const perfil = useApi((signal) => api.member(id, signal), [id]);
  const docs = useApi((signal) => publicaciones(id, signal), [id]);
  const interes = useApi((signal) => articulosDeInteres(id, signal), [id]);
  const [previa, setPrevia] = useState<DocumentoResumen | null>(null);
  const miembro = perfil.estado.status === 'ready' ? perfil.estado.data.usuario : null;

  return (
    <div className="projects-page users-page">
      <ProjectsHero title={miembro ? nombreMiembro(miembro) : 'Perfil'} compact />
      <section className="container member-profile">
        <Link to="/usuarios" className="project-detail__back">
          <ArrowLeft size={16} aria-hidden="true" /> Volver a integrantes
        </Link>
        {perfil.estado.status === 'loading' && <div className="skeleton member-profile__skeleton" aria-label="Cargando perfil" />}
        {perfil.estado.status === 'error' &&
          (esNoEncontrado(perfil.estado.error) ? (
            <Vacio icono={<UserRoundSearch size={30} aria-hidden="true" />} titulo="No encontramos a este integrante">
              Puede que el perfil se haya retirado o que el enlace no sea correcto.
            </Vacio>
          ) : (
            <ErrorCarga error={perfil.estado.error} onRetry={perfil.recargar} titulo="No pudimos cargar este perfil" />
          ))}
        {miembro && (
          <div className="member-profile__layout">
            <div className="member-profile__main">
              <article className="member-profile__card">
                <div className="member-profile__photo">
                  <MemberAvatar miembro={miembro} className="member-profile__img" />
                </div>
                <div className="member-profile__body">
                  <div className="member-profile__tags">
                    <span className="member-profile__role">{etiquetaRol(miembro.rol)}</span>
                  </div>
                  <h2 className="member-profile__name">{nombreMiembro(miembro)}</h2>
                  <span className="member-card__bar" />
                  <dl className="member-profile__facts">
                    {miembro.programa_academico && (
                      <div>
                        <dt>
                          <GraduationCap size={16} aria-hidden="true" /> Programa
                        </dt>
                        <dd>{miembro.programa_academico}</dd>
                      </div>
                    )}
                    {miembro.semestre && (
                      <div>
                        <dt>
                          <BookOpen size={16} aria-hidden="true" /> Semestre
                        </dt>
                        <dd>{miembro.semestre}.º semestre</dd>
                      </div>
                    )}
                  </dl>
                  <h3 className="member-profile__subtitle">Sobre mí</h3>
                  <p className="member-profile__bio">{miembro.biografia ?? 'Este integrante aún no ha escrito su biografía.'}</p>
                </div>
              </article>

              <section className="app-section" aria-labelledby="publicaciones-titulo">
                <h2 id="publicaciones-titulo" className="projects-explorer__title">
                  Publicaciones <span>y proyectos</span>
                </h2>
                {docs.estado.status === 'loading' && <Cargando texto="Cargando publicaciones…" />}
                {docs.estado.status === 'error' && <ErrorCarga error={docs.estado.error} onRetry={docs.recargar} />}
                {docs.estado.status === 'ready' && docs.estado.data.length === 0 && (
                  <Vacio icono={<FileText size={28} aria-hidden="true" />} titulo="Aún no tiene publicaciones" />
                )}
                {docs.estado.status === 'ready' && docs.estado.data.length > 0 && (
                  <div className="projects-grid">
                    {docs.estado.data.map((d) => (
                      <DocumentCard key={d.id} documento={d} onPrevisualizar={setPrevia} />
                    ))}
                  </div>
                )}
              </section>
            </div>

            <aside className="member-interest" aria-labelledby="interes-titulo">
              <h2 id="interes-titulo" className="member-interest__title">
                <Newspaper size={18} aria-hidden="true" /> Artículos de interés
              </h2>
              <p className="app-hint">Publicados por otros integrantes del semillero.</p>
              {interes.estado.status === 'loading' && <Cargando texto="Cargando artículos…" />}
              {interes.estado.status === 'error' && <ErrorCarga error={interes.estado.error} onRetry={interes.recargar} titulo="No pudimos cargar los artículos" />}
              {interes.estado.status === 'ready' && interes.estado.data.length === 0 && (
                <p className="member-interest__empty">Todavía no hay artículos de otros integrantes.</p>
              )}
              {interes.estado.status === 'ready' && interes.estado.data.length > 0 && (
                <ul className="member-interest__list">
                  {interes.estado.data.map((d) => (
                    <li key={d.id}>
                      <Link
                        className="member-interest__item"
                        to={rutaDocumento('articles', d.id)}
                        onClick={(e) => abrirVistaPrevia(e, () => setPrevia(d))}
                      >
                        <span className="member-interest__name">{d.titulo}</span>
                        <span className="member-interest__meta">
                          {nombrePersona(d.autor)}
                          {d.fecha_creacion && <> · <time dateTime={d.fecha_creacion}>{fecha(d.fecha_creacion)}</time></>}
                        </span>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
              <Link className="btn btn--ghost btn--sm member-interest__all" to="/articulos">
                Ver todos los artículos
              </Link>
            </aside>
          </div>
        )}
      </section>

      {previa && (
        <Suspense fallback={null}>
          <VistaPreviaDocumento documento={previa} onCerrar={() => setPrevia(null)} />
        </Suspense>
      )}
    </div>
  );
}
