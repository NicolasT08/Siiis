import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, BookOpen, CloudOff, GraduationCap, UserRoundSearch } from 'lucide-react';
import ProjectsHero from '../projects/components/ProjectsHero';
import { Feedback } from '../projects/components/Feedback';
import MemberAvatar from './components/MemberAvatar';
import { useMembers } from './useMembers';
import { etiquetaRol, nombreMiembro } from './memberUtils';
import '../projects/projects.css';
import './users.css';

/**
 * Perfil de un integrante. Mientras no exista `GET /usuarios/{id}` se toma del listado;
 * cambiar a la llamada propia cuando el backend la publique.
 */
export default function MemberDetailPage() {
  const { id = '' } = useParams();
  const { state, reintentar } = useMembers();
  const miembro = state.status === 'ready' ? (state.miembros.find((m) => m.id === id) ?? null) : null;

  return (
    <div className="projects-page users-page">
      <ProjectsHero title={miembro ? nombreMiembro(miembro) : 'Perfil'} compact />

      <section className="container member-profile">
        <Link to="/usuarios" className="project-detail__back">
          <ArrowLeft size={16} aria-hidden="true" /> Volver a usuarios
        </Link>

        {state.status === 'loading' && <div className="skeleton member-profile__skeleton" aria-label="Cargando perfil" />}

        {state.status === 'error' && (
          <Feedback
            icon={<CloudOff size={30} aria-hidden="true" />}
            title="No pudimos cargar este perfil"
            action={
              <button type="button" className="btn btn--navy" onClick={reintentar}>
                Reintentar
              </button>
            }
          />
        )}

        {state.status === 'ready' && !miembro && (
          <Feedback icon={<UserRoundSearch size={30} aria-hidden="true" />} title="No encontramos a este integrante">
            Puede que el perfil se haya retirado o que el enlace no sea correcto.
          </Feedback>
        )}

        {miembro && (
          <article className="member-profile__card">
            <div className="member-profile__photo">
              <MemberAvatar miembro={miembro} className="member-profile__img" />
            </div>

            <div className="member-profile__body">
              <div className="member-profile__tags">
                <span className="member-profile__role">{etiquetaRol(miembro.rol)}</span>
                <span className={`member-row__state${miembro.estado ? '' : ' is-inactive'}`}>
                  {miembro.estado ? 'Activo en el semillero' : 'Inactivo'}
                </span>
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
              <p className="member-profile__bio">
                {miembro.biografia ?? 'Este integrante aún no ha escrito su biografía.'}
              </p>
            </div>
          </article>
        )}
      </section>
    </div>
  );
}
