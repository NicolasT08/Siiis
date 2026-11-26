import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Pause, Play, Quote } from 'lucide-react';
import { MIN_CARDS, startArcCarousel } from '../../lib/arcCarousel';
import type { ArcCarousel } from '../../lib/arcCarousel';

export type Comment = {
  name: string;
  role: string;
  quote: string;
  tag: string;
};

export default function CommentsCarousel({ comments }: { comments: Comment[] }) {
  const stageRef = useRef<HTMLDivElement>(null);
  const cardRefs = useRef<(HTMLDivElement | null)[]>([]);
  const carousel = useRef<ArcCarousel | null>(null);
  const [paused, setPaused] = useState(false);

  const copies = comments.length > 1 ? Math.ceil(MIN_CARDS / comments.length) : 1;
  const cards = Array.from({ length: copies }, () => comments).flat();

  useEffect(() => {
    const stage = stageRef.current;
    if (!stage) return;

    const elements = cardRefs.current
      .slice(0, cards.length)
      .filter((card): card is HTMLDivElement => card !== null);
    const instance = startArcCarousel(stage, elements, 'testimonials');
    carousel.current = instance;

    return () => {
      instance.dispose();
      carousel.current = null;
    };
  }, [cards.length]);

  const togglePause = () => {
    carousel.current?.setPaused(!paused);
    setPaused(!paused);
  };

  return (
    <>
      <div ref={stageRef} className="comments-stage" aria-hidden="true">
        {cards.map((comment, index) => (
          <div
            key={`${comment.name}-${index}`}
            ref={(element) => {
              cardRefs.current[index] = element;
            }}
            className="comments-stage__slot"
            style={{ opacity: 0 }}
          >
            <article className="comment-card">
              <Quote className="comment-card__quote-mark" size={34} aria-hidden="true" />
              <div className="comment-card__author">
                <strong>{comment.name}</strong>
                <span>{comment.role}</span>
              </div>
              <p>{comment.quote}</p>
              <span className="comment-card__tag">{comment.tag}</span>
            </article>
          </div>
        ))}
      </div>

      <ul className="visually-hidden" aria-label="Comentarios de la comunidad">
        {comments.map((comment, index) => (
          <li key={`${comment.name}-${index}`}>
            {comment.name}, {comment.role}: {comment.quote}
          </li>
        ))}
      </ul>

      {comments.length > 1 && (
        <div className="comments-controls" aria-label="Controles de comentarios">
          <button
            type="button"
            className="comments-controls__button comments-controls__button--prev"
            onClick={() => carousel.current?.previous()}
            aria-label="Comentario anterior"
          >
            <ChevronLeft size={22} aria-hidden="true" />
          </button>
          <button
            type="button"
            className="comments-controls__button comments-controls__button--pause"
            onClick={togglePause}
            aria-label={paused ? 'Reanudar carrusel' : 'Pausar carrusel'}
            aria-pressed={paused}
          >
            {paused ? <Play size={18} aria-hidden="true" /> : <Pause size={18} aria-hidden="true" />}
          </button>
          <button
            type="button"
            className="comments-controls__button comments-controls__button--next"
            onClick={() => carousel.current?.next()}
            aria-label="Comentario siguiente"
          >
            <ChevronRight size={22} aria-hidden="true" />
          </button>
        </div>
      )}
    </>
  );
}
