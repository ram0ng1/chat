import app from "flarum/forum/app";

import chatState from "../state/chat";
import ChatDrawer from "../components/ChatDrawer";

/**
 * Global chat shortcuts.
 *
 * Deliberately few and all modified: an unmodified single-letter shortcut on a page
 * that is mostly text entry is a trap, so the only bare key handled is Escape, and
 * only when the chat itself owns the current mode.
 *
 *   Alt+C          toggle the drawer
 *   Alt+Shift+C    go to the full-screen chat
 *   Alt+K          jump to search, scoped to the open channel
 *   Escape         leave selection mode, then close the thread/pinned panel
 *
 * Alt rather than Ctrl/Cmd throughout: the browser and the OS have prior claim on
 * almost every Ctrl combination, and Alt+letter is what Discord and Slack use for
 * their own navigation.
 */
export function bindShortcuts(): void {
  document.addEventListener("keydown", onKeyDown);
}

function onKeyDown(e: KeyboardEvent): void {
  // Never steal a keystroke from an input. `isContentEditable` covers the rich
  // composer, which is a div rather than a textarea.
  const target = e.target as HTMLElement | null;
  const typing =
    !!target &&
    (target.tagName === "INPUT" ||
      target.tagName === "TEXTAREA" ||
      target.tagName === "SELECT" ||
      target.isContentEditable);

  if (e.key === "Escape") {
    // Escape is allowed while typing only for chat modes the composer does not
    // already handle; ChatComposer stops propagation for its own use of it.
    if (handleEscape()) {
      e.preventDefault();
    }

    return;
  }

  if (typing || !e.altKey || e.ctrlKey || e.metaKey) return;

  const key = e.key.toLowerCase();

  if (key === "c") {
    e.preventDefault();

    if (e.shiftKey) {
      m.route.set(
        chatState.activeChannelId
          ? app.route("chat.channel", { id: chatState.activeChannelId })
          : app.route("chat.index"),
      );

      return;
    }

    if (chatState.drawerOpen) {
      chatState.setDrawerOpen(false);
    } else {
      ChatDrawer.open();
    }

    m.redraw();

    return;
  }

  if (key === "k") {
    e.preventDefault();

    m.route.set(
      chatState.activeChannelId
        ? app.route("chat.search", { channel: chatState.activeChannelId })
        : app.route("chat.search"),
    );
  }
}

/**
 * Unwinds one layer of chat state. Returns whether anything was actually closed,
 * so Escape falls through to the rest of the page when the chat has no mode open.
 */
function handleEscape(): boolean {
  if (chatState.selecting) {
    chatState.selecting = false;
    chatState.selected.clear();
    m.redraw();

    return true;
  }

  if (chatState.activeThreadId !== null) {
    chatState.closeThread();
    m.redraw();

    return true;
  }

  if (chatState.showPinned) {
    chatState.showPinned = false;
    m.redraw();

    return true;
  }

  return false;
}
