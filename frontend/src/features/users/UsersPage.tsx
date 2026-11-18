import { useId, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronDown, CloudOff, FlaskConical, Search, SearchX, Users } from 'lucide-react';
import ProjectsHero from '../projects/components/ProjectsHero';
import { Feedback } from '../projects/components/Feedback';
import { ApiError } from '../../lib/api';
import MembersCarousel from './components/MembersCarousel';
import MemberAvatar from './components/MemberAvatar';
import { useMembers } from './useMembers';
import { detalleAcademico, etiquetaRol, filtrarMiembros, nombreMiembro } from './memberUtils';
import '../projects/projects.css';
import './users.css';

export default function UsersPage() {
  const { state, reintentar } = useMembers();
  const [q, setQ] = useState('');
  const [rol, setRol] = useState('');
  const searchId = useId();
  const rolId = useId();

  const miembros = useMemo(() => (state.status === 'ready' ? state.miembros : []), [state]);
  // El carrusel presenta a quienes están activos; el directorio lista a todos.
  const activos = useMemo(() => miembros.filter((m) => m.estado), [miembros]);
  const roles = useMemo(() => [...new Set(miembros.map((m) => etiquetaRol(m.rol)))].sort(), [miembros]);
  const filtrados = useMemo(() => filtrarMiembros(miembros, q, rol), [miembros, q, rol]);

  return (
    <div className="projects-page users-page">
      <ProjectsHero
        title="Usuarios"
        subtitle="Docentes, investigadores y estudiantes que hacen crecer el semillero SIIIS."
      />

      <section className="members-showcase" aria-labelledby="equipo-titulo">
        <div className="container members-showcase__head">
          <p className="members-showcase__eyebrow">Nuestro equipo</p>
          <h2 id="equipo-titulo" className="members-showcase__title">
            Las personas detrás de <span>cada investigación</span>
          </h2>
          {state.status === 'ready' && state.demo && (
            <p className="demo-notice" role="note">
              <FlaskConical size={16} aria-hidden="true" />
              Modo demostración: personas ficticias, solo visibles en desarrollo (VITE_USERS_DEMO).
            </p>
          )}
        </div>

        {state.status === 'loading' && <div className="container skeleton members-showcase__skeleton" />}

        {state.status === 'error' && (
          <div className="container">
            <Feedback
              icon={<CloudOff size={30} aria-hidden="true" />}
              title={
                state.error instanceof ApiError && state.error.status === 404
                  ? 'El listado de integrantes aún no está disponible'
                  : 'No pudimos cargar a los integrantes'
              }
              action={
                <button type="button" className="btn btn--navy" onClick={reintentar}>
                  Reintentar
                </button>
              }
            >
              {state.error instanceof ApiError && state.error.status !== 404
                ? state.error.message
                : 'El servicio de usuarios todavía no está publicado en la API. Vuelve a intentarlo más tarde.'}
            </Feedback>
          </div>
        )}

        {state.status === 'ready' && activos.length === 0 && (
          <div className="container">
            <Feedback icon={<Users size={30} aria-hidden="true" />} title="Aún no hay integrantes publicados" />
          </div>
        )}

        {activos.length > 0 && <MembersCarousel miembros={activos} />}
      </section>

      {state.status === 'ready' && miembros.length > 0 && (
        <section className="container members-directory" aria-labelledby="directorio-titulo">
          <div className="projects-explorer__head">
            <h2 id="directorio-titulo" className="projects-explorer__title">
              Directorio <span>de integrantes</span>
            </h2>
            <form className="project-filters" role="search" onSubmit={(e) => e.preventDefault()}>
              <div className="filter-select">
                <label htmlFor={rolId} className="visually-hidden">
                  Rol
                </label>
                <select id={rolId} value={rol} onChange={(e) => setRol(e.target.value)}>
                  <option value="">Todos los roles</option>
                  {roles.map((r) => (
                    <option key={r} value={r}>
                      {r}
                    </option>
                  ))}
                </select>
                <ChevronDown className="filter-select__chevron" size={16} aria-hidden="true" />
              </div>
              <div className="filter-search">
                <label htmlFor={searchId} className="visually-hidden">
                  Buscar integrantes por nombre o programa
                </label>
                <Search className="filter-search__icon" size={17} aria-hidden="true" />
                <input
                  id={searchId}
                  type="search"
                  placeholder="Buscar…"
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                />
              </div>
            </form>
          </div>

          <p className="visually-hidden" aria-live="polite">
            {filtrados.length} integrantes encontrados
          </p>

          {filtrados.length === 0 ? (
            <Feedback
              icon={<SearchX size={30} aria-hidden="true" />}
              title="Nadie coincide con tu búsqueda"
              action={
                <button
                  type="button"
                  className="btn btn--navy"
                  onClick={() => {
                    setQ('');
                    setRol('');
                  }}
                >
                  Limpiar búsqueda
                </button>
              }
            />
          ) : (
            <ul className="members-grid">
              {filtrados.map((m) => (
                <li key={m.id}>
                  <Link to={`/usuarios/${encodeURIComponent(m.id)}`} className="member-row">
                    <MemberAvatar miembro={m} className="member-row__avatar" />
                    <div className="member-row__info">
                      <p className="member-row__role">{etiquetaRol(m.rol)}</p>
                      <h3 className="member-row__name">{nombreMiembro(m)}</h3>
                      {detalleAcademico(m) && <p className="member-row__detail">{detalleAcademico(m)}</p>}
                    </div>
                    <span className={`member-row__state${m.estado ? '' : ' is-inactive'}`}>
                      {m.estado ? 'Activo' : 'Inactivo'}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
    </div>
  );
}
