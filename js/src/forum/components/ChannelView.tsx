import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import { displayEmoji } from '../utils/emoji';

import type Channel from '../../common/models/Channel';
import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
import ChatMessage from './ChatMessage';
import ChatComposer from './ChatComposer';
import ChannelFormModal from './ChannelFormModal';
import ChannelInfoModal from './ChannelInfoModal';
import ChatSelectionBar from './ChatSelectionBar';

export interface ChannelViewAttrs extends ComponentAttrs {
  channel: Channel;
  state: ChatState;
  /** Rendered in the header; lets the drawer show a back button the page doesn't need. */
  onBack?: () => void;
}

/**
 * A channel's header, message stream and composer.
 *
 * The stream is bottom-anchored: it stays pinned to the newest message while the
 * user is at the bottom, and holds its scroll position when they are not. Getting
 * this wrong is the single most noticeable chat bug, so the anchoring logic is
 * kept explicit rather than left to the browser.
 */
export default class ChannelView extends Component<ChannelViewAttrs> {
  private scroller: HTMLElement | null = null;

  /** Whether the user is at (or near) the bottom of the stream. */
  private pinned = true;

  /** Scroll height before a prepend, used to restore position after paging up. */
  private heightBeforePrepend: number | null = null;

  private lastRenderedCount = 0;
  private joining = false;

  oninit(vnode: Mithril.Vnode<ChannelViewAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<ChannelViewAttrs, this>): void {
    // `this.attrs` still holds the previous attrs at this point; Component
    // assigns the incoming vnode's attrs in super.onbeforeupdate().
    const previousId = this.attrs?.channel?.id();

    super.onbeforeupdate(vnode);

    // Switching channels inside the same component instance: reset and reload.
    if (previousId !== undefined && vnode.attrs.channel.id() !== previousId) {
      this.pinned = true;
      this.lastRenderedCount = 0;
      this.load();
    }
  }

  oncreate(vnode: Mithril.VnodeDOM<ChannelViewAttrs>): void {
    super.oncreate(vnode);

    this.scroller = vnode.dom.querySelector('.ChatChannel-stream');
    this.scrollToBottom();
  }

  onupdate(vnode: Mithril.VnodeDOM<ChannelViewAttrs>): void {
    super.onupdate(vnode);

    const stream = this.attrs.state.stream(Number(this.attrs.channel.id()));
    const count = stream.messages.length;

    // Restore the pre-prepend position after paging upwards, so the viewport does
    // not jump to the top when older messages arrive.
    if (this.heightBeforePrepend !== null && this.scroller) {
      this.scroller.scrollTop = this.scroller.scrollHeight - this.heightBeforePrepend;
      this.heightBeforePrepend = null;
    } else if (count > this.lastRenderedCount && this.pinned) {
      this.scrollToBottom();
    }

    this.lastRenderedCount = count;
  }

  view(): Mithril.Children {
    const { channel, state } = this.attrs;
    const stream = state.stream(Number(channel.id()));

    return (
      <div className="ChatChannel">
        {this.header()}
        {this.pinnedBar()}

        <div className="ChatChannel-stream" onscroll={(e: Event) => this.onScroll(e)}>
          {stream.loading && stream.messages.length === 0 ? this.skeleton() : null}
          {stream.hasMore && stream.messages.length > 0 ? (
            <div className="ChatChannel-loadMore">
              {stream.loading ? <LoadingIndicator display="inline" size="small" /> : null}
            </div>
          ) : null}

          {stream.loadedInitial && stream.messages.length === 0 ? (
            <div className="ChatBrowse-empty">{app.translator.trans('ramon-chat.forum.channel.no_messages')}</div>
          ) : null}

          {this.rows(stream.messages, stream.dividerAfterId)}
        </div>

        {this.typingIndicator()}

        {/* Selection replaces the composer: the two are different modes, and
            leaving the input under a selection bar invites typing into a channel
            while acting on messages in it. */}
        {state.selecting ? (
          <ChatSelectionBar channel={channel} state={state} />
        ) : (
          <ChatComposer channel={channel} state={state} onSent={() => this.onSent()} />
        )}

        {/* Announces arrivals to screen readers without stealing focus. */}
        <div className="ChatChannel-liveRegion" role="status" aria-live="polite" />
      </div>
    );
  }

