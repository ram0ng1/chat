import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Avatar from 'flarum/common/components/Avatar';
import humanTime from 'flarum/common/helpers/humanTime';
import username from 'flarum/common/helpers/username';
import type Mithril from 'mithril';

import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
import { MessageStreamSkeleton } from './Skeletons';

export interface BookmarksListAttrs extends ComponentAttrs {
  state: ChatState;
}

/**
 * The messages the actor has bookmarked, across every channel.
 *
 * The bookmark button has been on every message row from the start, and the filter
 * behind this has been in the API just as long — but nothing ever listed them, so
 * bookmarking was a one-way action: you could save a message and then had no way to
 * find it again. This is the other half.
 *
 * Rows are summaries rather than full ChatMessage components on purpose. A message
 * row draws reply, edit and pin actions from its own capability flags, and those
 * only make sense next to the conversation they belong to; here the useful action
 * is "take me to it", so each row is a link to the message in its channel.
 */
export default class BookmarksList extends Component<BookmarksListAttrs> {
  private messages: Message[] = [];
  private loading = true;
  private working: number | null = null;

  oninit(vnode: Mithril.Vnode<BookmarksListAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  view(): Mithril.Children {
    if (this.loading) {
      return <div className="ChatBookmarks">{MessageStreamSkeleton(4)}</div>;
    }

    if (this.messages.length === 0) {
      return (
        <div className="ChatBookmarks">
          <div className="ChatBrowse-empty">
            {app.translator.trans('ramon-chat.forum.bookmarks.empty')}
          </div>
        </div>
      );
    }

    return <div className="ChatBookmarks">{this.messages.map((message) => this.row(message))}</div>;
  }

  protected row(message: Message): Mithril.Children {
    const channel = this.attrs.state.channel(message.channelId());
    const at = message.createdAt();
    const id = Number(message.id());

    return (
      <div className="ChatBookmarks-row" key={message.id()}>
        <Avatar user={message.user()} className="Avatar" />

        <button type="button" className="ChatBookmarks-body" onclick={() => this.open(message)}>
          <div className="ChatBookmarks-meta">
            <span className="ChatBookmarks-author">{username(message.user())}</span>
            {channel ? <span className="ChatBookmarks-channel">{channel.displayName()}</span> : null}
            {at ? <span>{humanTime(at)}</span> : null}
          </div>

          {/* Plain text, not the rendered HTML: a bookmark list is for scanning, and
              a saved message with a code block or an image would otherwise dominate
              the list it is one row of. */}
          <div className="ChatBookmarks-excerpt">{this.excerpt(message)}</div>
        </button>

        <Button
          className="Button Button--icon Button--flat ChatBookmarks-remove"
          icon="fas fa-bookmark"
          loading={this.working === id}
          title={app.translator.trans('ramon-chat.forum.bookmarks.remove')}
          onclick={() => this.remove(message)}
        />
      </div>
    );
  }

  protected excerpt(message: Message): string {
    const text = (message.content() ?? '').replace(/\s+/g, ' ').trim();

    if (text === '') {
      return app.translator.trans('ramon-chat.forum.bookmarks.no_text', {}, true);
    }

    return text.length > 160 ? `${text.slice(0, 160)}…` : text;
  }

  protected async load(): Promise<void> {
    try {
      const results = (await app.store.find('chat-messages', {
        filter: { bookmarked: true },
        sort: '-createdAt',
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

  /**
   * Opens the channel the message lives in, and its thread when it has one — a
   * bookmarked thread reply is not in the channel window, so routing to the channel
   * alone would land somewhere the message is not.
   */
  protected open(message: Message): void {
    const channelId = message.channelId();

    if (!channelId) return;

    const threadId = message.threadId();

    this.attrs.state.setActiveChannel(channelId);
    this.attrs.state.activeThreadId = threadId ?? null;

    m.route.set(
      threadId
        ? app.route('chat.thread', { id: channelId, threadId })
        : app.route('chat.channel', { id: channelId })
    );
  }

  protected async remove(message: Message): Promise<void> {
    const id = Number(message.id());

    this.working = id;
    m.redraw();

    try {
      await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-messages/${id}/bookmark`,
        body: { data: { attributes: {} } },
      });

      // Dropped from the list rather than re-fetched: the endpoint toggles, so a
      // successful call means it is no longer bookmarked.
      this.messages = this.messages.filter((m) => Number(m.id()) !== id);
      message.pushAttributes({ isBookmarked: false });
    } catch {
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.bookmarks.remove_failed'));
    } finally {
      this.working = null;
      m.redraw();
    }
  }
}
