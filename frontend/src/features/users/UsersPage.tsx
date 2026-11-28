import { useId } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ChevronDown, Users } from 'lucide-react';
import ProjectsHero from '../projects/components/ProjectsHero';
import { api, ROLES } from '../../lib/api';
import type { Rol } from '../../lib/api';
import { useApi } from '../../lib/useApi';
import { Cargando, ErrorCarga, Paginacion, Vacio } from '../../components/ui';
import MembersCarousel from './components/MembersCarousel';
import MemberAvatar from './components/MemberAvatar';
import { detalleAcademico, etiquetaRol, nombreMiembro } from './memberUtils';
import '../projects/projects.css';
import './users.css';

const POR_PAGINA = 12;

export default function UsersPage() {
  const [params, setParams] = useSearchParams();
  const rolId = useId();
  const page = Math.max(1, Number(params.get('page')) || 1);
  const rolParam = params.get('rol') ?? '';
  const rol = (ROLES as readonly string[]).includes(rolParam) ? (rolParam as Rol) : '';

  // El carrusel presenta a las primeras personas; el directorio se pagina en la API (GET /users).
  const destacados = useApi((signal) => api.members({ per_page: 12 }, signal), []);
  const directorio = useApi((signal) => api.members({ page, per_page: POR_PAGINA, rol }, signal), [page, rol]);

  const cambiar = (cambio: { page?: number; rol?: string }) => {
    const next = new URLSearchParams(params);
    const valores = { page: String(cambio.page ?? 1), rol: cambio.rol ?? rol };
    for (const [clave, valor] of Object.entries(valores)) {
      if (valor && valor !== '1') next.set(clave, valor);
      else next.delete(clave);
    }
    setParams(next, { replace: true });
  };

  const lista = directorio.datos;

  return (
    <div className="projects-page users-page">
      <ProjectsHero title="Integrantes" subtitle="Docentes, investigadores y estudiantes que hacen crecer el semillero SIIIS." />

      <section className="members-showcase" aria-labelledby="equipo-titulo">
        <div className="container members-showcase__head">
          <p className="members-showcase__eyebrow">Nuestro equipo</p>
          <h2 id="equipo-titulo" className="members-showcase__title">
            Las personas detrás de <span>cada investigación</span>
          </h2>
        </div>
        {destacados.estado.status === 'loading' && <div className="container skeleton members-showcase__skeleton" />}
        {destacados.estado.status === 'ready' && destacados.estado.data.data.length > 0 && (
          <MembersCarousel miembros={destacados.estado.data.data} />
        )}
      </section>

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
              <select id={rolId} value={rol} onChange={(e) => cambiar({ rol: e.target.value })}>
                <option value="">Todos los roles</option>
                {ROLES.map((r) => (
                  <option key={r} value={r}>
                    {etiquetaRol(r)}
                  </option>
                ))}
              </select>
              <ChevronDown className="filter-select__chevron" size={16} aria-hidden="true" />
            </div>
          </form>
        </div>

        {directorio.estado.status === 'loading' && !lista && <Cargando texto="Cargando integrantes…" />}
        {directorio.estado.status === 'error' && (
          <ErrorCarga error={directorio.estado.error} onRetry={directorio.recargar} titulo="No pudimos cargar a los integrantes" />
        )}
        {lista && lista.data.length === 0 && (
          <Vacio icono={<Users size={30} aria-hidden="true" />} titulo="No hay integrantes con ese filtro" />
        )}
        {lista && lista.data.length > 0 && (
          <>
            <p className="visually-hidden" aria-live="polite">
              {lista.meta.total} integrantes encontrados
            </p>
            <ul className="members-grid">
              {lista.data.map((m) => (
                <li key={m.id}>
                  <Link to={`/usuarios/${encodeURIComponent(m.id)}`} className="member-row">
                    <MemberAvatar miembro={m} className="member-row__avatar" ancho={160} />
                    <div className="member-row__info">
                      <p className="member-row__role">{etiquetaRol(m.rol)}</p>
                      <h3 className="member-row__name">{nombreMiembro(m)}</h3>
                      {detalleAcademico(m) && <p className="member-row__detail">{detalleAcademico(m)}</p>}
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
            <Paginacion meta={lista.meta} onPage={(p) => cambiar({ page: p })} />
          </>
        )}
      </section>
    </div>
  );
}
