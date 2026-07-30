import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import chatState from '../state/chat';
import ChatSidebar from './ChatSidebar';
import ChannelView from './ChannelView';
import PinnedPanel from './PinnedPanel';
import { chatTitle, chatIcon } from '../utils/branding';

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
        className={classList('ChatDrawer', { 'ChatDrawer--collapsed': chatState.drawerCollapsed })}
        role="complementary"
        aria-label={title}
      >
        <div className="ChatDrawer-header" onclick={() => this.toggleCollapsed()}>
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

          <span className="ChatDrawer-title">{channel ? channel.displayName() : title}</span>

          {/* Collapsed, the drawer is a bar with no stream to look at — so unread
              activity has to surface on the header itself or it goes unnoticed.
              A mention shows its count; ambient traffic gets a quiet dot. */}
          {chatState.drawerCollapsed && unread.mentions > 0 ? (
            <span className="ChatDrawer-badge" aria-label={String(unread.mentions)}>
              {unread.mentions > 99 ? '99+' : unread.mentions}
            </span>
          ) : chatState.drawerCollapsed && unread.channels > 0 ? (
            <span className="ChatDrawer-dot" aria-hidden="true" />
          ) : null}

          <div className="ChatDrawer-actions">
            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-up-right-and-down-left-from-center"
              title={app.translator.trans('ramon-chat.forum.drawer.full_screen')}
              onclick={(e: Event) => {
                e.stopPropagation();
                this.goFullScreen();
              }}
            />
            <Button
              className="Button Button--icon Button--flat"
              icon={chatState.drawerCollapsed ? 'fas fa-chevron-up' : 'fas fa-chevron-down'}
              title={app.translator.trans(
                chatState.drawerCollapsed
                  ? 'ramon-chat.forum.drawer.expand'
                  : 'ramon-chat.forum.drawer.collapse'
              )}
              onclick={(e: Event) => {
                e.stopPropagation();
                this.toggleCollapsed();
              }}
            />
            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-times"
              title={app.translator.trans('ramon-chat.forum.drawer.close')}
              onclick={(e: Event) => {
                e.stopPropagation();
                this.close();
              }}
            />
          </div>
        </div>

        {chatState.drawerCollapsed ? null : (
          <div className="ChatDrawer-body">
            {channel ? (
              <ChannelView key={channel.id()} channel={channel} state={chatState} />
            ) : (
              <ChatSidebar state={chatState} onSelect={(c: Channel) => this.select(c)} />
            )}

            {/* The drawer has no room for a side panel, so the pinned list covers
                the conversation instead — the same arrangement the thread panel
                uses below the mobile breakpoint. Without this the header's pin
                button toggled a state nothing rendered, and clicking it did
                nothing at all. */}
            {channel && chatState.showPinned ? (
              <PinnedPanel
                key={`pinned-${channel.id()}`}
                channel={channel}
                state={chatState}
                onClose={() => {
                  chatState.showPinned = false;
                  m.redraw();
                }}
              />
            ) : null}
          </div>
        )}
      </div>
    );
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
   */
  protected goFullScreen(): void {
    const id = chatState.activeChannelId;

    chatState.setDrawerOpen(false);

    m.route.set(id ? app.route('chat.channel', { id }) : app.route('chat.index'));
  }

  /**
   * Opens the drawer, loading the channel list on first open.
   */
  static async open(): Promise<void> {
    chatState.setDrawerOpen(true);
    chatState.setDrawerCollapsed(false);

    if (!chatState.channelsLoaded) {
      await Promise.all([chatState.loadChannels(), chatState.loadDrafts()]);
    }

    m.redraw();
  }
}
