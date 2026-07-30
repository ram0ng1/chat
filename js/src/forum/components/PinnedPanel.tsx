import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
import ChatMessage from './ChatMessage';
import { MessageStreamSkeleton } from './Skeletons';

export interface PinnedPanelAttrs extends ComponentAttrs {
  channel: Channel;
  state: ChatState;
  onClose: () => void;
}

/**
 * A channel's pinned messages.
 *
 * Shares the right-hand panel geometry with the thread panel — the two are never
 * open at once. Read-only: pinning and unpinning happen on the message row itself,
 * so there is one place that does it rather than two that can disagree.
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
            {app.translator.trans('ramon-chat.forum.channel.pinned_messages')}
          </span>

          <Button
            className="Button Button--icon Button--flat"
            icon="fas fa-xmark"
            title={app.translator.trans('ramon-chat.forum.channel.close_pinned')}
            onclick={onClose}
          />
        </div>

        <div className="ChatThreadPanel-stream">
          {this.loading ? MessageStreamSkeleton(3) : null}

          {!this.loading && this.messages.length === 0 ? (
            <div className="ChatBrowse-empty">
              {app.translator.trans('ramon-chat.forum.channel.no_pinned_messages')}
            </div>
          ) : null}

          {/* `previous` is deliberately null on every row: these messages are not
              consecutive in the channel, so collapsing two of them under one author
              would imply a run that does not exist. */}
          {this.messages.map((message) => (
            <ChatMessage key={message.id()} message={message} previous={null} state={state} />
          ))}
        </div>
      </div>
    );
  }

  protected async load(): Promise<void> {
    try {
      const results = (await app.store.find('chat-messages', {
        filter: {
          channel: Number(this.attrs.channel.id()),
          pinned: true,
          // Without this the channel filter drops thread replies, and a pinned
          // message inside a thread would silently be missing from the list.
          includeThreadReplies: true,
        },
        sort: '-pinnedAt',
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
