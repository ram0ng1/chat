import type ChatState from "../state/ChatState";

/**
 * How long the row stays highlighted after the jump. Mirrors
 * `@chat-jump-highlight` in the LESS, which governs the animation itself: the two
 * must move together, otherwise the class leaves before the animation ends (the
 * highlight vanishes midway) or stays after it (the row returns to normal and the
 * side bar stays lit).
 */
const HIGHLIGHT_MS = 3000;

/**
 * Centers a stream row and highlights it for a few seconds.
 *
 * The target is looked up inside the scroller that contains `from`: the topic
 * panel and the channel draw the same `ChatMessage`, and a document-wide lookup
 * would scroll the wrong stream when both are open.
 *
 * Returns `false` when the row is not in the loaded window, so the caller can
 * warn instead of swallowing the click.
 */
export function jumpToMessage(
  id: number | string,
  from?: HTMLElement | null,
): boolean {
  const scroller =
    from?.closest<HTMLElement>(
      ".ChatChannel-stream, .ChatThreadPanel-stream",
    ) ?? document.querySelector<HTMLElement>(".ChatChannel-stream");

  const node = scroller?.querySelector<HTMLElement>(
    `.ChatMessage[data-id="${id}"]`,
  );

  if (!scroller || !node) return false;

  // Deliberately not `scrollIntoView`: it scrolls *all* scrollable ancestors of
  // the node, the document included. On mobile that dragged the whole page down
  // just to move one row inside a container that was already on screen.
  //
  // Measured by rects rather than `offsetTop`, which is relative to the nearest
  // positioned ancestor and only matches the scroller's coordinate space by
  // accident of the current CSS.
  const nodeRect = node.getBoundingClientRect();
  const scrollerRect = scroller.getBoundingClientRect();
  const centred =
    nodeRect.top -
    scrollerRect.top -
    (scroller.clientHeight - nodeRect.height) / 2;

  // Smooth only for short jumps. Animating across hundreds of rows took seconds
  // before the message showed up; a long jump goes straight there, like Discord.
  scroller.scrollTo({
    top: Math.max(0, scroller.scrollTop + centred),
    behavior: Math.abs(centred) > scroller.clientHeight * 2 ? "auto" : "smooth",
  });

  highlight(node);

  return true;
}

/**
 * In-flight timers, per row. Without this, jumping twice to the same message
 * would let the first timer clear the highlight midway through the second.
 */
const timers = new WeakMap<HTMLElement, number>();

function highlight(node: HTMLElement): void {
  window.clearTimeout(timers.get(node));

  // Reapplying a class that is already there does not restart the animation.
  // Removing it, forcing a reflow by reading `offsetWidth` and putting it back is
  // what makes the second jump to the same row light up again instead of doing
  // nothing visible.
  node.classList.remove("ChatMessage--flash");
  void node.offsetWidth;
  node.classList.add("ChatMessage--flash");

  timers.set(
    node,
    window.setTimeout(() => {
      node.classList.remove("ChatMessage--flash");
      timers.delete(node);
    }, HIGHLIGHT_MS),
  );
}

/**
 * Jumps to a channel message, first loading the slice of history that separates
 * it from the window when it is older than what is already on screen.
 *
 * The scroller is re-read after loading because the redraw may have recreated
 * it. Returns `false` only when the message cannot be in the channel stream:
 * a topic reply, a deleted one, or beyond the page limit.
 */
export async function revealAndJump(
  state: ChatState,
  channelId: number,
  id: number | string,
  from?: HTMLElement | null,
): Promise<boolean> {
  if (jumpToMessage(id, from)) return true;

  const scrollerSelector = from?.closest(".ChatThreadPanel-stream")
    ? ".ChatThreadPanel-stream"
    : ".ChatChannel-stream";

  if (!(await state.revealMessage(channelId, Number(id)))) return false;

  m.redraw.sync();

  return jumpToMessage(
    id,
    document.querySelector<HTMLElement>(scrollerSelector),
  );
}
