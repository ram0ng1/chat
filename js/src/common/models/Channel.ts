import app from 'flarum/common/app';
import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';
import type Message from './Message';

/** Espelha Ramon\Chat\ChannelUser. */
export const enum NotificationLevel {
  Never = 0,
  Mentions = 1,
  Always = 2,
}

/**
 * Espelha ChannelResource::transferState(). `incoming` é true para quem recebe
 * a oferta; `confirmed` fica false até o dono digitar o código enviado por e-mail.
 */
export interface OwnershipTransferState {
  id: number;
  fromUserId: number;
  toUserId: number;
  confirmed: boolean;
  incoming: boolean;
  expiresAt: string;
}

/**
 * Espelha Ramon\Chat\Rank\RankBook. Nos dois `builtin`, `name` e `color` ficam
 * null até o dono personalizar (tradução padrão e cor do tema). As três cores
 * derivadas são null exatamente quando `color` é.
 */
export interface RankEntry {
  key: string;
  id: number | null;
  builtin: 'owner' | 'moderator' | null;
  name: string | null;
  color: string | null;
  icon: string | null;
  showBadge: boolean;
  position: number;
  /** Branco ou quase preto, o que tiver mais contraste. */
  textColor: string | null;
  /** Para nome em fundo claro, escurecida o quanto for preciso. */
  nameLight: string | null;
  /** Em fundo escuro, clareada. */
  nameDark: string | null;
}

/**
 * Ranks em ordem de prioridade. `assignments` mapeia id de usuário para ids de
 * ranks personalizados; vazio, o PHP manda `[]`.
 */
export interface RankBook {
  ranks: RankEntry[];
  ownerId: number | null;
  moderatorIds: number[];
  assignments: Record<string, number[]> | [];
}

export default class Channel extends Model {
  // ── Identidade ──
  type = Model.attribute<string>('type');
  name = Model.attribute<string | null>('name');
  slug = Model.attribute<string | null>('slug');
  description = Model.attribute<string | null>('description');
  emoji = Model.attribute<string | null>('emoji');
  imageUrl = Model.attribute<string | null>('imageUrl');
  status = Model.attribute<string>('status');
  tagId = Model.attribute<number | null>('tagId');

  /**
   * Calculado no servidor. Conversas diretas não têm nome salvo e levam o nome
   * dos outros participantes, então é isto que se deve exibir.
   */
  displayName = Model.attribute<string>('displayName');

  // ── Configuração ──
  /** Só por convite: fora do Browse e fechado para quem não é membro. */
  isPrivate = Model.attribute<boolean>('isPrivate');

  /** 'all' ou 'moderators'. */
  postPermission = Model.attribute<string>('postPermission');

  threadingEnabled = Model.attribute<boolean>('threadingEnabled');

  /** Intervalo mínimo entre mensagens de uma pessoa, em segundos. 0 desliga. */
  slowModeSeconds = Model.attribute<number>('slowModeSeconds');

  /**
   * Segundos que este leitor ainda espera. Vem do servidor porque o cliente não
   * sabe, depois de recarregar, quando foi a última mensagem.
   */
  slowModeRemaining = Model.attribute<number>('slowModeRemaining');
  bypassesSlowMode = Model.attribute<boolean>('bypassesSlowMode');

  /**
   * Horário de funcionamento como o servidor guarda (ver Schedule\OpeningHours);
   * null é sempre aberto. Fora dele, só moderadores escrevem.
   */
  schedule = Model.attribute<ChannelScheduleData | null>('schedule');

  /** Se está no horário agora, calculado no servidor. */
  scheduleOpen = Model.attribute<boolean>('scheduleOpen');

  /** Quando abre ou fecha a seguir; null se nada mais está marcado. */
  scheduleNextChange = Model.attribute<Date | null, string | null>('scheduleNextChange', Model.transformDate);

  /** O evento do calendário ligado sumiu: o canal fica fechado. */
  scheduleUnavailable = Model.attribute<boolean>('scheduleUnavailable');

  scheduleEventTitle = Model.attribute<string | null>('scheduleEventTitle');

  /**
   * Null significa herdar a setting do fórum, não "sem limite"
   * (`Channel::maxMessageLength()` no servidor). Use `resolveMaxMessageLength()`.
   */
  maxMessageLength = Model.attribute<number | null>('maxMessageLength');

  autoJoin = Model.attribute<boolean>('autoJoin');

  /** Inscreve quem responde na categoria vinculada. */
  autoJoinOnReply = Model.attribute<boolean>('autoJoinOnReply');
  /** Anuncia no canal as discussões novas da categoria vinculada. */
  postDiscussions = Model.attribute<boolean>('postDiscussions');
  allowChannelWideMentions = Model.attribute<boolean>('allowChannelWideMentions');

  // ── Contadores ──
  messagesCount = Model.attribute<number>('messagesCount');
  userCount = Model.attribute<number>('userCount');
  lastMessageId = Model.attribute<number | null>('lastMessageId');
  lastMessageAt = Model.attribute('lastMessageAt', Model.transformDate);
  createdAt = Model.attribute('createdAt', Model.transformDate);
  archivedAt = Model.attribute('archivedAt', Model.transformDate);
  archivedDiscussionId = Model.attribute<number | null>('archivedDiscussionId');

