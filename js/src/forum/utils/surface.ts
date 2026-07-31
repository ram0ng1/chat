import app from "flarum/forum/app";

/**
 * Where the chat should open: its own drawer, or the full-screen page.
 *
 * Four call sites asked this independently — the header button, the invite
 * notification, "start a chat" on a profile, and the drawer itself — and had
 * drifted into three different answers. One of them is now core's, so the drift
 * had started to matter rather than just being untidy.
 */
export function shouldUseChatDrawer(): boolean {
  // Core's own test, from `HeaderDropdown#onclick`, which is what
  // flarum/messages inherits: when Flarum's drawer is open we are on a phone with
  // the header rendered as a list of rows, and a control tapped there navigates
  // rather than opening a panel over the list it was tapped in.
  //
  // First, and not merged into the width test below, because it answers a
  // different question: not "is the viewport narrow" but "is the user currently
  // inside the navigation". Core keeps it honest across rotation and resize
  // through `Drawer#resizeHandler`.
  if (app.drawer.isOpen()) return false;

  if (app.session.user?.preferences()?.["ramon-chat.openInDrawer"] === false) {
    return false;
  }

  // Below this the drawer goes full-bleed anyway, and a "drawer" that covers the
  // whole screen while keeping drawer chrome is worse than the page it is
  // imitating. 767 is `@chat-mobile-breakpoint`, which is core's `@phone` bound —
  // the stylesheet switches the page to its single-pane layout at the same point.
  return window.innerWidth > 767;
}
