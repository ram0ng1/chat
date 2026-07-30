import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import type { IPageAttrs } from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import chatState from '../state/chat';
import ChatSidebar from './ChatSidebar';
import ChannelView from './ChannelView';
import ThreadPanel from './ThreadPanel';
import PinnedPanel from './PinnedPanel';
import ThreadsList from './ThreadsList';
import ChatSearch from './ChatSearch';

/**
 * Full-screen chat.
 *
 * Sidebar plus channel side by side on desktop; on mobile the router shows one or
 * the other, since a 280px sidebar beside a channel is unusable at phone widths.
 */
export default class ChatPage<CustomAttrs extends IPageAttrs = IPageAttrs> extends Page<CustomAttrs> {
  private loading = true;

  oninit(vnode: Mithril.Vnode<CustomAttrs>): void {
    super.oninit(vnode);

    app.setTitle(app.translator.trans('ramon-chat.forum.nav.chat', {}, true));

    // The drawer and the page are mutually exclusive views of the same state.
    chatState.setDrawerOpen(false);

    this.boot();
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<CustomAttrs, this>): void {
    super.onbeforeupdate(vnode);

    this.syncFromRoute();
  }

  /**
   * Brings the active channel and thread back in line with the URL.
   *
   * Every chat route shares one mounted page (see ChatPageResolver), so `oninit`
   * runs once and cannot be where the route is read. In-app navigation sets the
   * state before it routes, which makes this a no-op most of the time; it exists
   * for the paths that do not — browser back and forward, and a link arriving from
   * outside the page.
   */
  protected syncFromRoute(): void {
    const routeId = m.route.param('id');
    const channelId = routeId ? Number(routeId) : null;

    // Only when the route actually names one. `/chat/search` and `/chat/threads`
    // carry no id and must not be read as "no channel selected" — the search pane
    // keeps its channel context.
    if (channelId !== null && channelId !== chatState.activeChannelId) {
      chatState.setActiveChannel(channelId);

      // Deep link to a channel the sidebar has not loaded.
      if (!chatState.channel(channelId)) {
        app.store.find('chat-channels', String(channelId)).catch(() => {});
      }
    }

    const threadParam = m.route.param('threadId');
    const threadId = threadParam ? Number(threadParam) : null;

    if (threadId !== chatState.activeThreadId) {
      chatState.activeThreadId = threadId;
    }
  }

  view(): Mithril.Children {
    const channel = chatState.channel(chatState.activeChannelId);
    const narrow = window.innerWidth <= 767;
    const threadId = chatState.activeThreadId;

    // `routeName` is supplied by core's DefaultResolver. Several routes resolve to
    // this same component, so the main pane is chosen from it rather than from the
    // path — the sidebar is shared by all of them.
    const routeName = (this.attrs as { routeName?: string }).routeName;

    return (
      <div className="ChatPage">
        {narrow && channel ? null : (
          <ChatSidebar state={chatState} onSelect={(c: Channel) => this.select(c)} />
        )}

        <div className="ChatPage-main">
          {this.mainPane(routeName, channel, narrow, threadId)}
        </div>
      </div>
    );
  }

  protected mainPane(
    routeName: string | undefined,
    channel: Channel | null,
    narrow: boolean,
    threadId: number | null
  ): Mithril.Children {
    if (this.loading) return <LoadingIndicator />;

    if (routeName === 'chat.search') {
      // The channel button passes `?channel=`; the sidebar link does not, and
      // then the search spans every channel the actor can read.
      const scope = m.route.param('channel');

      return <ChatSearch state={chatState} channelId={scope ? Number(scope) : null} />;
    }

    if (routeName === 'chat.threads') {
      return <ThreadsList state={chatState} />;
    }

    // Built as an array rather than a JSX fragment with a conditional slot.
    // Mithril's normalizeChildren decides a fragment is keyed from its first child
    // and then requires every other child to be keyed too — and it counts a `null`
    // as unkeyed. So `[<ChannelView key=…/>, null]`, which is what an absent thread
    // panel produced, threw "In fragments, vnodes must either all have keys or none
    // have keys" and took the page down. Pushing only what is actually rendered
    // keeps every entry present and keyed.
    const panes: Mithril.Children[] = [
      channel ? (
        <ChannelView
          key={`channel-${channel.id()}`}
          channel={channel}
          state={chatState}
          onBack={narrow ? () => this.deselect() : undefined}
        />
      ) : (
        this.empty()
      ),
    ];

    // Beside the channel on desktop; the panel's own stylesheet takes it full-bleed
    // over the channel below the mobile breakpoint. The thread and pinned panels
    // share the slot and are mutually exclusive — see ChatState.togglePinned().
    if (channel && threadId) {
      panes.push(
        <ThreadPanel
          key={`thread-${threadId}`}
          channel={channel}
          threadId={threadId}
          state={chatState}
          onClose={() => this.closeThread(channel)}
        />
      );
    } else if (channel && chatState.showPinned) {
      panes.push(
        <PinnedPanel
          key={`pinned-${channel.id()}`}
          channel={channel}
          state={chatState}
          onClose={() => {
            chatState.showPinned = false;
            m.redraw();
          }}
        />
      );
    }

    return panes;
  }

  protected empty(): Mithril.Children {
    // Keyed because it shares the pane array with the keyed channel view.
    return (
      <div className="ChatBrowse-empty" key="empty">
        {app.translator.trans('ramon-chat.forum.sidebar.no_channels')}
      </div>
    );
  }

  protected async boot(): Promise<void> {
    try {
      await Promise.all([chatState.loadChannels(), chatState.loadDrafts()]);

      const routeId = m.route.param('id');

      if (routeId) {
        chatState.setActiveChannel(Number(routeId));

        // Deep link to a channel that is not in the sidebar (not joined, or the
        // list is truncated): fetch it directly rather than showing "empty".
        if (!chatState.channel(chatState.activeChannelId)) {
          try {
            await app.store.find('chat-channels', String(routeId));
          } catch {
            chatState.setActiveChannel(null);
          }
        }
      } else if (!chatState.activeChannelId) {
        chatState.setActiveChannel(chatState.channels[0] ? Number(chatState.channels[0].id()) : null);
      }

      const threadId = m.route.param('threadId');
      chatState.activeThreadId = threadId ? Number(threadId) : null;
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  protected select(channel: Channel): void {
    chatState.setActiveChannel(Number(channel.id()));
    m.route.set(app.route('chat.channel', { id: channel.id() }));
  }

  protected deselect(): void {
    chatState.setActiveChannel(null);
    m.route.set(app.route('chat.index'));
  }

  /**
   * Closing the panel also drops the thread from the URL, so a reload does not
   * reopen what was just dismissed.
   */
  protected closeThread(channel: Channel): void {
    chatState.closeThread();
    m.route.set(app.route('chat.channel', { id: channel.id() }));
  }
}
