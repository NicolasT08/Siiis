import { ArrowUpRight, Newspaper, PlayCircle, Settings2 } from 'lucide-react';
import { Link } from 'react-router-dom';
import ImageCarousel from '../../components/ImageCarousel';
import { SITIO } from '../../config/sitio';
import { api } from '../../lib/api';
import { esAdministrativo, useAuth } from '../../lib/auth';
import { useApi } from '../../lib/useApi';
import DocumentCard from '../content/DocumentCard';
import ProjectsHero from '../projects/components/ProjectsHero';
import ReviewsSection from '../reviews/ReviewsSection';
import '../projects/projects.css';
import './home.css';

function Videos() {
  const { estado } = useApi((signal) => api.videos(signal), []);
  const videos = estado.status === 'ready' ? estado.data.data.filter((v) => v.tipo === 'video' && v.url) : [];
  if (videos.length === 0) return null;
  return (
    <section className="home-gallery home-videos" aria-labelledby="home-videos-title">
      <div className="container">
        <div className="home-section-heading">
          <p className="home-eyebrow">
            <PlayCircle size={14} aria-hidden="true" /> Conócenos en video
          </p>
          <h2 id="home-videos-title" className="home-section-heading__title">
            Videos <span>del semillero</span>
          </h2>
        </div>
        <div className="home-videos__grid">
          {videos.map((v) => (
            <figure key={v.id} className="home-videos__item">
              <video controls preload="metadata" src={v.url} aria-label={v.descripcion ?? 'Video del semillero'}>
                Tu navegador no puede reproducir este video. <a href={v.url}>Descárgalo aquí</a>.
              </video>
              {v.descripcion && <figcaption>{v.descripcion}</figcaption>}
            </figure>
          ))}
        </div>
      </div>
    </section>
  );
}

function UltimasNoticias() {
  const { estado } = useApi((signal) => api.documents('articles', { categoria: 'noticia', per_page: 3 }, signal), []);
  const noticias = estado.status === 'ready' ? estado.data.data : [];
  if (noticias.length === 0) return null;
  return (
    <section className="home-gallery" aria-labelledby="home-news-title">
      <div className="container">
        <div className="home-section-heading">
          <p className="home-eyebrow">
            <Newspaper size={14} aria-hidden="true" /> Lo más reciente
          </p>
          <h2 id="home-news-title" className="home-section-heading__title">
            Últimas <span>noticias</span>
          </h2>
        </div>
        <div className="projects-grid">
          {noticias.map((n) => (
            <DocumentCard key={n.id} documento={n} mostrarEstado={false} />
          ))}
        </div>
        <p className="home-more">
          <Link className="btn btn--navy" to="/noticias">
            Ver todas las noticias <ArrowUpRight size={16} aria-hidden="true" />
          </Link>
        </p>
      </div>
    </section>
  );
}

/** Acceso directo a Administración → Inicio para superadministradores y administradores (DEC-B62). */
function AdministrarCarrusel() {
  const { usuario } = useAuth();
  if (!esAdministrativo(usuario?.rol)) return null;
  return (
    <p className="home-admin-link">
      <Link className="btn btn--bronze" to="/admin/inicio">
        <Settings2 size={16} aria-hidden="true" /> Administrar carrusel
      </Link>
    </p>
  );
}

export default function HomePage() {
  return (
    <div className="projects-page home-page">
      <ProjectsHero
        title="Investigar para transformar nuestra región"
        subtitle={`Somos el Semillero de Investigación en Ingeniería de Sistemas, Información e Innovación Social de la ${SITIO.universidad}.`}
      />

      <section className="home-intro" aria-labelledby="home-intro-title">
        <div className="container home-intro__inner">
          <div>
            <p className="home-eyebrow">Conocimiento con propósito</p>
            <h2 id="home-intro-title">
              Ideas que conectan <span>tecnología y sociedad</span>
            </h2>
            <p className="home-intro__copy">
              Exploramos, creamos y compartimos conocimiento desde la ingeniería de sistemas para aportar a los retos de
              nuestra comunidad.
            </p>
          </div>
          <div className="home-intro__actions">
            <Link className="btn btn--navy home-intro__link" to="/proyectos">
              Conoce nuestros proyectos <ArrowUpRight size={18} aria-hidden="true" />
            </Link>
            <Link className="btn btn--bronze home-intro__link" to="/inscripcion">
              Inscríbete <ArrowUpRight size={18} aria-hidden="true" />
            </Link>
          </div>
        </div>
      </section>

      <section className="home-gallery" aria-labelledby="home-gallery-title">
        <div className="container">
          <div className="home-section-heading">
            <p className="home-eyebrow">Así vivimos la investigación</p>
            <h2 id="home-gallery-title" className="home-section-heading__title">
              Momentos <span>SIIIS</span>
            </h2>
          </div>
          <div className="gallery-box">
            <ImageCarousel />
          </div>
          <AdministrarCarrusel />
        </div>
      </section>

      <Videos />
      <UltimasNoticias />

      <section className="home-comments" aria-labelledby="home-comments-title">
        <div className="container">
          <div className="home-section-heading">
            <p className="home-eyebrow">Voces de nuestra comunidad</p>
            <h2 id="home-comments-title" className="home-section-heading__title">
              Lo que dicen <span>de SIIIS</span>
            </h2>
          </div>
          <ReviewsSection />
        </div>
      </section>
    </div>
  );
}
