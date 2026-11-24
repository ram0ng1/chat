import app from 'flarum/common/app';
import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';
import type Channel from './Channel';
import type { RankEntry } from './Channel';
import type Thread from './Thread';
import type Upload from './Upload';

/**
 * Agregado no servidor (MessageResource::reactionSummary()) para o cliente não
 * receber cada reação.
 */
export interface ReactionSummaryEntry {
  count: number;
  reacted: boolean;
}

export type ReactionSummary = Record<string, ReactionSummaryEntry>;

export default class Message extends Model {
  // ── Conteúdo ──
  /** Texto-fonte. Null em mensagem apagada que o usuário não pode ver. */
  content = Model.attribute<string | null>('content');
  contentHtml = Model.attribute<string | null>('contentHtml');

  type = Model.attribute<string>('type');
  systemKey = Model.attribute<string | null>('systemKey');
  systemData = Model.attribute<Record<string, unknown> | null>('systemData');

  // ── Posição ──
  number = Model.attribute<number | null>('number');
  channelId = Model.attribute<number>('channelId');
  threadId = Model.attribute<number | null>('threadId');
  replyToId = Model.attribute<number | null>('replyToId');

  createdAt = Model.attribute('createdAt', Model.transformDate);
  editedAt = Model.attribute('editedAt', Model.transformDate);
  deletedAt = Model.attribute('deletedAt', Model.transformDate);

  /**
   * Avança a cada mudança (edição, exclusão, fixar, reação). O polling de
   * fallback se reconcilia por ele (pollChanges() em forum/index.tsx).
   */
  updatedAt = Model.attribute('updatedAt', Model.transformDate);

  isDeleted = Model.attribute<boolean>('isDeleted');

  /**
   * Apagada por alguém que não o autor. `isRedacted()` só diz que o texto foi
   * ocultado deste leitor, o que vale também para autoexclusão.
   */
  isModeratorDeleted = Model.attribute<boolean>('isModeratorDeleted');
  isEdited = Model.attribute<boolean>('isEdited');

  isPinned = Model.attribute<boolean>('isPinned');
  pinnedAt = Model.attribute('pinnedAt', Model.transformDate);

  // ── Interação ──
  reactionSummary = Model.attribute<ReactionSummary>('reactionSummary');
  mentionedUsers = Model.attribute<number[]>('mentionedUsers');
  mentionsChannelWide = Model.attribute<boolean>('mentionsChannelWide');
  isBookmarked = Model.attribute<boolean>('isBookmarked');

  /**
   * Rank do autor no momento da leitura. O `rankBook` do canal, mantido em dia
   * pelo realtime, tem precedência quando carregado.
   */
  authorRank = Model.attribute<RankEntry | null>('authorRank');

  /** Se *este* leitor tem denúncia aberta contra ela. */
  isFlagged = Model.attribute<boolean>('isFlagged');

  /**
   * Só para quem tem `ramon-chat.moderate`; para os demais é undefined, não
   * zero, para não revelar quais mensagens foram denunciadas.
   */
  flagsCount = Model.attribute<number | undefined>('flagsCount');

  // ── Permissões ──
  canEdit = Model.attribute<boolean>('canEdit');
  canDelete = Model.attribute<boolean>('canDelete');
  canReact = Model.attribute<boolean>('canReact');
  canReply = Model.attribute<boolean>('canReply');
  canCreateThread = Model.attribute<boolean>('canCreateThread');
  canMove = Model.attribute<boolean>('canMove');
  canPin = Model.attribute<boolean>('canPin');
  canFlag = Model.attribute<boolean>('canFlag');

  /** Remover a linha de vez, sem deixar tombstone. */
  canForceDelete = Model.attribute<boolean>('canForceDelete');

  // ── Relações ──
  user = Model.hasOne<User | null>('user');
  editedBy = Model.hasOne<User | null>('editedBy');
  deletedBy = Model.hasOne<User | null>('deletedBy');
  replyTo = Model.hasOne<Message | null>('replyTo');
  thread = Model.hasOne<Thread | null>('thread');
  channel = Model.hasOne<Channel | null>('channel');
  uploads = Model.hasMany<Upload>('uploads');

  // ── Derivados ──

  isSystem(): boolean {
    return this.type() === 'system';
  }

  /**
   * Não é mensagem de sistema: renderiza como mensagem comum, mas sem registro
   * de usuário; nome e avatar vêm das settings.
   */
  isBot(): boolean {
    return this.type() === 'bot';
  }

  /**
   * A linha existe mas o texto foi ocultado deste leitor. O stream desenha um
   * tombstone para não haver reflow silencioso.
   */
  isRedacted(): boolean {
    return Boolean(this.isDeleted()) && this.content() === null;
  }

  /** Por nome ou via @here / @all. */
  mentionsActor(): boolean {
    const actor = app.session.user;

    if (!actor) return false;

    if (this.mentionsChannelWide()) return true;

    return (this.mentionedUsers() ?? []).includes(Number(actor.id()));
  }

  /** Mesmo autor, perto no tempo e nenhuma das duas é de sistema. */
  isGroupedWith(previous: Message | null | undefined, withinSeconds = 300): boolean {
    if (!previous) return false;
    if (this.isSystem() || previous.isSystem()) return false;
    if (this.threadId() !== previous.threadId()) return false;

    // Posts do bot não têm `user()`; dois seguidos contam como mesmo autor, e
    // bot com humano nunca.
    if (this.isBot() || previous.isBot()) {
      if (!this.isBot() || !previous.isBot()) return false;
    } else {
      const a = this.user();
      const b = previous.user();

      if (!a || !b || a.id() !== b.id()) return false;
    }

    // A janela de tempo vale também para o bot.
    const t1 = this.createdAt();
    const t2 = previous.createdAt();

    if (!t1 || !t2) return false;

    return (t1.getTime() - t2.getTime()) / 1000 <= withinSeconds;
  }

  totalReactions(): number {
    const summary: ReactionSummary = this.reactionSummary() ?? {};

    return Object.values(summary).reduce<number>((sum, entry) => sum + entry.count, 0);
  }

  apiEndpoint(): string {
    return '/chat-messages' + (this.exists ? '/' + this.id() : '');
  }
}
