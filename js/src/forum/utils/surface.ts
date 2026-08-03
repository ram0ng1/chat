import app from "flarum/forum/app";

/**
 * The width below which the chat has no room for two panes side by side.
 *
 * 767 is `@chat-mobile-breakpoint`, which is core's `@phone` bound. Kept here
 * rather than repeated as a literal at each call site: the number appeared in
 * three files and in the stylesheet, and a breakpoint that only *mostly* agrees
 * with itself produces layouts nobody can reproduce.
 */
export const CHAT_MOBILE_BREAKPOINT = 767;

/** Whether the viewport is narrow enough for the chat's single-pane layout. */
export function isNarrowViewport(): boolean {
  return window.innerWidth <= CHAT_MOBILE_BREAKPOINT;
}

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

  // Below this there is no room for a panel over the page: the drawer would fill
  // the screen while keeping drawer chrome, which is worse than the full-screen
  // page it would be imitating. An already-open drawer that ends up here — from a
  // rotation, or restored from a previous desktop session — becomes the floating
  // button instead; see ChatDrawer.
  return !isNarrowViewport();
}
