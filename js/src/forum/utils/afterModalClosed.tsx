import app from 'flarum/forum/app';

/**
 * Runs a callback once no modal is on screen.
 *
 * Core's `ModalManager.animateHide()` guards itself with a `modalClosing` latch
 * that is only cleared from a `transitionend` handler on the dialog element. If
 * anything interrupts that transition — most easily a route change in the same
 * tick as `hide()` — the event never arrives, the latch stays set, and from then
 * on `animateHide()` returns immediately for every modal. The symptom is a close
 * button that silently does nothing until the page is reloaded.
 *
 * So a caller that wants to navigate after closing a modal has to wait for the
 * close to finish. This waits on observable state — the manager's own modal list
 * emptying — rather than on a hardcoded delay that would have to track core's
 * animation duration.
 *
 * @param callback What to run once the screen is clear.
 * @param maxFrames Bound on the wait, so a modal that never closes cannot strand
 *                  the callback forever. At ~60fps this is comfortably longer
 *                  than core's 200ms transition.
 */
export default function afterModalClosed(callback: () => void, maxFrames = 40): void {
  let frames = 0;

  const tick = () => {
    const stillOpen = (app.modal as any)?.modalList?.length > 0;

    if (!stillOpen || frames++ >= maxFrames) {
      callback();

      return;
    }

    requestAnimationFrame(tick);
  };

  // One frame minimum: `hide()` has only just been called, so the list has not
  // been updated yet and checking synchronously would always report "clear".
  requestAnimationFrame(tick);
}
