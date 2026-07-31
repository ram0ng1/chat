import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import chatState from "../state/chat";
import ChatSidebar from "./ChatSidebar";
import ErrorBoundary from "./ErrorBoundary";
import ChannelView from "./ChannelView";
import PinnedPanel from "./PinnedPanel";
import ThreadPanel from "./ThreadPanel";
import { chatTitle, chatIcon } from "../utils/branding";

/**
 * The floating chat panel, pinned bottom-right over whatever page is open.
 *
 * Mounted once at the app root rather than per-page, so navigating the forum does
 * not tear down an open conversation — which is the entire point of a drawer.
 */
export default class ChatDrawer extends Component<ComponentAttrs> {
  view(): Mithril.Children {
    if (!chatState.drawerOpen) return null;

    const channel = chatState.channel(chatState.activeChannelId);
    const title = chatTitle();
    const icon = chatIcon();
    const unread = chatState.unreadSummary();

    return (
      <div
        className={classList("ChatDrawer", {
          "ChatDrawer--collapsed": chatState.drawerCollapsed,
        })}
        role="complementary"
        aria-label={title}
      >
        <div
          className="ChatDrawer-header"
          onclick={() => this.toggleCollapsed()}
        >
          {channel ? (
            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-chevron-left"
              onclick={(e: Event) => {
                e.stopPropagation();
                chatState.setActiveChannel(null);
              }}
            />
          ) : icon ? (
            <i className={icon} aria-hidden="true" />
          ) : null}

          <span className="ChatDrawer-title">
            {channel ? channel.displayName() : title}
          </span>

          {/* Collapsed, the drawer is a bar with no stream to look at — so unread
              activity has to surface on the header itself or it goes unnoticed.
              Both cases show a number: a dot says "something happened" and leaves
              you to open the drawer to find out how much. Mentions keep their own
              colour because they are addressed to you specifically. */}
          {chatState.drawerCollapsed && unread.mentions > 0 ? (
            <span
              className="ChatDrawer-badge ChatDrawer-badge--mention"
              title={app.translator.trans(
                "ramon-chat.forum.nav.unread_mentions",
                { count: unread.mentions },
                true,
              )}
            >
              {unread.mentions > 99 ? "99+" : unread.mentions}
            </span>
          ) : chatState.drawerCollapsed && unread.messages > 0 ? (
            <span
              className="ChatDrawer-badge"
              title={app.translator.trans(
                "ramon-chat.forum.nav.unread_messages",
                { count: unread.messages },
                true,
              )}
            >
              {unread.messages > 99 ? "99+" : unread.messages}
            </span>
          ) : null}

          <div className="ChatDrawer-actions">
            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-up-right-and-down-left-from-center"
              title={app.translator.trans(
                "ramon-chat.forum.drawer.full_screen",
                {},
                true,
              )}
              onclick={(e: Event) => {
                e.stopPropagation();
                this.goFullScreen();
              }}
            />
            <Button
              className="Button Button--icon Button--flat"
              icon={
                chatState.drawerCollapsed
                  ? "fas fa-chevron-up"
                  : "fas fa-chevron-down"
              }
              title={app.translator.trans(
                chatState.drawerCollapsed
                  ? "ramon-chat.forum.drawer.expand"
                  : "ramon-chat.forum.drawer.collapse",
                {},
                true,
              )}
              onclick={(e: Event) => {
                e.stopPropagation();
                this.toggleCollapsed();
              }}
            />
            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-times"
              title={app.translator.trans(
                "ramon-chat.forum.drawer.close",
                {},
                true,
              )}
              onclick={(e: Event) => {
                e.stopPropagation();
                this.close();
              }}
            />
          </div>
        </div>

        {chatState.drawerCollapsed ? null : (
          <div className="ChatDrawer-body">
            <ErrorBoundary area="drawer">{this.body(channel)}</ErrorBoundary>
          </div>
        )}
      </div>
    );
  }

  /**
   * The drawer's contents: the conversation, or the channel list, plus the pinned
   * panel over the top when it is open.
   *
   * Built as an array rather than a JSX fragment with a conditional slot. Mithril
   * decides a fragment is keyed from its first child and then demands every other
   * child be keyed too — and it counts `null` as unkeyed. `[<ChannelView key=…/>,
   * null]`, which is what a closed pinned panel produced, therefore threw "In
   * fragments, vnodes must either all have keys or none have keys" on every redraw.
   * The drawer is mounted at the app root, so that fired on every page of the forum,
   * not only inside the chat.
   */
  protected body(channel: Channel | null): Mithril.Children {
    const panes: Mithril.Children[] = [
      channel ? (
        <ChannelView
          key={`channel-${channel.id()}`}
          channel={channel}
          state={chatState}
          embedded
        />
      ) : (
        <ChatSidebar
          key="sidebar"
          state={chatState}
          onSelect={(c: Channel) => this.select(c)}
        />
      ),
    ];

    // A thread takes the same overlay slot as the pinned list. Only one of the two
    // can be open: opening a thread is what the reply indicator does, and the
    // pinned toggle clears the thread, so they cannot both claim the space.
    if (channel && chatState.activeThreadId !== null) {
      panes.push(
        <ThreadPanel
          key={`thread-${chatState.activeThreadId}`}
          channel={channel}
          threadId={chatState.activeThreadId}
          state={chatState}
          onClose={() => {
            chatState.closeThread();
            m.redraw();
          }}
        />,
      );

      return panes;
    }

    // The drawer has no room for a side panel, so the pinned list covers the
    // conversation instead — the arrangement the thread panel uses on a phone.
    if (channel && chatState.showPinned) {
      panes.push(
        <PinnedPanel
          key={`pinned-${channel.id()}`}
          channel={channel}
          state={chatState}
          embedded
          onClose={() => {
            chatState.showPinned = false;
            m.redraw();
          }}
        />,
      );
    }

    return panes;
  }

  protected select(channel: Channel): void {
    chatState.setActiveChannel(Number(channel.id()));
    m.redraw();
  }

  protected toggleCollapsed(): void {
    chatState.setDrawerCollapsed(!chatState.drawerCollapsed);
    m.redraw();
  }

  /**
   * The only way to close the drawer. Navigating the forum, reloading the page and
   * collapsing the header all leave it open — dismissal has to be deliberate.
   */
  protected close(): void {
    chatState.setDrawerOpen(false);
    m.redraw();
  }

  /**
   * Hands the current channel over to the full-screen page. The drawer closes so
   * the two never render the same conversation at once.
   *
   * Suspended rather than closed: the user did not dismiss the chat, they moved it,
   * so leaving the full-screen page puts the drawer back where it was. See
   * ChatPage.onremove, which is what restores it.
   */
  protected goFullScreen(): void {
    const id = chatState.activeChannelId;

    chatState.suspendDrawer();

    m.route.set(
      id ? app.route("chat.channel", { id }) : app.route("chat.index"),
    );
  }

  /**
   * Opens the drawer, loading the channel list on first open.
   */
  static async open(): Promise<void> {
    chatState.setDrawerOpen(true);
    chatState.setDrawerCollapsed(false);

    if (!chatState.channelsLoaded) {
      // loadDrafts() is a no-op for a guest; see ChatState.
      await Promise.all([chatState.loadChannels(), chatState.loadDrafts()]);
    }

    m.redraw();
  }
}
