type Props = { title: string; subtitle?: string; compact?: boolean };

/** Banner con fondo azul marino, edificio colonial a la izquierda e ilustración histórica a la derecha. */
export default function ProjectsHero({ title, subtitle, compact = false }: Props) {
  return (
    <section className={`projects-hero${compact ? ' projects-hero--compact' : ''}`}>
      <svg width="0" height="0" aria-hidden="true" focusable="false" className="projects-hero__defs">
        <clipPath id="hero-building-clip" clipPathUnits="objectBoundingBox">
          <path d="M0 0 H1 Q0.8 0.5 0.58 1 H0 Z" />
        </clipPath>
      </svg>

      <div className="projects-hero__building" aria-hidden="true" />
      <svg className="projects-hero__swoosh" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
        <path d="M100 0 Q80 50 58 100" vectorEffect="non-scaling-stroke" />
      </svg>
      <div className="projects-hero__portrait" aria-hidden="true" />

      <div className="container projects-hero__content">
        <span className="projects-hero__eyebrow">SIIIS</span>
        <h1 className="projects-hero__title">{title}</h1>
        {subtitle && <p className="projects-hero__subtitle">{subtitle}</p>}
      </div>
    </section>
  );
}
