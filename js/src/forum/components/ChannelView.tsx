import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import type Message from "../../common/models/Message";
import type ChatState from "../state/ChatState";
import ChatMessage from "./ChatMessage";
import ChatComposer from "./ChatComposer";
import ChatSelectionBar from "./ChatSelectionBar";
import { MessageStreamSkeleton } from "./Skeletons";
import { channelIcon } from "../utils/channelIcon";
import { channelActions, openChannelInfo } from "../utils/channelActions";
import { jumpToMessage } from "../utils/jumpToMessage";
import { messagePreview } from "../../common/utils/preview";

export interface ChannelViewAttrs extends ComponentAttrs {
  channel: Channel;
  state: ChatState;
  /** Rendered in the header; lets the drawer show a back button the page doesn't need. */
  onBack?: () => void;
  /**
   * The view is inside a container that shows the thread panel itself — the
   * drawer. Opening a thread then sets the state and stops, instead of routing
   * away to the full-screen page and closing the drawer to do it.
   */
  embedded?: boolean;
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

    this.scroller = vnode.dom.querySelector(".ChatChannel-stream");
    this.scrollToBottom();
  }

  onupdate(vnode: Mithril.VnodeDOM<ChannelViewAttrs>): void {
    super.onupdate(vnode);

    const stream = this.attrs.state.stream(Number(this.attrs.channel.id()));
    const count = stream.messages.length;

    // Restore the pre-prepend position after paging upwards, so the viewport does
    // not jump to the top when older messages arrive.
    if (this.heightBeforePrepend !== null && this.scroller) {
      this.scroller.scrollTop =
        this.scroller.scrollHeight - this.heightBeforePrepend;
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

        {/* The wrapper exists only to anchor the scroll-down button. Positioning
            it against `.ChatChannel` would mean guessing the composer's height,
            which grows with the text in it and with the typing indicator; against
            the scroller itself it would scroll away with the content. */}
        <div className="ChatChannel-streamWrap">
          <div
            className="ChatChannel-stream"
            onscroll={(e: Event) => this.onScroll(e)}
          >
            {stream.loading && stream.messages.length === 0
              ? this.skeleton()
              : null}
            {/* Paging upwards. A skeleton row rather than a spinner: it occupies
                roughly the height the arriving messages will, so the stream does
                not lurch when they land — and it says "messages are coming"
                rather than "something is happening". */}
            {stream.hasMore && stream.messages.length > 0 ? (
              <div className="ChatChannel-loadMore">
                {stream.loading ? MessageStreamSkeleton(2) : null}
              </div>
            ) : null}

            {stream.loadedInitial && stream.messages.length === 0 ? (
              <div className="ChatBrowse-empty">
                {app.translator.trans("ramon-chat.forum.channel.no_messages")}
              </div>
            ) : null}

            {this.rows(stream.messages, stream.dividerAfterId)}
          </div>

          {this.scrollDownButton()}
        </div>

        {this.typingIndicator()}

        {/* Selection replaces the composer: the two are different modes, and
            leaving the input under a selection bar invites typing into a channel
            while acting on messages in it. */}
        {state.selecting ? (
          <ChatSelectionBar channel={channel} state={state} />
        ) : (
          <ChatComposer
            channel={channel}
            state={state}
            onSent={() => this.onSent()}
          />
        )}

        {/* Announces arrivals to screen readers without stealing focus. */}
        <div
          className="ChatChannel-liveRegion"
          role="status"
          aria-live="polite"
        />
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

    const text = messagePreview(pinned);
    const reachable = state
      .stream(Number(channel.id()))
      .messages.some((message) => message.id() === pinned.id());

    const count = state.pinnedCount(Number(channel.id()));

    return (
      <div className="ChatChannel-pinnedBar">
        <button
          type="button"
          className="ChatChannel-pinnedBar-jump"
          title={app.translator.trans(
            "ramon-chat.forum.channel.pinned_messages",
            {},
            true,
          )}
          onclick={() => this.jumpToPinned(pinned)}
          disabled={!reachable}
        >
          <i
            className="ChatChannel-pinnedBar-icon fas fa-thumbtack"
            aria-hidden="true"
          />
          <span className="ChatChannel-pinnedBar-text">
            {text || app.translator.trans("ramon-chat.forum.message.pinned")}
          </span>
        </button>

        {/* Only past the first. The bar already *is* the one pinned message, and
            a control opening a panel to show that same message again would cost
            a click to see what is already on screen. Past that the bar shows the
            newest of several and the rest have nowhere else to be reached from —
            which is the gap this closes: the panel's own toggle moved into the
            drawer's overflow menu, and the strip that stands for the pins had no
            way into them.

            A sibling rather than a nested button: the strip is itself a button
            and a button inside a button is invalid, which is why the whole thing
            is a div now with the jump as its first child. */}
        {count > 1 ? (
          <button
            type="button"
            className={classList("ChatChannel-pinnedBar-all", {
              "ChatChannel-pinnedBar-all--active": state.showPinned,
            })}
            title={app.translator.trans(
              "ramon-chat.forum.channel.view_all_pinned",
              { count },
              true,
            )}
            onclick={() => {
              state.togglePinned();
              m.redraw();
            }}
          >
            <span className="ChatChannel-pinnedBar-count">{count}</span>
            <i className="fas fa-angle-right" aria-hidden="true" />
          </button>
        ) : null}
      </div>
    );
  }

  protected jumpToPinned(pinned: Message): void {
    jumpToMessage(pinned.id()!, this.scroller);
  }

  /**
   * The channel's own bar: mark, name, description, actions.
   *
   * Absent when embedded. The drawer is 320px of vertical space and drew two
   * bars stacked, both naming the same channel — its own, for the window
   * controls, and this one. It now carries both, so this would be the second
   * copy rather than the only one.
   */
  protected header(): Mithril.Children {
    const { channel, state, onBack, embedded } = this.attrs;

    if (embedded) return null;

    return (
      <div className="ChatChannel-header">
        {onBack ? (
          <Button
            className="Button Button--icon Button--flat"
            icon="fas fa-chevron-left"
            onclick={onBack}
          />
        ) : null}

        {/* Name on the first line, description under it — not both on one row.
            Side by side they competed for the same width and the flex line
            resolved it by cutting the *name*: "Canal Priva…Apenas um canal …".
            Stacked, each truncates within its own line and the name is always
            whole for as long as the header is wide enough to hold it. */}
        <button
          type="button"
          className="ChatChannel-title"
          onclick={() => openChannelInfo(channel)}
        >
          {channelIcon(channel, "ChatChannel-icon")}

          <span className="ChatChannel-titleText">
            <span className="ChatChannel-name">{channel.displayName()}</span>

            {channel.description() ? (
              <span className="ChatChannel-description">
                {channel.description()}
              </span>
            ) : null}
          </span>
        </button>

        {/* The same set the drawer puts behind its overflow menu — see
            utils/channelActions, which is where the gating lives. */}
        <div className="ChatChannel-headerActions">
          {channelActions(channel, state).map((action) => (
            <Button
              key={action.key}
              className={classList("Button Button--icon Button--flat", {
                "ChatChannel-headerAction--active": action.active,
              })}
              icon={action.icon}
              title={action.label}
              loading={action.loading}
              onclick={action.onclick}
            />
          ))}
        </div>
      </div>
    );
  }

  /**
   * Interleaves date separators and the unread divider with the message rows.
   */
  protected rows(
    messages: Message[],
    dividerAfterId: number | null,
  ): Mithril.Children {
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
            <span className="ChatDateSeparator-label">
              {this.dateLabel(at!)}
            </span>
          </div>,
        );

        lastDate = dateKey;
      }

      if (
        !dividerPlaced &&
        dividerAfterId !== null &&
        Number(message.id()) > dividerAfterId
      ) {
        out.push(
          <div className="ChatUnreadDivider" key="unread">
            <span className="ChatUnreadDivider-label">
              {app.translator.trans("ramon-chat.forum.stream.new_messages")}
            </span>
          </div>,
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
        />,
      );
    });

    return out;
  }

  protected dateLabel(date: Date): string {
    const today = new Date();
    const yesterday = new Date(today.getTime() - 86400000);

    if (date.toDateString() === today.toDateString()) {
      return app.translator.trans("ramon-chat.forum.stream.today", {}, true);
    }

    if (date.toDateString() === yesterday.toDateString()) {
      return app.translator.trans(
        "ramon-chat.forum.stream.yesterday",
        {},
        true,
      );
    }

    return date.toLocaleDateString(undefined, {
      day: "numeric",
      month: "long",
      year: "numeric",
    });
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
            ? app.translator.trans("ramon-chat.forum.typing.one", {
                username: names[0],
              })
            : app.translator.trans("ramon-chat.forum.typing.several", {
                count: names.length,
              })}
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
    const wasPinned = this.pinned;

    // 40px of tolerance: "at the bottom" should survive sub-pixel rounding and a
    // partially visible last row.
    this.pinned = el.scrollHeight - el.scrollTop - el.clientHeight < 40;

    // Scroll fires continuously and Mithril redraws after every handler bound
    // this way, so a drag through the middle of a long stream repaints it dozens
    // of times over. Suppressed only while staying *away* from the bottom, which
    // is the one case with nothing to show for it: the button below is already
    // visible, `markRead` is not reached, and `fetchPage` redraws itself when its
    // request lands. Staying pinned still redraws — `markRead` mutates the unread
    // badge and has no redraw of its own.
    if (!this.pinned && !wasPinned) {
      (e as Event & { redraw?: boolean }).redraw = false;
    }

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

  /**
   * The way back down, for when the stream has been left somewhere above the
   * newest message.
   *
   * Jumping to a pinned message is the case that makes this necessary: it can
   * land you hours up the conversation with no affordance but a long scroll, and
   * on a phone that is a lot of dragging. Same control WhatsApp draws in the same
   * corner, for the same reason.
   *
   * Tied to `pinned` rather than to a scroll-offset threshold of its own —
   * `pinned` is already what decides whether the stream follows new messages, so
   * the button is visible exactly when it is not following, and never lingers
   * over a stream that is already at the bottom.
   */
  protected scrollDownButton(): Mithril.Children {
    if (this.pinned) return null;

    const label = app.translator.trans(
      "ramon-chat.forum.channel.scroll_to_latest",
      {},
      true,
    );

    return (
      <button
        type="button"
        className="ChatChannel-scrollDown"
        title={label}
        aria-label={label}
        onclick={() => {
          this.pinned = true;
          this.scrollToBottom();
        }}
      >
        <i className="fas fa-chevron-down" aria-hidden="true" />
      </button>
    );
  }

  /**
   * Jumps the stream to the newest message.
   *
   * Instant, including for the button — an animated version of this does not
   * survive its surroundings. `onupdate` re-anchors to the bottom the moment a
   * message arrives while pinned, which overrides an animation mid-flight; and a
   * smooth `scrollTo` resolves its target once, so rows still laying out below
   * (an image finishing, the reconcile filling a row in) leave it settling short
   * of the bottom — on whichever row happened to be there.
   */
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
    state.setDraft(Number(channel.id()), message.content() ?? "");
    m.redraw();
  }

  protected openThread(message: Message): void {
    const thread = message.thread();
    const { channel, state, embedded } = this.attrs;

    if (thread) {
      state.activeThreadId = Number(thread.id());

      // In the drawer the panel opens over the conversation, the way the pinned
      // list does. Routing here would throw the drawer away and reopen the whole
      // thing full-screen, which is not what clicking a reply count asks for.
      if (embedded) {
        m.redraw();

        return;
      }

      m.route.set(
        app.route("chat.thread", { id: channel.id(), threadId: thread.id() }),
      );

      return;
    }

    // No thread yet: the send makes one. Staged as a reply like any other, but
    // flagged as a branch — the composer cannot otherwise tell this apart from
    // the plain reply below, and inferring it turned every reply in a
    // threading-enabled channel into a thread.
    state.setReplyingTo(Number(channel.id()), message, null, true);
    m.redraw();
  }
}
