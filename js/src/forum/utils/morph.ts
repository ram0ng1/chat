/**
 * Morph between two chat surfaces (FLIP), in the same style as the Avocado
 * theme's floating composer: the new surface is born from the rectangle of the
 * one that opened it (size, corners and background interpolated) and settles
 * with a slight overshoot, while the contents cascade in on landing.
 *
 * The leaving side records the origin with `rememberOrigin`; the arriving side
 * consumes it with `morphIn`. The origin is valid only briefly and only for the
 * named target, so an unrelated navigation never inherits a stale animation.
 */

export type MorphTarget = "page" | "drawer";

interface Origin {
  target: MorphTarget;
  rect: DOMRect;
  radius: string;
  bg: string;
  shadow: string;
  at: number;
}

const MORPH_MS = 520;
const CONTENT_DELAY_MS = 260;
const ORIGIN_TTL_MS = 1500;

const EASE_LAND = "cubic-bezier(0.2, 1.12, 0.32, 1)";
const EASE_OUT = "cubic-bezier(0.4, 0, 0.2, 1)";

let origin: Origin | null = null;

export const reducedMotion = (): boolean =>
  window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;

/**
 * Records the element opening `target` as the starting point. An element with
 * no box (hidden, unmounted) records nothing.
 */
export function rememberOrigin(
  el: Element | null | undefined,
  target: MorphTarget,
): void {
  if (!(el instanceof HTMLElement)) return;

  const rect = el.getBoundingClientRect();

  if (rect.width === 0 || rect.height === 0) return;

  const cs = getComputedStyle(el);

  origin = {
    target,
    rect,
    radius: cs.borderTopLeftRadius,
    bg: cs.backgroundColor,
    shadow: cs.boxShadow,
    at: Date.now(),
  };
}

/**
 * Takes `el` from the recorded rectangle to its own place. Returns whether it
 * animated; with no recent origin for this target, or with reduced motion, it
 * does nothing.
 */
export function morphIn(
  el: Element | null | undefined,
  target: MorphTarget,
  contentDelay = CONTENT_DELAY_MS,
): boolean {
  const from = origin;

  if (!from || from.target !== target) return false;

  origin = null;

  if (!(el instanceof HTMLElement) || Date.now() - from.at > ORIGIN_TTL_MS)
    return false;
  if (reducedMotion() || typeof el.animate !== "function") return false;

  const home = el.getBoundingClientRect();

  if (home.width === 0 || home.height === 0) return false;

  const sx = from.rect.width / home.width;
  const sy = from.rect.height / home.height;
  const radius = parseFloat(from.radius) || 0;
  const own = getComputedStyle(el);

  el.animate(
    [
      {
        transformOrigin: "top left",
        transform: `translate(${from.rect.left - home.left}px, ${from.rect.top - home.top}px) scale(${sx}, ${sy})`,
        borderRadius: `${radius / sx}px / ${radius / sy}px`,
        backgroundColor: from.bg,
        boxShadow: from.shadow,
      },
      {
        transformOrigin: "top left",
        transform: "none",
        borderRadius: own.borderTopLeftRadius,
        backgroundColor: own.backgroundColor,
        boxShadow: own.boxShadow,
      },
    ],
    { duration: MORPH_MS, easing: EASE_LAND },
  );

  cascadeIn(el, contentDelay);

  return true;
}

/** The contents cascading in when the surface is almost landed. */
export function cascadeIn(el: HTMLElement, delay = CONTENT_DELAY_MS): void {
  if (reducedMotion() || typeof el.animate !== "function") return;

  Array.from(el.children).forEach((child, i) =>
    child.animate(
      [
        { opacity: 0, transform: "translateY(6px)" },
        { opacity: 1, transform: "none" },
      ],
      {
        duration: 240,
        delay: delay + i * 40,
        easing: EASE_OUT,
        fill: "backwards",
      },
    ),
  );
}