  /**
   * The pinned message, as a strip under the header — the WhatsApp arrangement.
   *
   * Shown in the drawer as well as the full-screen page: the drawer has no room for
   * the pinned panel, and a pin nobody can see is pointless. Clicking it jumps to
   * the message when it is in the loaded window, which is the behaviour the strip
   * implies; when it is not loaded the strip stays a label rather than pretending
   * to navigate somewhere.
   */
  protected pinnedBar(): Mithril.Children {
    const { channel, state } = this.attrs;
    const pinned = state.latestPinned(Number(channel.id()));

    if (!pinned) return null;

    const text = pinned.content() ?? '';
    const reachable = state
      .stream(Number(channel.id()))
      .messages.some((message) => message.id() === pinned.id());

    return (
      <button
        type="button"
        className="ChatChannel-pinnedBar"
        title={app.translator.trans('ramon-chat.forum.channel.pinned_messages', {}, true)}
        onclick={() => this.jumpToPinned(pinned)}
        disabled={!reachable}
      >
        <i className="ChatChannel-pinnedBar-icon fas fa-thumbtack" aria-hidden="true" />
        <span className="ChatChannel-pinnedBar-text">
          {text || app.translator.trans('ramon-chat.forum.message.pinned')}
        </span>
      </button>
    );
  }

  protected jumpToPinned(pinned: Message): void {
    const node = this.scroller?.querySelector(`[data-id="${pinned.id()}"]`);

    if (!node) return;

    node.scrollIntoView({ block: 'center', behavior: 'smooth' });

    // Briefly re-assert the highlight, so it is obvious which row was meant when
    // several are pinned.
    node.classList.add('ChatMessage--flash');
    window.setTimeout(() => node.classList.remove('ChatMessage--flash'), 1200);
  }

  protected header(): Mithril.Children {
    const { channel, onBack } = this.attrs;

    return (
      <div className="ChatChannel-header">
        {onBack ? <Button className="Button Button--icon Button--flat" icon="fas fa-chevron-left" onclick={onBack} /> : null}

        <button type="button" className="ChatChannel-title" onclick={() => this.openInfo()}>
          {channel.emoji() ? <span>{displayEmoji(channel.emoji())}</span> : <i className="fas fa-hashtag" aria-hidden="true" />}
          <span>{channel.displayName()}</span>
          {channel.description() ? (
            <span className="ChatChannel-description">{channel.description()}</span>
          ) : null}
        </button>

        <div className="ChatChannel-headerActions">
          {channel.isMuted() ? <i className="fas fa-bell-slash" title="muted" aria-hidden="true" /> : null}

          {/* Gated on the server-computed flag, so the control is absent rather
              than present-and-rejected. See ChannelPolicy::edit. */}
          {channel.canEdit() && channel.isCategory() ? (
            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-pen-to-square"
              title={app.translator.trans('ramon-chat.forum.channel.edit')}
              onclick={() => this.editChannel()}
            />
          ) : null}

          <Button
            className={classList('Button Button--icon Button--flat', {
              'ChatChannel-headerAction--active': this.attrs.state.showPinned,
            })}
            icon="fas fa-thumbtack"
            title={app.translator.trans('ramon-chat.forum.channel.pinned_messages')}
            onclick={() => {
              this.attrs.state.togglePinned();
              m.redraw();
            }}
          />

          <Button
            className="Button Button--icon Button--flat"
            icon="fas fa-magnifying-glass"
            title={app.translator.trans('ramon-chat.forum.channel.search_in_channel')}
            onclick={() => m.route.set(app.route('chat.search', { channel: channel.id() }))}
          />

          {/* Leaving is offered only for a channel you are actually in. A direct
              channel keeps its history, so leaving one is not destructive. */}
          {channel.isFollowing() ? (
            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-arrow-right-from-bracket"
              title={app.translator.trans('ramon-chat.forum.channel.leave')}
              onclick={() => this.leave()}
            />
          ) : (
            this.joinControls(channel)
          )}
        </div>
      </div>
    );
  }

