/**
 * Runs a callback once no modal is on screen, and unsticks core's close latch if
 * the modal never finishes closing.
 *
 * `ModalManager.animateHide()` sets a `modalClosing` flag and only clears it from
 * a `transitionend` handler on the dialog element. The same handler is what calls
 * `state.close()`, so if that event never arrives, two things are true at once:
 * the modal list never empties, and every later `animateHide()` returns
 * immediately. The symptom is a close button that silently does nothing — on that
 * modal and on every modal opened afterwards — until the page is reloaded.
 *
 * The event goes missing more easily than it sounds: a route change in the same
 * tick as `hide()` interrupts the transition, and so does any environment where
 * the transition never runs at all — a reduced-motion setting, a theme that
 * dropped it, a backgrounded tab.
 *
 * Waiting on the modal list handles the ordinary case. The synthetic event
 * handles the stuck one: the listener core registered is `{ once: true }` and
 * reads nothing off the event, so dispatching a bare `transitionend` at the
 * dialog runs exactly the cleanup that was owed, and leaves the real event — if
 * it ever arrives — with nothing left to do.
 *
 * @param callback What to run once the screen is clear.
 * @param maxFrames Bound on the wait. At ~60fps this is comfortably longer than
 *                  core's 200ms transition.
 */
export default function afterModalClosed(callback: () => void, maxFrames?: number): void;
