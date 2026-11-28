import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, BookOpen, FileText, GraduationCap, UserRoundSearch } from 'lucide-react';
import ProjectsHero from '../projects/components/ProjectsHero';
import { api } from '../../lib/api';
import type { DocumentoResumen } from '../../lib/api';
import { esNoEncontrado } from '../../lib/errors';
import { useApi } from '../../lib/useApi';
import { Cargando, ErrorCarga, Vacio } from '../../components/ui';
import DocumentCard from '../content/DocumentCard';
import MemberAvatar from './components/MemberAvatar';
import { etiquetaRol, nombreMiembro } from './memberUtils';
import '../projects/projects.css';
import './users.css';

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

export default function MemberDetailPage() {
  const { id = '' } = useParams();
  const perfil = useApi((signal) => api.member(id, signal), [id]);
  const docs = useApi((signal) => publicaciones(id, signal), [id]);
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
        )}

        {miembro && (
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
                  <DocumentCard key={d.id} documento={d} />
                ))}
              </div>
            )}
          </section>
        )}
      </section>
    </div>
  );
}
