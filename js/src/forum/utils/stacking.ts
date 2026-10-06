/**
 * Who sits on top when the chat drawer and the forum's composer overlap.
 *
 * The drawer is stacked just above the composer by default (see `.ChatDrawer`
 * in components.less): a reply bubble left parked at the bottom of the screen
 * must not cover the conversation. The moment the reader works in the composer
 * — focus or a press inside it, the bubble, or the theme's floating card — the
 * composer comes forward instead, and it steps back as soon as they act
 * anywhere else. Press as well as focus, because dragging a resize handle or
 * clicking a toolbar gap moves no focus at all.
 */
const COMPOSER =
  "#composer, #avocado-reply-dock, .AvocadoComposerDock, .AvocadoReplyDock";

const FRONT_CLASS = "ChatComposerFront";

let installed = false;

export function installComposerStacking(): void {
  if (installed) return;

  installed = true;

  const update = (target: EventTarget | null) => {
    const inComposer =
      target instanceof Element && target.closest(COMPOSER) !== null;

    document.documentElement.classList.toggle(FRONT_CLASS, inComposer);
  };

  document.addEventListener("pointerdown", (e) => update(e.target), true);
  document.addEventListener("focusin", (e) => update(e.target), true);
}
