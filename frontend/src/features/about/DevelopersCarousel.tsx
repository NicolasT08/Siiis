import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Code2, Mail } from 'lucide-react';
import { startArcCarousel } from '../../lib/arcCarousel';
import type { ArcCarousel } from '../../lib/arcCarousel';

export type Developer = {
  nombre: string;
  correo?: string;
  foto?: string;
};

type Props = {
  developers: Developer[];
  version: string;
};

/** Foto del desarrollador o, si no hay (o falla), sus iniciales con el estilo de siempre. */
function DeveloperPhoto({ developer, cardIndex }: { developer: Developer; cardIndex: number }) {
  const [fallo, setFallo] = useState(false);
  if (developer.foto && !fallo) {
    return (
      <img
        className="member-card__img"
        src={developer.foto}
        alt={developer.nombre}
        loading="lazy"
        decoding="async"
        draggable={false}
        onError={() => setFallo(true)}
      />
    );
  }
  return (
    <span
      className={`member-card__img member-avatar--initials about-developer-avatar about-developer-avatar--${cardIndex + 1}`}
    >
      {initials(developer.nombre)}
    </span>
  );
}

function initials(name: string) {
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toLocaleUpperCase('es');
}

export default function DevelopersCarousel({ developers, version }: Props) {
  const stageRef = useRef<HTMLDivElement>(null);
  const cardRefs = useRef<(HTMLDivElement | null)[]>([]);
  const carousel = useRef<ArcCarousel | null>(null);
  const [index, setIndex] = useState(0);

  useEffect(() => {
    const stage = stageRef.current;
    if (!stage) return;
    const elements = cardRefs.current
      .slice(0, developers.length)
      .filter((card): card is HTMLDivElement => card !== null);
    const instance = startArcCarousel(stage, elements, 'developers');
    carousel.current = instance;
    return () => {
      instance.dispose();
      carousel.current = null;
    };
  }, [developers.length, version]);

  const showPrevious = () => {
    if (!carousel.current || index === 0) return;
    carousel.current.previous();
    setIndex(index - 1);
  };

  const showNext = () => {
    if (!carousel.current || index >= developers.length - 1) return;
    carousel.current.next();
    setIndex(index + 1);
  };

  return (
    <section
      className="about-developers__carousel"
      aria-label={`Carrusel de desarrolladores de la versión ${version}`}
    >
      <div ref={stageRef} className="members-stage" aria-hidden="true">
        {developers.map((developer, cardIndex) => (
          <div
            key={`${version}-${developer.nombre}`}
            ref={(element) => {
              cardRefs.current[cardIndex] = element;
            }}
            className="members-stage__slot"
            style={{ opacity: 0 }}
          >
            <article className="member-card about-developer-card">
              <div className="member-card__photo">
                <DeveloperPhoto developer={developer} cardIndex={cardIndex} />
                <span className="member-card__role">Desarrollador/a</span>
              </div>
              <div className="member-card__body">
                <h3 className="member-card__name">{developer.nombre}</h3>
                <span className="member-card__bar" />
                <p className="member-card__bio">
                  Parte del equipo desarrollador del Semillero de Investigación SIIIS.
                </p>
                <footer className="member-card__foot">
                  {developer.correo ? (
                    <>
                      <Mail size={17} aria-hidden="true" />
                      <span>{developer.correo}</span>
                    </>
                  ) : (
                    <>
                      <Code2 size={17} aria-hidden="true" />
                      <span>SIIIS · Versión {version}</span>
                    </>
                  )}
                </footer>
              </div>
            </article>
          </div>
        ))}
      </div>

      <ul className="visually-hidden" aria-label={`Desarrolladores de la versión ${version}`}>
        {developers.map((developer) => (
          <li key={developer.nombre}>
            {developer.nombre}
            {developer.correo && <>, {developer.correo}</>}
          </li>
        ))}
      </ul>

      <div className="members-controls">
        <button
          type="button"
          className="members-controls__btn members-controls__btn--prev"
          onClick={showPrevious}
          disabled={index === 0}
          aria-label="Desarrollador anterior"
        >
          <ChevronLeft size={22} aria-hidden="true" />
        </button>
        <span className="about-developers__position" aria-live="polite">
          <span className="visually-hidden">Desarrollador </span>
          {String(index + 1).padStart(2, '0')} / {String(developers.length).padStart(2, '0')}
        </span>
        <button
          type="button"
          className="members-controls__btn members-controls__btn--next"
          onClick={showNext}
          disabled={index === developers.length - 1}
          aria-label="Siguiente desarrollador"
        >
          <ChevronRight size={22} aria-hidden="true" />
        </button>
      </div>
    </section>
  );
}
