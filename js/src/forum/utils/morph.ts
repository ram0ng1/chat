/**
 * Metamorfose entre duas superfícies do chat (FLIP), no mesmo desenho do
 * compositor flutuante do tema Avocado: a superfície nova nasce do retângulo da
 * que a abriu — tamanho, cantos e fundo interpolados — e assenta com um leve
 * passar do ponto, enquanto o miolo entra em cascata no pouso.
 *
 * Quem sai grava a origem com `rememberOrigin`; quem chega a consome com
 * `morphIn`. A origem vale por pouco tempo e só para o destino nomeado, para que
 * uma navegação sem relação nunca herde uma animação velha.
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
 * Grava o elemento que está abrindo `target` como ponto de partida. Um elemento
 * sem caixa (escondido, desmontado) não grava nada.
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
 * Leva `el` do retângulo gravado até o lugar dele. Devolve se animou; sem origem
 * recente para este destino, ou com movimento reduzido, não faz nada.
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

/** O miolo entrando em cascata quando a superfície está quase pousada. */
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
