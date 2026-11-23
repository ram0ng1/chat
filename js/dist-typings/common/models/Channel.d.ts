import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';
import type Message from './Message';
/** Espelha Ramon\Chat\ChannelUser. */
export declare const enum NotificationLevel {
    Never = 0,
    Mentions = 1,
    Always = 2
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
    type: () => string;
    name: () => string | null;
    slug: () => string | null;
    description: () => string | null;
    emoji: () => string | null;
    imageUrl: () => string | null;
    status: () => string;
    tagId: () => number | null;
    /**
     * Calculado no servidor. Conversas diretas não têm nome salvo e levam o nome
     * dos outros participantes, então é isto que se deve exibir.
     */
    displayName: () => string;
    /** Só por convite: fora do Browse e fechado para quem não é membro. */
    isPrivate: () => boolean;
    /** 'all' ou 'moderators'. */
    postPermission: () => string;
    threadingEnabled: () => boolean;
    /** Intervalo mínimo entre mensagens de uma pessoa, em segundos. 0 desliga. */
    slowModeSeconds: () => number;
    /**
     * Segundos que este leitor ainda espera. Vem do servidor porque o cliente não
     * sabe, depois de recarregar, quando foi a última mensagem.
     */
    slowModeRemaining: () => number;
    bypassesSlowMode: () => boolean;
    /**
     * Horário de funcionamento como o servidor guarda (ver Schedule\OpeningHours);
     * null é sempre aberto. Fora dele, só moderadores escrevem.
     */
    schedule: () => ChannelScheduleData | null;
    /** Se está no horário agora, calculado no servidor. */
    scheduleOpen: () => boolean;
    /** Quando abre ou fecha a seguir; null se nada mais está marcado. */
    scheduleNextChange: () => Date | null;
    /** O evento do calendário ligado sumiu: o canal fica fechado. */
    scheduleUnavailable: () => boolean;
    scheduleEventTitle: () => string | null;
    /**
     * Null significa herdar a setting do fórum, não "sem limite"
     * (`Channel::maxMessageLength()` no servidor). Use `resolveMaxMessageLength()`.
     */
    maxMessageLength: () => number | null;
    autoJoin: () => boolean;
    /** Inscreve quem responde na categoria vinculada. */
    autoJoinOnReply: () => boolean;
    /** Anuncia no canal as discussões novas da categoria vinculada. */
    postDiscussions: () => boolean;
    allowChannelWideMentions: () => boolean;
    messagesCount: () => number;
    userCount: () => number;
    lastMessageId: () => number | null;
    lastMessageAt: () => Date | null | undefined;
    createdAt: () => Date | null | undefined;
    archivedAt: () => Date | null | undefined;
    archivedDiscussionId: () => number | null;
    isFollowing: () => boolean;
    /** Convite pendente para o leitor e quem o enviou. */
    isInvited: () => boolean;
    invitedById: () => number | null;
    invitedByName: () => string | null;
    isMuted: () => boolean;
    notificationLevel: () => NotificationLevel;
    lastReadMessageId: () => number;
    unreadCount: () => number;
    unreadMentionsCount: () => number;
    canPostMessage: () => boolean;
    canEdit: () => boolean;
    canJoin: () => boolean;
    /** Entrar sem aparecer na lista de membros; só moderadores. */
    canJoinHidden: () => boolean;
    /** Membro invisível. */
    isHiddenMember: () => boolean;
    canClose: () => boolean;
    canArchive: () => boolean;
    canUnarchive: () => boolean;
    canDelete: () => boolean;
    canManageMembers: () => boolean;
    /** O dono ou moderadores do chat. */
    canManageModerators: () => boolean;
    /** O dono ou um administrador. */
    canTransferOwnership: () => boolean;
    /** Só preenchido quando os participantes são carregados, como `moderatorIds`. */
    ownershipTransfer: () => OwnershipTransferState | null;
    creatorId: () => number | null;
    canManageRanks: () => boolean;
    /**
     * Ausente na listagem de canais. Leia por `utils/ranks`, que cai no rank que
     * cada mensagem traz.
     */
    rankBook: () => RankBook | null | undefined;
    /** Moderadores do canal; preenchido quando os participantes são carregados. */
    moderatorIds: () => number[];
    canMentionChannelWide: () => boolean;
    creator: () => false | User | null;
    lastMessage: () => false | Message | null;
    participants: () => false | (User | undefined)[];
    /** Convites sem resposta; só para quem gerencia membros e quando incluído. */
    invitedUsers: () => false | (User | undefined)[];
    /**
     * Incluído por padrão na listagem; `participants` só vem quando pedido (aba de
     * membros). Use `others()` em vez das relações direto.
     */
    directParticipants: () => false | (User | undefined)[];
    isDirect(): boolean;
    isCategory(): boolean;
    /**
     * Quem vê o canal já está nele. Espelha `Channel::isForEveryone()`: a lista de
     * membros só tem quem o administra e a contagem não é a da sala.
     */
    isForEveryone(): boolean;
    isOpen(): boolean;
    isClosed(): boolean;
    isArchived(): boolean;
    /** Canal silenciado continua listado, mas sem badge. */
    hasUnread(): boolean;
    hasUnreadMentions(): boolean;
    /**
     * Todos menos o leitor. `hasMany` devolve `false` para relação não incluída,
     * então tenta `directParticipants` e depois `participants`.
     */
    others(): User[];
    apiEndpoint(): string;
}
/** Horário guardado no canal; o `mode` diz quais campos existem. */
export type ChannelScheduleData = {
    mode: 'window';
    start: string;
    end: string;
    timezone: string;
} | {
    mode: 'weekly';
    days: number[];
    from: string;
    to: string;
    timezone: string;
} | {
    mode: 'event';
    eventId: number;
};
