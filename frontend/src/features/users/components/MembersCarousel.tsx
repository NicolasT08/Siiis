import { useEffect, useRef, useState } from 'react';
import type React from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowUpRight, ChevronLeft, ChevronRight, GraduationCap, Pause, Play } from 'lucide-react';
import type { Miembro } from '../../../lib/api';
import { MIN_CARDS, startArcCarousel } from '../../../lib/arcCarousel';
import type { ArcCarousel } from '../../../lib/arcCarousel';
import { detalleAcademico, etiquetaRol, nombreMiembro } from '../memberUtils';
import MemberAvatar from './MemberAvatar';

/**
 * Integrantes en el carrusel en arco: la tarjeta del centro resalta (franja dorada y más sombra).
 * Un clic en una tarjeta abre el perfil (/usuarios/:id). Las tarjetas animadas son decorativas
 * (aria-hidden); con teclado y lector de pantalla se usa el directorio que la página muestra debajo.
 */
export default function MembersCarousel({ miembros }: { miembros: Miembro[] }) {
  const stageRef = useRef<HTMLDivElement>(null);
  const cardRefs = useRef<(HTMLDivElement | null)[]>([]);
  const carousel = useRef<ArcCarousel | null>(null);
  const [paused, setPaused] = useState(false);
  const navigate = useNavigate();

  // Se repite el set completo hasta tener tarjetas suficientes para el bucle (así las copias
  // de una misma persona nunca quedan juntas).
  const copias = miembros.length ? Math.ceil(MIN_CARDS / miembros.length) : 0;
  const tarjetas = Array.from({ length: copias }, () => miembros).flat();

  useEffect(() => {
    const stage = stageRef.current;
    if (!stage) return;
    const elementos = cardRefs.current
      .slice(0, tarjetas.length)
      .filter((card): card is HTMLDivElement => card !== null);
    const instance = startArcCarousel(stage, elementos);
    carousel.current = instance;
    return () => {
      instance.dispose();
      carousel.current = null;
    };
  }, [tarjetas.length]);

  // Un clic abre el perfil; si la persona arrastró el carrusel, no.
  const inicioX = useRef(0);
  const abrir = (e: React.MouseEvent, id: string) => {
    if (Math.abs(e.clientX - inicioX.current) > 6) return;
    navigate(`/usuarios/${encodeURIComponent(id)}`);
  };

  const togglePause = () => {
    carousel.current?.setPaused(!paused);
    setPaused(!paused);
  };

  return (
    <>
      <div
        ref={stageRef}
        className="members-stage"
        aria-hidden="true"
        onPointerDown={(e) => {
          inicioX.current = e.clientX;
        }}
      >
        {tarjetas.map((m, i) => (
          <div
            key={`${m.id}-${i}`}
            ref={(el) => {
              cardRefs.current[i] = el;
            }}
            className="members-stage__slot"
            style={{ opacity: 0 }}
          >
            <article className="member-card" onClick={(e) => abrir(e, m.id)}>
              <div className="member-card__photo">
                <MemberAvatar miembro={m} className="member-card__img" />
                <span className="member-card__role">{etiquetaRol(m.rol)}</span>
              </div>
              <div className="member-card__body">
                <h3 className="member-card__name">{nombreMiembro(m)}</h3>
                <span className="member-card__bar" />
                {m.biografia && <p className="member-card__bio">{m.biografia}</p>}
                <footer className="member-card__foot">
                  <GraduationCap size={17} />
                  <span>{detalleAcademico(m) || 'Semillero SIIIS'}</span>
                  <span className="member-card__more">
                    Ver perfil <ArrowUpRight size={14} />
                  </span>
                </footer>
              </div>
            </article>
          </div>
        ))}
      </div>

      <div className="members-controls">
        <button
          type="button"
          className="members-controls__btn members-controls__btn--prev"
          onClick={() => carousel.current?.previous()}
          aria-label="Integrante anterior"
        >
          <ChevronLeft size={22} aria-hidden="true" />
        </button>
        <button
          type="button"
          className="members-controls__btn members-controls__btn--pause"
          onClick={togglePause}
          aria-label={paused ? 'Reanudar carrusel' : 'Pausar carrusel'}
          aria-pressed={paused}
        >
          {paused ? <Play size={18} aria-hidden="true" /> : <Pause size={18} aria-hidden="true" />}
        </button>
        <button
          type="button"
          className="members-controls__btn members-controls__btn--next"
          onClick={() => carousel.current?.next()}
          aria-label="Integrante siguiente"
        >
          <ChevronRight size={22} aria-hidden="true" />
        </button>
      </div>
    </>
  );
}
