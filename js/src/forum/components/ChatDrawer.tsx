import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import chatState from "../state/chat";
import { loadChatUi } from "../utils/lazy";
import { isNarrowViewport } from "../utils/surface";
import { morphIn } from "../utils/morph";

type Panel = Awaited<ReturnType<typeof loadChatUi>>["ChatDrawerPanel"];

let panel: Panel | null = null;
let pending: Promise<void> | null = null;

/**
 * Fetches the drawer panel, which lives in the lazy interface chunk. Called on
 * hover of the header button, on open, and when restoring a drawer left open;
 * concurrent requests collapse into one.
 */
export function loadDrawerPanel(): Promise<void> {
  pending ??= loadChatUi().then(
    (ui) => {
      panel = ui.ChatDrawerPanel;
      m.redraw();
    },
    (error) => {
      pending = null;
      throw error;
    },
  );

  return pending;
}

/**
 * The drawer shell that stays in the initial bundle.
 *
 * Mounted once at the app root, as before, but without loading the conversation:
 * closed it draws nothing, and opened before the chunk arrives it shows the
 * drawer box with a spinner. The real panel (ChatDrawerPanel) takes over as soon
 * as the chunk resolves; the morph from the button goes to whichever appears
 * first.
 */
export default class ChatDrawer extends Component<ComponentAttrs> {
  /** Whether the placeholder box was on screen at the last render. */
  private placeholderShown = false;

  oncreate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void {
    super.oncreate(vnode);

    this.morphPlaceholder(vnode.dom);
  }

  onupdate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void {
    super.onupdate(vnode);

    this.morphPlaceholder(vnode.dom);
  }

  /**
   * While the chunk has not arrived, the placeholder box is what grows from the
   * button; it consumes the origin, and the panel that replaces it enters without
   * repeating the animation.
   */
  protected morphPlaceholder(dom: Element | null): void {
    const box =
      dom instanceof HTMLElement &&
      dom.classList.contains("ChatDrawer--loading")
        ? dom
        : null;

    if (box && !this.placeholderShown) morphIn(box, "drawer");

    this.placeholderShown = box !== null;
  }

  view(): Mithril.Children {
    if (!chatState.drawerOpen) return null;

    if (panel) {
      const Panel = panel;

      return <Panel />;
    }

    loadDrawerPanel().catch(() => {});

    if (isNarrowViewport()) return null;

    return (
      <div
        className={classList("ChatDrawer ChatDrawer--loading", {
          "ChatDrawer--collapsed": chatState.drawerCollapsed,
        })}
        role="complementary"
        aria-busy="true"
      >
        <LoadingIndicator />
      </div>
    );
  }

  /**
   * Opens the drawer, loading the channel list on first open.
   *
   * The redraw comes before the load: waiting for the list left the drawer
   * invisible for a full round trip, which feels like a slow button. The sidebar
   * draws the skeleton in the meantime.
   */
  static async open(): Promise<void> {
    chatState.setDrawerOpen(true);
    chatState.setDrawerCollapsed(false);

    loadDrawerPanel().catch(() => {});

    m.redraw();

    if (chatState.channelsLoaded) return;

    await Promise.all([chatState.loadChannels(), chatState.loadDrafts()]).catch(
      () => {},
    );
  }
}
