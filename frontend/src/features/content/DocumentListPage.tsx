import { useId } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ChevronDown, FolderOpen, Plus, X } from 'lucide-react';
import ProjectsHero from '../projects/components/ProjectsHero';
import { api, ESTADOS_POR_MODULO } from '../../lib/api';
import type { EstadoDocumento, Modulo } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { ETIQUETA_ESTADO } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { Cargando, ErrorCarga, Paginacion, Vacio } from '../../components/ui';
import DocumentCard from './DocumentCard';
import '../projects/projects.css';

const POR_PAGINA = 12;

type Props = {
  modulo: Modulo;
  titulo: string;
  subtitulo: string;
  /** /noticias fija la categoría; /articulos deja elegir. */
  categoriaFija?: 'articulo' | 'noticia';
};

function Select({ label, value, options, onChange }: { label: string; value: string; options: { value: string; label: string }[]; onChange: (v: string) => void }) {
  const id = useId();
  return (
    <div className="filter-select">
      <label htmlFor={id} className="visually-hidden">
        {label}
      </label>
      <select id={id} value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">{label}</option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
      <ChevronDown className="filter-select__chevron" size={16} aria-hidden="true" />
    </div>
  );
}

/** Listado público paginado de proyectos (`/projects`) o de artículos y noticias (`/articles`). */
export default function DocumentListPage({ modulo, titulo, subtitulo, categoriaFija }: Props) {
  const { usuario } = useAuth();
  const [params, setParams] = useSearchParams();
  const page = Math.max(1, Number(params.get('page')) || 1);
  const estados = ESTADOS_POR_MODULO[modulo];
  const estadoParam = params.get('estado') ?? '';
  const estado = estados.includes(estadoParam as EstadoDocumento) ? (estadoParam as EstadoDocumento) : '';
  const categoriaParam = params.get('categoria');
  const categoria = categoriaFija ?? (categoriaParam === 'articulo' || categoriaParam === 'noticia' ? categoriaParam : '');

  const { estado: carga, datos, recargar } = useApi(
    (signal) => api.documents(modulo, { page, per_page: POR_PAGINA, estado, ...(modulo === 'articles' ? { categoria } : {}) }, signal),
    [modulo, page, estado, categoria, usuario?.id],
  );

  const cambiar = (cambio: { page?: number; estado?: string; categoria?: string }) => {
    const next = new URLSearchParams(params);
    const valores: Record<string, string> = { page: String(cambio.page ?? 1), estado: cambio.estado ?? estado };
    if (!categoriaFija) valores.categoria = cambio.categoria ?? categoria;
    for (const [clave, valor] of Object.entries(valores)) {
      if (valor && valor !== '1') next.set(clave, valor);
      else next.delete(clave);
    }
    setParams(next, { replace: true });
  };

  // Sin sesión, "en revisión" nunca trae nada: solo se ofrece a quien tiene sesión (ve sus borradores).
  const opcionesEstado = estados
    .filter((e) => usuario || e !== 'en_revision')
    .map((e) => ({ value: e, label: ETIQUETA_ESTADO[e] }));
  const hayFiltros = Boolean(estado || (!categoriaFija && categoria));
  const nuevo = modulo === 'projects' ? 'proyecto' : (categoriaFija ?? 'articulo');

  return (
    <div className="projects-page">
      <ProjectsHero title={titulo} subtitle={subtitulo} />
      <section className="container projects-explorer" aria-labelledby="explora-titulo">
        <div className="projects-explorer__head">
          <h2 id="explora-titulo" className="projects-explorer__title">
            Explora <span>{titulo.toLowerCase()}</span>
          </h2>
          <form className="project-filters" role="search" onSubmit={(e) => e.preventDefault()}>
            {opcionesEstado.length > 1 && (
              <Select label="Estado" value={estado} options={opcionesEstado} onChange={(v) => cambiar({ estado: v })} />
            )}
            {!categoriaFija && modulo === 'articles' && (
              <Select
                label="Tipo"
                value={categoria}
                options={[
                  { value: 'articulo', label: 'Artículos' },
                  { value: 'noticia', label: 'Noticias' },
                ]}
                onChange={(v) => cambiar({ categoria: v })}
              />
            )}
            {hayFiltros && (
              <button type="button" className="btn btn--bronze" onClick={() => cambiar({ estado: '', categoria: '' })}>
                <X size={16} aria-hidden="true" /> Limpiar
              </button>
            )}
            {usuario && (
              <Link className="btn btn--navy" to={`/mis-contenidos/nuevo/${nuevo}`}>
                <Plus size={16} aria-hidden="true" /> Crear
              </Link>
            )}
          </form>
        </div>

        {carga.status === 'loading' && !datos && <Cargando />}
        {carga.status === 'error' && <ErrorCarga error={carga.error} onRetry={recargar} />}
        {datos && datos.data.length === 0 && (
          <Vacio icono={<FolderOpen size={30} aria-hidden="true" />} titulo={hayFiltros ? 'Nada coincide con esos filtros' : 'Aún no hay publicaciones'}>
            {hayFiltros ? 'Prueba con otro filtro.' : 'Cuando el semillero publique contenido, aparecerá aquí.'}
          </Vacio>
        )}
        {datos && datos.data.length > 0 && (
          <>
            <p className="visually-hidden" aria-live="polite">
              {datos.meta.total} resultados
            </p>
            <div className="projects-grid">
              {datos.data.map((d) => (
                <DocumentCard key={d.id} documento={d} />
              ))}
            </div>
            <Paginacion meta={datos.meta} onPage={(p) => cambiar({ page: p })} />
          </>
        )}
      </section>
    </div>
  );
}
