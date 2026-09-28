import { useEffect, useRef, useState } from 'react';
import { api } from '../lib/api';

// Respaldo si GET /home/slider falla o no trae imágenes.
const FALLBACK_IMAGES = [
  'https://res.cloudinary.com/we3ya7sq/image/upload/w_1600,q_auto:best,f_auto,e_sharpen:50,e_saturation:8/v1789084069/Foto_1.jpg',
  'https://res.cloudinary.com/we3ya7sq/image/upload/w_1600,q_auto:best,f_auto,e_sharpen:50,e_saturation:8/v1789084067/Foto_2.jpg',
  'https://res.cloudinary.com/we3ya7sq/image/upload/w_1600,q_auto:best,f_auto,e_sharpen:50,e_saturation:8/v1789084067/Foto_3.jpg',
  'https://res.cloudinary.com/we3ya7sq/image/upload/w_1600,q_auto:best,f_auto,e_sharpen:50,e_saturation:8/v1789084068/Foto_4.jpg',
  'https://res.cloudinary.com/we3ya7sq/image/upload/w_1600,q_auto:best,f_auto,e_sharpen:50,e_saturation:8/v1789084068/Foto_5.jpg',
  'https://res.cloudinary.com/we3ya7sq/image/upload/w_1600,q_auto:best,f_auto,e_sharpen:50,e_saturation:8/v1789084070/Foto_6.jpg',
  'https://res.cloudinary.com/we3ya7sq/image/upload/w_1600,q_auto:best,f_auto,e_sharpen:50,e_saturation:8/v1789084071/Foto_7.jpg',
  'https://res.cloudinary.com/we3ya7sq/image/upload/w_1600,q_auto:best,f_auto,e_sharpen:50,e_saturation:8/v1789084073/Foto_8.jpg',
];

const AUTO_PLAY_MS = 4000;
const RESUME_DELAY_MS = 5000;
const DRAG_THRESHOLD = 60;

