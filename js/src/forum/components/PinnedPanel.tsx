import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import type Message from "../../common/models/Message";
import type ChatState from "../state/ChatState";
import ChatMessage from "./ChatMessage";
import { MessageStreamSkeleton } from "./Skeletons";

export interface PinnedPanelAttrs extends ComponentAttrs {
  channel: Channel;
  state: ChatState;
  onClose: () => void;
  /**
   * True inside the drawer, where panels stack over the conversation instead of
   * being addressed by URL. Same meaning as ChannelView's attr of the same name,
   * and needed for the same reason: routing from the drawer would throw it away
   * and reopen the conversation full-screen.
   */
  embedded?: boolean;
}

/**
 * A channel's pinned messages.
 *
 * Shares the right-hand panel geometry with the thread panel — the two are never
 * open at once. Pinning and unpinning happen on the message row itself, so there is
 * one place that does it rather than two that can disagree.
 */
export default class PinnedPanel extends Component<PinnedPanelAttrs> {
  private messages: Message[] = [];
  private loading = true;

  oninit(vnode: Mithril.Vnode<PinnedPanelAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<PinnedPanelAttrs, this>): void {
    const previousId = this.attrs?.channel?.id();

    super.onbeforeupdate(vnode);

    if (previousId !== undefined && vnode.attrs.channel.id() !== previousId) {
      this.loading = true;
      this.load();
    }
  }

  view(): Mithril.Children {
    const { state, onClose } = this.attrs;

    return (
      <div className="ChatThreadPanel ChatPinnedPanel">
        <div className="ChatThreadPanel-header">
          <i className="fas fa-thumbtack" aria-hidden="true" />

          <span className="ChatThreadPanel-title">
            {app.translator.trans("ramon-chat.forum.channel.pinned_messages")}
          </span>

          <Button
            className="Button Button--icon Button--flat"
            icon="fas fa-xmark"
            title={app.translator.trans(
              "ramon-chat.forum.channel.close_pinned",
              {},
              true,
            )}
            onclick={onClose}
          />
        </div>

        <div className="ChatThreadPanel-stream">
          {this.loading ? MessageStreamSkeleton(3) : null}

          {!this.loading && this.messages.length === 0 ? (
            <div className="ChatBrowse-empty">
              {app.translator.trans(
                "ramon-chat.forum.channel.no_pinned_messages",
              )}
            </div>
          ) : null}

          {/* `previous` is deliberately null on every row: these messages are not
              consecutive in the channel, so collapsing two of them under one author
              would imply a run that does not exist. */}
          {this.messages.map((message) => (
            <ChatMessage
              key={message.id()}
              message={message}
              previous={null}
              state={state}
              hideThreadIndicator
              onReply={(msg: Message) => this.reply(msg)}
              onEdit={(msg: Message) => this.edit(msg)}
            />
          ))}
        </div>
      </div>
    );
  }

  /**
   * Replying to a pinned message.
   *
   * The panel had no handlers, so ChatMessage drew the reply and edit buttons from
   * the message's own capability flags — the server said yes — and clicking them
   * did nothing at all. Worse than a missing button, because it looks like the
   * feature is broken rather than absent.
   *
   * The reply is staged against the channel, not the panel: the panel has no
   * composer of its own, so it closes and hands the context to the one below. That
   * is also why the target must carry its thread scope — replying to a pinned
   * message that lives inside a thread has to land in that thread, not in the
   * channel where the reply would make no sense.
   */
  protected reply(message: Message): void {
    this.stage(message, (channelId, threadId) =>
      this.attrs.state.setReplyingTo(channelId, message, threadId),
    );
  }

  protected edit(message: Message): void {
    this.stage(message, (channelId, threadId) => {
      this.attrs.state.setEditing(channelId, message, threadId);
      this.attrs.state.setDraft(channelId, message.content() ?? "", threadId);
    });
  }

  /**
   * Closes the panel, opens the right composer, and applies the action to it.
   */
  protected stage(
    message: Message,
    apply: (channelId: number, threadId: number | null) => void,
  ): void {
    const state = this.attrs.state;
    const channelId = Number(this.attrs.channel.id());
    const threadId = message.threadId() ?? null;

    state.showPinned = false;

    apply(channelId, threadId);

    this.attrs.onClose();

    // A pinned reply belongs to its thread, so that thread's panel has to open —
    // otherwise the composer holding the context is not the one on screen and the
    // click appears to do nothing, which is the bug this method exists to fix.
    if (threadId !== null) {
      state.activeThreadId = threadId;

      // On the full-screen page the thread is addressed by URL, and ChatPage
      // re-reads it on every update: setting the field alone would be undone on
      // the next redraw. In the drawer there is no route to set.
      if (!this.attrs.embedded) {
        m.route.set(app.route("chat.thread", { id: channelId, threadId }));

        return;
      }
    }

    m.redraw();
  }

  protected async load(): Promise<void> {
    try {
      const results = (await app.store.find("chat-messages", {
        filter: {
          channel: Number(this.attrs.channel.id()),
          pinned: true,
          // Without this the channel filter drops thread replies, and a pinned
          // message inside a thread would silently be missing from the list.
          includeThreadReplies: true,
        },
        sort: "-pinnedAt",
        page: { limit: 50 },
      })) as unknown as Message[];

      this.messages = Array.isArray(results) ? results : [];
    } catch {
      this.messages = [];
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
}
