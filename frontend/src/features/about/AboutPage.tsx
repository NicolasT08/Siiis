import { useState } from 'react';
import { BookOpenCheck, Code2, Eye, Lightbulb } from 'lucide-react';
import ProjectsHero from '../projects/components/ProjectsHero';
import DevelopersCarousel from './DevelopersCarousel';
import '../projects/projects.css';
import '../users/users.css';
import './about.css';

type Version = '1.0' | '2.0' | '3.0';

const VERSION_DEVELOPERS: Record<Version, string[]> = {
  '1.0': [],
  '2.0': [],
  '3.0': ['Luisa Fernanda', 'Rafael Lozano', 'Nicolás Tinjaca', 'Andryw Barrera'],
};

const VERSIONS: Version[] = ['1.0', '2.0', '3.0'];

export default function AboutPage() {
  const [version, setVersion] = useState<Version>('3.0');
  const developers = VERSION_DEVELOPERS[version];

  return (
    <div className="projects-page about-page">
      <ProjectsHero
        title="Acerca de nosotros"
        subtitle="Conoce el propósito, la visión y las personas que construyen el Semillero de Investigación SIIIS."
      />

      <div className="container">
        <ul className="project-stats about-stats" aria-label="Resumen del semillero">
          <li className="project-stats__item">
            <BookOpenCheck className="project-stats__icon" size={30} strokeWidth={1.6} aria-hidden="true" />
            <div>
              <strong className="project-stats__value">SIIIS</strong>
              <span className="project-stats__label">Semillero de investigación</span>
            </div>
          </li>
        </ul>
      </div>

      <section className="container about-story" aria-labelledby="about-story-title">
        <div className="about-story__intro">
          <p className="about-eyebrow">Quiénes somos</p>
          <h2 id="about-story-title" className="about-heading">
            Investigación que conecta <span>ideas y tecnología</span>
          </h2>
          <p className="about-story__text">
            SIIIS es un espacio para aprender, investigar y desarrollar soluciones desde los sistemas de información.
            Trabajamos en equipo para compartir conocimiento y convertir ideas en proyectos.
          </p>
        </div>

        <div className="about-pillars">
          <article className="about-pillar">
            <span className="about-pillar__icon">
              <Lightbulb size={24} aria-hidden="true" />
            </span>
            <p className="about-eyebrow">Nuestro propósito</p>
            <h3>Misión</h3>
            <p>
              Promover la investigación y la colaboración en sistemas de información, creando proyectos que aporten
              conocimiento y respondan a necesidades reales.
            </p>
          </article>
          <article className="about-pillar">
            <span className="about-pillar__icon">
              <Eye size={24} aria-hidden="true" />
            </span>
            <p className="about-eyebrow">Hacia dónde vamos</p>
            <h3>Visión</h3>
            <p>
              Ser un semillero reconocido por sus iniciativas de investigación, el trabajo interdisciplinario y el
              desarrollo de soluciones con impacto.
            </p>
          </article>
        </div>

        <section className="about-director" aria-labelledby="about-director-title">
          <span className="about-director__avatar" aria-hidden="true">
            EJ
          </span>
          <div>
            <p className="about-eyebrow">Dirección del semillero</p>
            <h2 id="about-director-title">Director</h2>
            <p className="about-director__name">Edmundo Arturo Junco</p>
          </div>
        </section>
      </section>

      <section className="about-developers" aria-labelledby="developers-title">
        <div className="container">
          <div className="about-developers__head">
            <div>
              <p className="about-eyebrow">Quienes hacen posible cada versión</p>
              <h2 id="developers-title" className="about-heading">
                Equipo <span>desarrollador</span>
              </h2>
            </div>
            <div className="about-version-picker" aria-label="Seleccionar versión del equipo">
              {VERSIONS.map((item) => (
                <button
                  key={item}
                  type="button"
                  className={`about-version-picker__option${version === item ? ' is-active' : ''}`}
                  aria-pressed={version === item}
                  onClick={() => setVersion(item)}
                >
                  Versión {item}
                </button>
              ))}
            </div>
          </div>

          <p className="about-developers__caption">
            Desarrolladores de la <strong>versión {version}</strong>
          </p>

          {developers.length > 0 ? (
            <DevelopersCarousel key={version} developers={developers} version={version} />
          ) : (
            <div className="about-version-empty" role="status">
              <Code2 size={26} aria-hidden="true" />
              <p>El equipo desarrollador de la versión {version} se anunciará próximamente.</p>
            </div>
          )}
        </div>
      </section>
    </div>
  );
}
