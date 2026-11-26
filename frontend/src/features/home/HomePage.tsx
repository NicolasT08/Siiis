import { ArrowUpRight } from 'lucide-react';
import { Link } from 'react-router-dom';
import ImageCarousel from '../../components/ImageCarousel';
import ProjectsHero from '../projects/components/ProjectsHero';
import CommentsCarousel from './CommentsCarousel';
import type { Comment } from './CommentsCarousel';
import '../projects/projects.css';
import './home.css';

const comments: Comment[] = [
  {
    name: 'Author Name',
    role: 'Role',
    quote:
      'Nibh elit lacus mi elit, dui maecenas vestibulum cursus. Aliquet quam cursus tortor eu a. Enim, integer pellentesque sagittis lectus aliquam sed curus tortor ac, ac. Ornare quisque ullamcorper a eleifend fringilla turpis.',
    tag: 'Zoomer',
  },
];

export default function HomePage() {
  return (
    <div className="projects-page home-page">
      <ProjectsHero
        title="Investigar para transformar nuestra región"
        subtitle="Somos el Semillero de Investigación en Ingeniería de Sistemas, Información e Innovación Social de la Universidad de Boyacá."
      />

      <section className="home-intro" aria-labelledby="home-intro-title">
        <div className="container home-intro__inner">
          <div>
            <p className="home-eyebrow">Conocimiento con propósito</p>
            <h2 id="home-intro-title">
              Ideas que conectan <span>tecnología y sociedad</span>
            </h2>
            <p className="home-intro__copy">
              Exploramos, creamos y compartimos conocimiento desde la ingeniería de sistemas para aportar a los retos
              de nuestra comunidad.
            </p>
          </div>
          <Link className="btn btn--navy home-intro__link" to="/proyectos">
            Conoce nuestros proyectos <ArrowUpRight size={18} aria-hidden="true" />
          </Link>
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
        </div>
      </section>

      <section className="home-comments" aria-labelledby="home-comments-title">
        <div className="container">
          <div className="home-section-heading">
            <p className="home-eyebrow">Voces de nuestra comunidad</p>
            <h2 id="home-comments-title" className="home-section-heading__title">
              Lo que dicen <span>de SIIIS</span>
            </h2>
          </div>
          <CommentsCarousel comments={comments} />
        </div>
      </section>
    </div>
  );
}
