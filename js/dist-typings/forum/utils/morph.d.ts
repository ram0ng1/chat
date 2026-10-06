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
export declare const reducedMotion: () => boolean;
/**
 * Records the element opening `target` as the starting point. An element with
 * no box (hidden, unmounted) records nothing.
 */
export declare function rememberOrigin(el: Element | null | undefined, target: MorphTarget): void;
/**
 * Takes `el` from the recorded rectangle to its own place. Returns whether it
 * animated; with no recent origin for this target, or with reduced motion, it
 * does nothing.
 */
export declare function morphIn(el: Element | null | undefined, target: MorphTarget, contentDelay?: number): boolean;
/** The contents cascading in when the surface is almost landed. */
export declare function cascadeIn(el: HTMLElement, delay?: number): void;