  /**
   * Getting back into a channel you left.
   *
   * Two buttons rather than one, because they are different acts. An ordinary join
   * puts you in the member list and the count; a hidden one does not, which is what
   * lets a moderator read a room without their arrival changing how people talk in
   * it. The hidden option is drawn only when the server says the actor holds it,
   * and a lurking moderator is told they are lurking — otherwise the state is
   * indistinguishable from an ordinary membership.
   */
  protected joinControls(channel: Channel): Mithril.Children {
    const items: Mithril.Children[] = [];

    if (channel.canJoin()) {
      items.push(
        <Button
          className="Button Button--icon Button--flat"
          icon="fas fa-arrow-right-to-bracket"
          title={app.translator.trans('ramon-chat.forum.channel.join')}
          loading={this.joining}
          onclick={() => this.join(false)}
        />
      );
    }

    if (channel.canJoinHidden()) {
      items.push(
        <Button
          className="Button Button--icon Button--flat"
          icon="fas fa-user-secret"
          title={app.translator.trans('ramon-chat.forum.channel.join_hidden')}
          loading={this.joining}
          onclick={() => this.join(true)}
        />
      );
    }

    return items.length > 0 ? items : null;
  }

  protected async join(hidden: boolean): Promise<void> {
    const { channel, state } = this.attrs;

    this.joining = true;
    m.redraw();

    try {
      await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-channels/${channel.id()}/join`,
        body: { data: { attributes: { hidden } } },
      });

      channel.pushAttributes({
        isFollowing: true,
        isHiddenMember: hidden,
        // A hidden join is absent from the count, so it must not appear to move it.
        userCount: hidden ? channel.userCount() : (channel.userCount() ?? 0) + 1,
      });

      if (!state.channels.some((c) => c.id() === channel.id())) {
        state.channels.unshift(channel);
      }

      if (hidden) {
        app.alerts.show({ type: 'success' }, app.translator.trans('ramon-chat.forum.channel.joined_hidden'));
      }
    } catch (e: any) {
      app.alerts.show(
        { type: 'error' },
        e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.channel.join_failed')
      );
    } finally {
      this.joining = false;
      m.redraw();
    }
  }

  /**
   * Interleaves date separators and the unread divider with the message rows.
   */
  protected rows(messages: Message[], dividerAfterId: number | null): Mithril.Children {
    const out: Mithril.Children[] = [];
    let lastDate: string | null = null;
    let dividerPlaced = false;

    messages.forEach((message, index) => {
      const previous = index > 0 ? messages[index - 1] : null;

      const at = message.createdAt();
      const dateKey = at ? at.toDateString() : null;

      if (dateKey && dateKey !== lastDate) {
        out.push(
          <div className="ChatDateSeparator" key={`date-${dateKey}`}>
            <span className="ChatDateSeparator-label">{this.dateLabel(at!)}</span>
          </div>
        );

        lastDate = dateKey;
      }

      if (!dividerPlaced && dividerAfterId !== null && Number(message.id()) > dividerAfterId) {
        out.push(
          <div className="ChatUnreadDivider" key="unread">
            <span className="ChatUnreadDivider-label">
              {app.translator.trans('ramon-chat.forum.stream.new_messages')}
            </span>
          </div>
        );

        dividerPlaced = true;
      }

      out.push(
        <ChatMessage
          key={message.id()}
          message={message}
          previous={previous}
          state={this.attrs.state}
          onReply={(msg: Message) => this.reply(msg)}
          onEdit={(msg: Message) => this.edit(msg)}
          onOpenThread={(msg: Message) => this.openThread(msg)}
        />
      );
    });

    return out;
  }

  protected dateLabel(date: Date): string {
    const today = new Date();
    const yesterday = new Date(today.getTime() - 86400000);

    if (date.toDateString() === today.toDateString()) {
      return app.translator.trans('ramon-chat.forum.stream.today', {}, true);
    }

    if (date.toDateString() === yesterday.toDateString()) {
      return app.translator.trans('ramon-chat.forum.stream.yesterday', {}, true);
    }

    return date.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' });
  }

  protected typingIndicator(): Mithril.Children {
    const names = this.attrs.state.typistsIn(Number(this.attrs.channel.id()));

    if (names.length === 0) return <div className="ChatTyping" />;

    return (
      <div className="ChatTyping">
        <span className="ChatTyping-dots">
          <span />
          <span />
          <span />
        </span>
        <span>
          {names.length === 1
            ? app.translator.trans('ramon-chat.forum.typing.one', { username: names[0] })
            : app.translator.trans('ramon-chat.forum.typing.several', { count: names.length })}
        </span>
      </div>
    );
  }

  protected skeleton(): Mithril.Children {
    return (
      <div className="ChatSkeleton">
        {[0, 1, 2, 3, 4].map((i) => (
          <div className="ChatSkeleton-row" key={i}>
            <div className="ChatSkeleton-avatar" />
            <div className="ChatSkeleton-lines">
              <div className="ChatSkeleton-line ChatSkeleton-line--short" />
              <div className="ChatSkeleton-line ChatSkeleton-line--long" />
            </div>
          </div>
        ))}
      </div>
    );
  }

  // ── Behaviour ──────────────────────────────────────────────────────────────

  protected async load(): Promise<void> {
    const { channel, state } = this.attrs;
    const channelId = Number(channel.id());

    // Not awaited: the pinned bar is secondary to the conversation, and blocking
    // the first page on it would delay every channel open by a round trip.
    state.loadPinnedPreview(channelId).catch(() => {});

    await state.loadChannel(channelId);

    // Mark read only after the first page is on screen, so the divider has
    // already been positioned from the pre-read marker.
    state.markRead(channelId);
  }

  protected onScroll(e: Event): void {
    const el = e.target as HTMLElement;

    // 40px of tolerance: "at the bottom" should survive sub-pixel rounding and a
    // partially visible last row.
    this.pinned = el.scrollHeight - el.scrollTop - el.clientHeight < 40;

    if (el.scrollTop < 120) {
      const stream = this.attrs.state.stream(Number(this.attrs.channel.id()));

      if (stream.hasMore && !stream.loading) {
        this.heightBeforePrepend = el.scrollHeight;
        this.attrs.state.fetchPage(Number(this.attrs.channel.id()));
      }
    }

    if (this.pinned) {
      this.attrs.state.markRead(Number(this.attrs.channel.id()));
    }
  }

  protected scrollToBottom(): void {
    if (!this.scroller) return;

    this.scroller.scrollTop = this.scroller.scrollHeight;
  }

  protected onSent(): void {
    this.pinned = true;
    this.scrollToBottom();
  }

  protected reply(message: Message): void {
    const { channel, state } = this.attrs;

    state.setReplyingTo(Number(channel.id()), message);
    m.redraw();
  }

  protected edit(message: Message): void {
    const { channel, state } = this.attrs;

    state.setEditing(Number(channel.id()), message);
    state.setDraft(Number(channel.id()), message.content() ?? '');
    m.redraw();
  }

  protected openThread(message: Message): void {
    const thread = message.thread();
    const { channel, state } = this.attrs;

    if (thread) {
      state.activeThreadId = Number(thread.id());
      m.route.set(app.route('chat.thread', { id: channel.id(), threadId: thread.id() }));

      return;
    }

    // No thread yet: replying with `createThread` makes one. Staging the reply
    // here keeps thread creation on the same code path as a normal reply.
    state.setReplyingTo(Number(channel.id()), message);
    m.redraw();
  }

  protected editChannel(): void {
    app.modal.show(ChannelFormModal, { channel: this.attrs.channel });
  }

  /**
   * Leaves the channel. The membership row is retained server-side, so read state
   * and history survive rejoining — and for a direct channel, restarting the
   * conversation links back to the earlier messages.
   */
  protected async leave(): Promise<void> {
    const { channel, state } = this.attrs;

    if (!confirm(app.translator.trans('ramon-chat.forum.channel.leave_confirm', {}, true))) return;

    try {
      await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-channels/${channel.id()}/leave`,
      });

      channel.pushAttributes({
        isFollowing: false,
        unreadCount: 0,
        unreadMentionsCount: 0,
        userCount: Math.max(0, (channel.userCount() ?? 1) - 1),
      });

      // Drop it from the sidebar and step away from the now-unfollowed channel.
      state.channels = state.channels.filter((c) => c.id() !== channel.id());
      state.setActiveChannel(null);

      if (m.route.get().includes('/chat/c/')) {
        m.route.set(app.route('chat.index'));
      }
    } catch (e: any) {
      app.alerts.show(
        { type: 'error' },
        e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.channel.leave_failed')
      );
    } finally {
      m.redraw();
    }
  }

  /**
   * Clicking the title opens the channel's details — notification level, member
   * list and the state actions the actor is allowed. Available to every member,
   * unlike the settings form behind the pencil, which needs the edit permission.
   */
  protected openInfo(): void {
    app.modal.show(ChannelInfoModal, { channel: this.attrs.channel });
  }
}
