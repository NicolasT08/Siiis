/*
 * Carrusel en arco (traído del carrusel de testimonios de Orión Lex).
 * - Arranca solo cuando la sección se ve al 50 % (una sola vez): todas las tarjetas entran juntas
 *   desde la derecha, cada una con un pequeño retraso, y se acomodan en un arco suave (la del
 *   centro grande y recta; las de los lados más pequeñas, más abajo e inclinadas).
 * - Luego avanzan una posición a la izquierda, se detienen y vuelven a avanzar, sin fin: la que
 *   sale por un lado pasa (sin animación y fuera de la pantalla) al otro extremo.
 *   Quien llama debe pasar el set repetido hasta tener al menos MIN_CARDS tarjetas.
 * - Se puede arrastrar con el mouse o el dedo, mover con flechas y pausar.
 * - Solo transform y opacity; se recalcula al cambiar el tamaño y se pausa fuera de pantalla.
 * - Con "reducir movimiento" no se anima ni avanza solo.
 */

// ---- AJUSTES ----
/** Lo que tarda cada paso (ms). */
const MOVE_MS = 800;
/** Pausa entre un paso y el siguiente (ms). */
const PAUSE_MS = 1400;
/** Duración de la entrada inicial (ms). */
const INTRO_MS = 2200;
/** Retraso entre una tarjeta y la siguiente en la entrada (ms). */
const INTRO_STAGGER_MS = 60;
/** Espera entre el final de la entrada y el primer paso (ms). */
const LOOP_DELAY_MS = 1000;
/** Tras usar las flechas o arrastrar, espera antes de seguir solo (ms). */
const RESUME_MS = 3000;
/** Lo que hay que arrastrar (px) para pasar de tarjeta. */
const DRAG_THRESHOLD = 40;
/** Frenado suave al final de cada movimiento. */
const EASE = 'cubic-bezier(.22,1,.36,1)';
/** Escala de las tarjetas que no están en el centro. */
const SIDE_SCALE = 0.82;

/** Tarjetas visibles, separación entre centros (px), caída del arco (px) e inclinación (°). */
function settings(width: number) {
  if (width < 768) return { visible: 5, gap: width * 0.36, arc: 40, tilt: 3 };
  if (width < 1200) return { visible: 7, gap: width * 0.17, arc: 80, tilt: 4 };
  return { visible: 7, gap: Math.min(width, 2000) * 0.15, arc: 100, tilt: 5 };
}

/** Tarjetas necesarias para que el bucle no deje huecos (visibles + 3, con 7 visibles). */
export const MIN_CARDS = 10;

export interface ArcCarousel {
  next: () => void;
  previous: () => void;
  setPaused: (paused: boolean) => void;
  dispose: () => void;
}