export default function ImageCarousel() {
  const [images, setImages] = useState<string[]>(FALLBACK_IMAGES);
  const [index, setIndex] = useState(1);
  const [isPaused, setIsPaused] = useState(false);
  const [isDragging, setIsDragging] = useState(false);
  const [dragOffset, setDragOffset] = useState(0);
  const [barKey, setBarKey] = useState(0);
  const dragStartRef = useRef<number | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    api
      .slider(controller.signal)
      .then(({ data }) => {
        const urls = data.filter((item) => item.tipo === 'imagen' && item.url).map((item) => item.url);
        if (urls.length) {
          setImages(urls);
          setIndex(1);
          setBarKey((prev) => prev + 1);
        }
      })
      .catch(() => {
        // Se quedan las imágenes de respaldo.
      });
    return () => controller.abort();
  }, []);

  const singleImage = images.length === 1;
  const slides = [images[images.length - 1], ...images, images[0]];
  const currentIndex = ((index - 1) % images.length + images.length) % images.length;

  const goTo = (nextIndex: number) => {
    setIndex(nextIndex);
    setBarKey((prev) => prev + 1);
  };

  const next = () => goTo(index + 1);
  const prev = () => goTo(index - 1);

  useEffect(() => {
    if (singleImage || isPaused) return;

    const timer = window.setTimeout(() => {
      setIndex((prev) => prev + 1);
      setBarKey((prev) => prev + 1);
    }, AUTO_PLAY_MS);

    return () => window.clearTimeout(timer);
  }, [index, isPaused, singleImage]);

  useEffect(() => {
    if (index === 0) {
      const timeout = window.setTimeout(() => {
        setIndex(images.length);
      }, 350);
      return () => window.clearTimeout(timeout);
    }

    if (index === images.length + 1) {
      const timeout = window.setTimeout(() => {
        setIndex(1);
      }, 350);
      return () => window.clearTimeout(timeout);
    }

    return undefined;
  }, [index, images.length]);

  useEffect(() => {
    const handleVisibility = () => {
      if (document.hidden) {
        setIsPaused(true);
      } else {
        setIsPaused(false);
      }
    };

    document.addEventListener('visibilitychange', handleVisibility);
    return () => document.removeEventListener('visibilitychange', handleVisibility);
  }, []);

  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'ArrowRight') {
        next();
      }
      if (event.key === 'ArrowLeft') {
        prev();
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [index]);

  const handlePointerDown = (event: React.PointerEvent<HTMLDivElement>) => {
    if (event.pointerType === 'mouse' && event.button !== 0) return;

    dragStartRef.current = event.clientX;
    setIsDragging(true);
    setIsPaused(true);
  };

  const handlePointerMove = (event: React.PointerEvent<HTMLDivElement>) => {
    if (!isDragging || dragStartRef.current === null) return;
    setDragOffset(event.clientX - dragStartRef.current);
  };

  const handlePointerEnd = () => {
    if (!isDragging) return;

    if (dragOffset > DRAG_THRESHOLD) {
      prev();
    } else if (dragOffset < -DRAG_THRESHOLD) {
      next();
    }

    dragStartRef.current = null;
    setIsDragging(false);
    setDragOffset(0);

    window.setTimeout(() => {
      setIsPaused(false);
    }, RESUME_DELAY_MS);
  };

  const trackTransform = isDragging
    ? `translate3d(calc(${-index * 100}% + ${dragOffset}px), 0, 0)`
    : `translate3d(${-index * 100}%, 0, 0)`;

  if (singleImage) {
    return (
      <div className="carousel carousel--single" aria-label="Carrusel de imágenes">
        <div className="carousel-track" style={{ transform: 'translate3d(0%, 0, 0)' }}>
          <div className="carousel-slide">
            <img src={images[0]} alt="Foto del semillero" draggable={false} />
          </div>
        </div>
      </div>
    );
  }

  return (
    <div
      className="carousel"
      id="carousel"
      aria-label="Carrusel de imágenes"
      onMouseEnter={() => setIsPaused(true)}
      onMouseLeave={() => {
        setIsPaused(false);
        setBarKey((prev) => prev + 1);
      }}
    >
      <div
        className="carousel-track"
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={handlePointerEnd}
        onPointerLeave={handlePointerEnd}
        onPointerCancel={handlePointerEnd}
        style={{ transform: trackTransform, transition: isDragging ? 'none' : 'transform 0.5s cubic-bezier(.4,0,.2,1)' }}
      >
        {slides.map((src, i) => (
          <div className="carousel-slide" key={`${src}-${i}`}>
            <img src={src} alt={`Foto ${i + 1}`} draggable={false} />
          </div>
        ))}
      </div>

      <button type="button" className="carousel-arrow carousel-arrow--prev" aria-label="Anterior" onClick={prev}>
        <svg viewBox="0 0 24 24" width="22" height="22">
          <path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>

      <button type="button" className="carousel-arrow carousel-arrow--next" aria-label="Siguiente" onClick={next}>
        <svg viewBox="0 0 24 24" width="22" height="22">
          <path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>

      <div className="carousel-dots" aria-label="Selector de imágenes">
        {images.map((_, dotIndex) => (
          <button
            type="button"
            key={dotIndex}
            className={`carousel-dot${dotIndex === currentIndex ? ' is-active' : ''}`}
            aria-label={`Ir a la imagen ${dotIndex + 1}`}
            aria-current={dotIndex === currentIndex ? 'true' : 'false'}
            onClick={() => goTo(dotIndex + 1)}
          />
        ))}
      </div>

      <div className="carousel-counter" id="carouselCounter">
        {currentIndex + 1} / {images.length}
      </div>

      <div className="carousel-progress" aria-hidden="true">
        <div key={barKey} className="carousel-progress-bar" style={{ animation: isPaused ? 'none' : 'progressFill 4s linear forwards' }} />
      </div>
    </div>
  );
}