  // ── Estado do usuário atual ──
  isFollowing = Model.attribute<boolean>('isFollowing');

  /** Convite pendente para o leitor e quem o enviou. */
  isInvited = Model.attribute<boolean>('isInvited');
  invitedById = Model.attribute<number | null>('invitedById');
  invitedByName = Model.attribute<string | null>('invitedByName');
  isMuted = Model.attribute<boolean>('isMuted');
  notificationLevel = Model.attribute<NotificationLevel>('notificationLevel');
  lastReadMessageId = Model.attribute<number>('lastReadMessageId');
  unreadCount = Model.attribute<number>('unreadCount');
  unreadMentionsCount = Model.attribute<number>('unreadMentionsCount');

  // ── Permissões ──
  // Usadas direto em `disabled` e visibilidade, para o cliente não oferecer o
  // que o servidor recusaria.
  canPostMessage = Model.attribute<boolean>('canPostMessage');
  canEdit = Model.attribute<boolean>('canEdit');
  canJoin = Model.attribute<boolean>('canJoin');
  /** Entrar sem aparecer na lista de membros; só moderadores. */
  canJoinHidden = Model.attribute<boolean>('canJoinHidden');
  /** Membro invisível. */
  isHiddenMember = Model.attribute<boolean>('isHiddenMember');
  canClose = Model.attribute<boolean>('canClose');
  canArchive = Model.attribute<boolean>('canArchive');
  canUnarchive = Model.attribute<boolean>('canUnarchive');
  canDelete = Model.attribute<boolean>('canDelete');
  canManageMembers = Model.attribute<boolean>('canManageMembers');
  /** O dono ou moderadores do chat. */
  canManageModerators = Model.attribute<boolean>('canManageModerators');
  /** O dono ou um administrador. */
  canTransferOwnership = Model.attribute<boolean>('canTransferOwnership');
  /** Só preenchido quando os participantes são carregados, como `moderatorIds`. */
  ownershipTransfer = Model.attribute<OwnershipTransferState | null>('ownershipTransfer');
  creatorId = Model.attribute<number | null>('creatorId');

  canManageRanks = Model.attribute<boolean>('canManageRanks');

  /**
   * Ausente na listagem de canais. Leia por `utils/ranks`, que cai no rank que
   * cada mensagem traz.
   */
  rankBook = Model.attribute<RankBook | null | undefined>('rankBook');
  /** Moderadores do canal; preenchido quando os participantes são carregados. */
  moderatorIds = Model.attribute<number[]>('moderatorIds');
  canMentionChannelWide = Model.attribute<boolean>('canMentionChannelWide');

  // ── Relações ──
  creator = Model.hasOne<User | null>('creator');
  lastMessage = Model.hasOne<Message | null>('lastMessage');
  participants = Model.hasMany<User>('participants');

  /** Convites sem resposta; só para quem gerencia membros e quando incluído. */
  invitedUsers = Model.hasMany<User>('invitedUsers');

  /**
   * Incluído por padrão na listagem; `participants` só vem quando pedido (aba de
   * membros). Use `others()` em vez das relações direto.
   */
  directParticipants = Model.hasMany<User>('directParticipants');

  // ── Derivados ──

  isDirect(): boolean {
    return this.type() === 'direct';
  }

  isCategory(): boolean {
    return this.type() === 'category';
  }

  /**
   * Quem vê o canal já está nele. Espelha `Channel::isForEveryone()`: a lista de
   * membros só tem quem o administra e a contagem não é a da sala.
   */
  isForEveryone(): boolean {
    return this.isCategory() && !!this.autoJoin() && !this.isPrivate();
  }

  isOpen(): boolean {
    return this.status() === 'open';
  }

  isClosed(): boolean {
    return this.status() === 'closed';
  }

  isArchived(): boolean {
    return this.status() === 'archived';
  }

  /** Canal silenciado continua listado, mas sem badge. */
  hasUnread(): boolean {
    return !this.isMuted() && (this.unreadCount() ?? 0) > 0;
  }

  hasUnreadMentions(): boolean {
    return (this.unreadMentionsCount() ?? 0) > 0;
  }

  /**
   * Todos menos o leitor. `hasMany` devolve `false` para relação não incluída,
   * então tenta `directParticipants` e depois `participants`.
   */
  others(): User[] {
    const loaded = this.directParticipants() || this.participants() || [];
    const actorId = app.session.user?.id();

    return (loaded as (User | null)[]).filter((user): user is User => Boolean(user) && user!.id() !== actorId);
  }

  apiEndpoint(): string {
    return '/chat-channels' + (this.exists ? '/' + this.id() : '');
  }
}

/** Horário guardado no canal; o `mode` diz quais campos existem. */
export type ChannelScheduleData =
  | { mode: 'window'; start: string; end: string; timezone: string }
  | { mode: 'weekly'; days: number[]; from: string; to: string; timezone: string }
  | { mode: 'event'; eventId: number };
