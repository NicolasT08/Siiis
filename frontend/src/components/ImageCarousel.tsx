import { useEffect, useRef, useState } from 'react';
import { api } from '../lib/api';
import { imagenCloudinary, srcsetCloudinary } from '../lib/cloudinary';

import { Images } from 'lucide-react';

const AUTO_PLAY_MS = 4000;
const RESUME_DELAY_MS = 5000;
const DRAG_THRESHOLD = 60;

type Foto = { url: string; descripcion: string };

/**
 * Anchos que se piden a Cloudinary (c_limit): el carrusel mide como máximo 1060 px (.gallery-box de 1100 px menos
 * 20 px de margen a cada lado), así la foto original de 4000 px nunca se descarga completa.
 */
const ANCHOS = [480, 768, 1080, 1440, 2120];
const SIZES = '(max-width: 1100px) calc(100vw - 40px), 1060px';

function FotoCarrusel({ src, alt, cargar }: { src: string; alt: string; cargar: 'eager' | 'lazy' }) {
  return (
    <img
      src={imagenCloudinary(src, 1080)}
      srcSet={srcsetCloudinary(src, ANCHOS) || undefined}
      sizes={SIZES}
      alt={alt}
      loading={cargar}
      decoding="async"
      draggable={false}
    />
  );
}

/**
 * Carrusel de la página de inicio con las fotos de `GET /home/slider` (Multimedia, sección slider_principal).
 * Sin fotos en la base (o si la API falla) muestra un estado vacío discreto: ya no hay imágenes de respaldo fijas;
 * las fotos se cargan con scripts/cargar_multimedia.php o desde Administración → Inicio (DEC-B60, DEC-B62).
 */
export default function ImageCarousel() {
  const [fotos, setFotos] = useState<Foto[] | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    api
      .slider(controller.signal)
      .then(({ data }) =>
        setFotos(data.filter((item) => item.tipo === 'imagen' && item.url).map((item) => ({ url: item.url, descripcion: item.descripcion ?? '' }))),
      )
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        setFotos([]);
      });
    return () => controller.abort();
  }, []);

  if (fotos === null) return <div className="carousel carousel--loading skeleton" aria-label="Cargando fotos" />;
  if (fotos.length === 0) {
    return (
      <div className="carousel-empty" role="status">
        <Images size={26} aria-hidden="true" />
        <p>Pronto compartiremos aquí fotos de las actividades del semillero.</p>
      </div>
    );
  }
  return <Diapositivas fotos={fotos} />;
}

function Diapositivas({ fotos }: { fotos: Foto[] }) {
  const images = fotos.map((f) => f.url);
  const descripciones = Object.fromEntries(fotos.map((f) => [f.url, f.descripcion]));
  const [index, setIndex] = useState(1);
  const [isPaused, setIsPaused] = useState(false);
  const [isDragging, setIsDragging] = useState(false);
  const [dragOffset, setDragOffset] = useState(0);
  const [barKey, setBarKey] = useState(0);
  const dragStartRef = useRef<number | null>(null);

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

  // Flechas del teclado solo con el foco en el carrusel (antes se escuchaban en toda la página).
  const handleKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'ArrowRight') next();
    if (event.key === 'ArrowLeft') prev();
  };

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
            <FotoCarrusel src={images[0]} alt={descripciones[images[0]] || 'Foto del semillero'} cargar="eager" />
          </div>
        </div>
      </div>
    );
  }

  return (
    <div
      className="carousel"
      id="carousel"
      role="region"
      aria-roledescription="carrusel"
      aria-label="Carrusel de imágenes (usa las flechas del teclado)"
      tabIndex={0}
      onKeyDown={handleKeyDown}
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
            {/* Solo la diapositiva visible y sus vecinas se descargan de inmediato; el resto, al acercarse. */}
            <FotoCarrusel
              src={src}
              alt={descripciones[src] || `Foto ${i + 1} del semillero`}
              cargar={Math.abs(i - index) <= 1 ? 'eager' : 'lazy'}
            />
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
