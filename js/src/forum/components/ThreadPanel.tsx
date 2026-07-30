import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import type Message from '../../common/models/Message';
import type Thread from '../../common/models/Thread';
import type ChatState from '../state/ChatState';
import ChatMessage from './ChatMessage';
import ChatComposer from './ChatComposer';
import { MessageStreamSkeleton } from './Skeletons';

export interface ThreadPanelAttrs extends ComponentAttrs {
  channel: Channel;
  threadId: number;
  state: ChatState;
  /** Invoked when the panel is dismissed, so the route can drop the thread. */
  onClose: () => void;
}

/**
 * A thread's replies, beside the channel.
 *
 * The channel stream shows a thread as one root message with a reply indicator —
 * the API's channel filter drops the replies — so this panel is the only place
 * they are readable. It is a narrower ChannelView: same rows, same composer, but
 * scoped to `filter[thread]` and without the unread divider, since a thread keeps
 * no read marker of its own.
 */
export default class ThreadPanel extends Component<ThreadPanelAttrs> {
  private scroller: HTMLElement | null = null;
  private pinned = true;
  private lastRenderedCount = 0;

  oninit(vnode: Mithril.Vnode<ThreadPanelAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<ThreadPanelAttrs, this>): void {
    const previousId = this.attrs?.threadId;

    super.onbeforeupdate(vnode);

    // Switching threads inside the same instance: reset and reload.
    if (previousId !== undefined && vnode.attrs.threadId !== previousId) {
      this.pinned = true;
      this.lastRenderedCount = 0;
      this.load();
    }
  }

  oncreate(vnode: Mithril.VnodeDOM<ThreadPanelAttrs>): void {
    super.oncreate(vnode);

    this.scroller = vnode.dom.querySelector('.ChatThreadPanel-stream');
    this.scrollToBottom();
  }

  onupdate(): void {
    const count = this.attrs.state.threadStream(this.attrs.threadId).messages.length;

    if (count > this.lastRenderedCount && this.pinned) {
      this.scrollToBottom();
    }

    this.lastRenderedCount = count;
  }

  view(): Mithril.Children {
    const { channel, threadId, state, onClose } = this.attrs;
    const stream = state.threadStream(threadId);
    const thread = app.store.getById<Thread>('chat-threads', String(threadId));

    return (
      <div className="ChatThreadPanel">
        <div className="ChatThreadPanel-header">
          <i className="fas fa-comments" aria-hidden="true" />

          <span className="ChatThreadPanel-title">
            {/* displayTitle() falls back to an excerpt of the root message, so an
                untitled thread is still identifiable. */}
            {thread
              ? thread.displayTitle()
              : app.translator.trans('ramon-chat.forum.thread.title')}
          </span>

          <Button
            className="Button Button--icon Button--flat"
            icon="fas fa-xmark"
            title={app.translator.trans('ramon-chat.forum.thread.close')}
            onclick={onClose}
          />
        </div>

        <div className="ChatThreadPanel-stream" onscroll={(e: Event) => this.onScroll(e)}>
          {stream.loading && stream.messages.length === 0 ? MessageStreamSkeleton(4) : null}

          {stream.loadedInitial && stream.messages.length === 0 ? (
            <div className="ChatBrowse-empty">
              {app.translator.trans('ramon-chat.forum.thread.no_replies')}
            </div>
          ) : null}

          {/* onReply/onEdit are wired even though the panel has no thread of its
              own to open: a row whose action buttons do nothing is worse than a row
              without them, and ChatMessage draws them from the message's own
              capability flags. `onOpenThread` is deliberately absent — the policy
              refuses to nest a thread inside a thread, so canCreateThread is false
              on every row here and the button is never drawn. */}
          {stream.messages.map((message, index) => (
            <ChatMessage
              key={message.id()}
              message={message}
              previous={stream.messages[index - 1] ?? null}
              state={state}
              onReply={(msg: Message) => this.reply(msg)}
              onEdit={(msg: Message) => this.edit(msg)}
            />
          ))}
        </div>

        <ChatComposer
          channel={channel}
          state={state}
          threadId={threadId}
          onSent={() => this.onSent()}
        />
      </div>
    );
  }

  protected load(): void {
    const { state, threadId } = this.attrs;

    // The record itself, for the title — the panel can be reached by URL without
    // the channel stream having loaded the root message it hangs off.
    state.findThread(threadId).then(() => m.redraw());

    state.loadThread(threadId).catch(() => {});
  }

  protected onScroll(e: Event): void {
    const el = e.target as HTMLElement;

    this.pinned = el.scrollHeight - el.scrollTop - el.clientHeight < 40;

    if (el.scrollTop < 120) {
      const stream = this.attrs.state.threadStream(this.attrs.threadId);

      if (stream.hasMore && !stream.loading) {
        this.attrs.state.fetchThreadPage(this.attrs.threadId).catch(() => {});
      }
    }
  }

  /**
   * Reply and edit are staged against this panel's scope, so the channel composer
   * below is unaffected.
   */
  protected reply(message: Message): void {
    const { state, channel, threadId } = this.attrs;

    state.setReplyingTo(Number(channel.id()), message, threadId);
    m.redraw();
  }

  protected edit(message: Message): void {
    const { state, channel, threadId } = this.attrs;

    state.setEditing(Number(channel.id()), message, threadId);
    state.setDraft(Number(channel.id()), message.content() ?? '', threadId);
    m.redraw();
  }

  protected onSent(): void {
    this.pinned = true;
    this.scrollToBottom();
  }

  protected scrollToBottom(): void {
    if (!this.scroller) return;

    this.scroller.scrollTop = this.scroller.scrollHeight;
  }
}
