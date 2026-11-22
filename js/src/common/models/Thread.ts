import app from 'flarum/common/app';
import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';
import type Channel from './Channel';
import type Message from './Message';
import { messagePreview } from '../utils/preview';

/** Espelha Ramon\Chat\ThreadUser. */
export const enum ThreadTracking {
  Never = 0,
  Mentions = 1,
  Always = 2,
}

export default class Thread extends Model {
  title = Model.attribute<string | null>('title');
  status = Model.attribute<string>('status');

  channelId = Model.attribute<number>('channelId');
  originalMessageId = Model.attribute<number | null>('originalMessageId');
  repliesCount = Model.attribute<number>('repliesCount');
  lastMessageId = Model.attribute<number | null>('lastMessageId');

  lastMessageAt = Model.attribute('lastMessageAt', Model.transformDate);
  createdAt = Model.attribute('createdAt', Model.transformDate);

  // ── Estado do usuário atual ──
  notificationLevel = Model.attribute<ThreadTracking>('notificationLevel');
  unreadCount = Model.attribute<number>('unreadCount');
  lastReadMessageId = Model.attribute<number>('lastReadMessageId');
  isParticipating = Model.attribute<boolean>('isParticipating');

  canRename = Model.attribute<boolean>('canRename');
  canPostMessage = Model.attribute<boolean>('canPostMessage');
  canClose = Model.attribute<boolean>('canClose');

  creator = Model.hasOne<User | null>('creator');
  channel = Model.hasOne<Channel | null>('channel');
  originalMessage = Model.hasOne<Message | null>('originalMessage');
  lastMessage = Model.hasOne<Message | null>('lastMessage');

  isOpen(): boolean {
    return this.status() === 'open';
  }

  hasUnread(): boolean {
    return (this.unreadCount() ?? 0) > 0;
  }

  /** Sem título, usa um trecho da mensagem raiz. */
  displayTitle(excerptLength = 60): string {
    const title = this.title();

    if (title) return title;

    // `hasOne` devolve `false` quando a relação não foi carregada, diferente de
    // carregada e null.
    const root = this.originalMessage();

    // `content()` é o Markdown cru; o preview lê o HTML renderizado.
    const content = root ? messagePreview(root, excerptLength) : '';

    if (!content) {
      return app.translator.trans('ramon-chat.forum.thread.untitled', {}, true);
    }

    return content;
  }

  apiEndpoint(): string {
    return '/chat-threads' + (this.exists ? '/' + this.id() : '');
  }
}
