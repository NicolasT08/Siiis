import ProjectsHero from '../projects/components/ProjectsHero';
import ReviewsSection from './ReviewsSection';
import '../projects/projects.css';

export default function ReviewsPage() {
  return (
    <div className="projects-page">
      <ProjectsHero compact title="Reseñas" subtitle="Lo que dicen del Semillero SIIIS quienes lo conocen." />
      <section className="container app-page">
        <ReviewsSection porPagina={10} paginado />
      </section>
    </div>
  );
}
