import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { FolderOpen } from 'lucide-react';
import ProjectsHero from './components/ProjectsHero';
import ProjectStats from './components/ProjectStats';
import ProjectFilters from './components/ProjectFilters';
import ProjectCard, { ProjectCardSkeleton } from './components/ProjectCard';
import { DemoNotice, ErrorFeedback, Feedback, NoResults } from './components/Feedback';
import { useProjects } from './useProjects';
import { anioDe, claveEstado, ESTADOS, filtrarProyectos, opcionesUnicas } from './projectUtils';
import type { EstadoClave, Filtros } from './projectUtils';
import './projects.css';

const PAGE_SIZE = 9;
const CLAVES: (keyof Filtros)[] = ['estado', 'categoria', 'anio', 'q'];

export default function ProjectsPage() {
  const { state, reintentar } = useProjects();
  const [params, setParams] = useSearchParams();
  const [visibles, setVisibles] = useState(PAGE_SIZE);

  // Los filtros viven en la URL: se pueden compartir y sobreviven al botón "atrás".
  const filtros = useMemo<Filtros>(
    () => ({
      estado: params.get('estado') ?? '',
      categoria: params.get('categoria') ?? '',
      anio: params.get('anio') ?? '',
      q: params.get('q') ?? '',
    }),
    [params],
  );

  const actualizar = (cambio: Partial<Filtros>) => {
    const next = new URLSearchParams(params);
    for (const clave of CLAVES) {
      const valor = clave in cambio ? cambio[clave] : filtros[clave];
      if (valor) next.set(clave, valor);
      else next.delete(clave);
    }
    setParams(next, { replace: true });
    setVisibles(PAGE_SIZE);
  };
  const limpiar = () => actualizar({ estado: '', categoria: '', anio: '', q: '' });

  const proyectos = state.status === 'ready' ? state.proyectos : null;

  const opciones = useMemo(() => {
    const lista = proyectos ?? [];
    const estados = opcionesUnicas(lista.map((p) => claveEstado(p.estado))).filter(
      (c): c is Exclude<EstadoClave, 'otro'> => c !== 'otro',
    );
    return {
      estado: estados.map((c) => ({ value: c, label: ESTADOS[c].label })),
      categoria: opcionesUnicas(lista.map((p) => p.categoria)).map((c) => ({ value: c, label: c })),
      anio: opcionesUnicas(lista.map((p) => anioDe(p.fecha_publicacion)), 'desc').map((a) => ({ value: a, label: a })),
    };
  }, [proyectos]);

  const filtrados = useMemo(() => (proyectos ? filtrarProyectos(proyectos, filtros) : []), [proyectos, filtros]);

  // Solo se destaca un proyecto que el backend marque como `destacado`.
  const destacado = filtrados.find((p) => p.destacado) ?? null;
  const resto = filtrados.filter((p) => p !== destacado);
  const restoVisible = resto.slice(0, visibles);

  return (
    <div className="projects-page">
      <ProjectsHero
        title="Proyectos"
        subtitle="Investigación, desarrollo e innovación aplicada en sistemas de información, inteligencia artificial y análisis social."
      />
      <ProjectStats proyectos={proyectos} />

      <section className="container projects-explorer" aria-labelledby="explora-titulo">
        <div className="projects-explorer__head">
          <h2 id="explora-titulo" className="projects-explorer__title">
            Explora <span>los proyectos</span>
          </h2>
          <ProjectFilters
            filtros={filtros}
            opciones={opciones}
            disabled={state.status !== 'ready'}
            onChange={actualizar}
            onClear={limpiar}
          />
        </div>

        {state.status === 'ready' && state.demo && <DemoNotice />}

        {state.status === 'loading' && (
          <div className="projects-grid" aria-label="Cargando proyectos">
            <ProjectCardSkeleton featured />
            <ProjectCardSkeleton />
            <ProjectCardSkeleton />
          </div>
        )}

        {state.status === 'error' && <ErrorFeedback error={state.error} onRetry={reintentar} />}

        {state.status === 'ready' && state.proyectos.length === 0 && (
          <Feedback icon={<FolderOpen size={30} aria-hidden="true" />} title="Aún no hay proyectos publicados">
            Cuando el semillero publique sus proyectos, aparecerán aquí.
          </Feedback>
        )}

        {state.status === 'ready' && state.proyectos.length > 0 && filtrados.length === 0 && (
          <NoResults onClear={limpiar} />
        )}

        {filtrados.length > 0 && (
          <>
            <p className="visually-hidden" aria-live="polite">
              {filtrados.length} proyectos encontrados
            </p>
            <div className="projects-grid">
              {destacado && <ProjectCard proyecto={destacado} featured />}
              {restoVisible.map((p) => (
                <ProjectCard key={p.id} proyecto={p} />
              ))}
            </div>
            {resto.length > visibles && (
              <div className="projects-more">
                <button type="button" className="btn btn--navy" onClick={() => setVisibles((n) => n + PAGE_SIZE)}>
                  Mostrar más proyectos
                </button>
              </div>
            )}
          </>
        )}
      </section>
    </div>
  );
}