export function startArcCarousel(stage: HTMLElement, cards: HTMLElement[]): ArcCarousel {
  const total = cards.length;
  const noop = () => {};
  if (total === 0) return { next: noop, previous: noop, setPaused: noop, dispose: noop };
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let s = settings(stage.clientWidth);
  let step = 0;
  let started = false;
  let onScreen = false;
  let paused = reduceMotion;
  let timer: number | undefined;
  let drag: { startX: number; dx: number; id: number } | undefined;

  const transition = (ms: number, delay = 0) => `transform ${ms}ms ${EASE} ${delay}ms, opacity 400ms`;

  // Posición según el "slot" (0 = extremo izquierdo visible); `extra` la corre en slots.
  // `horizontal`: solo cambia X (la entrada llega en línea recta desde la derecha).
  const place = (card: HTMLElement, slot: number, extra = 0, horizontal = false) => {
    const center = (s.visible - 1) / 2;
    const d = slot - center;
    const e = d + extra;
    const curve = horizontal ? d : e;
    const x = stage.clientWidth / 2 + e * s.gap;
    const y = (curve / center) ** 2 * s.arc;
    // Al arrastrar, la escala pasa poco a poco de 0,82 a 1 al acercarse al centro.
    const scale = 1 - (1 - SIDE_SCALE) * Math.min(Math.abs(curve), 1);
    card.style.transform = `translate(${x}px, ${y}px) rotate(${curve * s.tilt}deg) scale(${scale})`;
    card.style.zIndex = String(40 - Math.round(Math.abs(e) * 2));
    card.dataset.center = String(Math.abs(e) < 0.5);
  };

  // Slot de cada tarjeta en un paso: de -1 (fuera por la izquierda) a total - 2.
  const slotOf = (i: number, at: number) => ((((i - at) % total) + total) % total) - 1;
  const isShown = (slot: number) => slot >= 0 && slot <= s.visible;

  const layout = (animate: boolean, from = step) => {
    cards.forEach((card, i) => {
      const slot = slotOf(i, step);
      // Dio la vuelta (de un extremo al otro): se mueve sin animación, ya invisible.
      const wrapped = Math.abs(slot - slotOf(i, from)) > total / 2;
      card.style.transition = animate && !wrapped ? transition(MOVE_MS) : 'none';
      place(card, slot);
      card.style.opacity = isShown(slot) ? '1' : '0';
    });
  };

  const schedule = (ms: number) => {
    window.clearTimeout(timer);
    timer = undefined;
    if (paused || !onScreen || !started) return;
    timer = window.setTimeout(loop, ms);
  };

  const go = (by: number) => {
    const from = step;
    step += by;
    layout(true, from);
  };

  function loop() {
    timer = undefined;
    if (paused || !onScreen) return;
    go(1);
    schedule(MOVE_MS + PAUSE_MS);
  }

  const intro = () => {
    started = true;
    // 1) Todas fuera de la pantalla, a la derecha.
    cards.forEach((card, i) => {
      card.style.transition = 'none';
      place(card, slotOf(i, 0), s.visible + 2, true);
      card.style.opacity = '1';
    });
    void stage.offsetHeight; // fuerza el repintado antes de animar
    // 2) Entran juntas, cada una un poco después de la anterior.
    cards.forEach((card, i) => {
      const slot = slotOf(i, 0);
      card.style.transition = transition(INTRO_MS, i * INTRO_STAGGER_MS);
      place(card, slot);
      card.style.opacity = slot >= 0 && slot < s.visible ? '1' : '0';
    });
    // 3) Después, los pasos sin fin.
    schedule(INTRO_MS + LOOP_DELAY_MS);
  };

  // Antes de la entrada (o sin animaciones) las tarjetas esperan en su lugar o escondidas.
  if (reduceMotion) {
    started = true;
    layout(false);
  } else {
    cards.forEach((card) => (card.style.opacity = '0'));
  }

  // Usar las flechas o arrastrar adelanta la entrada si aún no había empezado.
  const ensureStarted = () => {
    if (started) return;
    trigger.disconnect();
    started = true;
    layout(false);
  };

  // ---- Arrastrar con el mouse o el dedo ----
  const onPointerDown = (event: PointerEvent) => {
    if (event.button !== 0) return;
    ensureStarted();
    drag = { startX: event.clientX, dx: 0, id: event.pointerId };
    window.clearTimeout(timer);
    timer = undefined;
  };
  const onPointerMove = (event: PointerEvent) => {
    if (!drag || event.pointerId !== drag.id) return;
    drag.dx = event.clientX - drag.startX;
    if (Math.abs(drag.dx) < 4) return;
    if (!stage.hasPointerCapture(event.pointerId)) stage.setPointerCapture(event.pointerId);
    // Las tarjetas siguen al dedo (como mucho una tarjeta y media).
    const extra = Math.max(-1.5, Math.min(1.5, drag.dx / s.gap));
    cards.forEach((card, i) => {
      const slot = slotOf(i, step);
      card.style.transition = 'none';
      place(card, slot, extra);
      card.style.opacity = isShown(slot) || isShown(slot + Math.sign(extra)) ? '1' : '0';
    });
  };
  const endDrag = (event: PointerEvent) => {
    if (!drag || event.pointerId !== drag.id) return;
    const { dx } = drag;
    drag = undefined;
    if (Math.abs(dx) < 4) return schedule(RESUME_MS); // fue un clic, no un arrastre
    const by = Math.abs(dx) < DRAG_THRESHOLD ? 0 : -Math.sign(dx) * Math.max(1, Math.round(Math.abs(dx) / s.gap));
    if (by === 0) layout(true);
    else go(by);
    schedule(RESUME_MS);
  };
  stage.addEventListener('pointerdown', onPointerDown);
  stage.addEventListener('pointermove', onPointerMove);
  stage.addEventListener('pointerup', endDrag);
  stage.addEventListener('pointercancel', endDrag);

  const trigger = new IntersectionObserver(
    ([entry]) => {
      if (!entry?.isIntersecting || started) return;
      trigger.disconnect();
      intro();
    },
    { threshold: 0.5 },
  );
  if (!reduceMotion) trigger.observe(stage.closest('section') ?? stage);

  // Fuera de pantalla no se mueve nada; al volver, sigue donde iba.
  const visibility = new IntersectionObserver(([entry]) => {
    onScreen = !!entry?.isIntersecting;
    if (onScreen && timer === undefined) schedule(PAUSE_MS);
  });
  visibility.observe(stage);

  const resize = new ResizeObserver(() => {
    s = settings(stage.clientWidth);
    if (started) layout(false);
  });
  resize.observe(stage);

  return {
    next: () => {
      ensureStarted();
      go(1);
      schedule(RESUME_MS);
    },
    previous: () => {
      ensureStarted();
      go(-1);
      schedule(RESUME_MS);
    },
    setPaused: (value) => {
      paused = value;
      if (paused) {
        window.clearTimeout(timer);
        timer = undefined;
      } else {
        ensureStarted();
        schedule(PAUSE_MS);
      }
    },
    dispose: () => {
      window.clearTimeout(timer);
      trigger.disconnect();
      visibility.disconnect();
      resize.disconnect();
      stage.removeEventListener('pointerdown', onPointerDown);
      stage.removeEventListener('pointermove', onPointerMove);
      stage.removeEventListener('pointerup', endDrag);
      stage.removeEventListener('pointercancel', endDrag);
    },
  };
}
