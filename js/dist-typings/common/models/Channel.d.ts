import Model from "flarum/common/Model";
import type User from "flarum/common/models/User";
import type Message from "./Message";
/**
 * Per-channel notification level. Mirrors Ramon\Chat\ChannelUser.
 */
export declare const enum NotificationLevel {
    Never = 0,
    Mentions = 1,
    Always = 2
}
/**
 * A pending ownership handover. Mirrors ChannelResource::transferState().
 *
 * `incoming` is true for the member it is offered to; `confirmed` is false
 * while the owner has yet to enter the code mailed to them.
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
 * One rank as the server describes it. Mirrors Ramon\Chat\Rank\RankBook.
 *
 * `builtin` names the two every channel has; their `name` and `color` are null
 * until the owner customises them, meaning the translated default and the
 * theme's colour. The three derived colours are null exactly when `color` is.
 */
export interface RankEntry {
    key: string;
    id: number | null;
    builtin: "owner" | "moderator" | null;
    name: string | null;
    color: string | null;
    icon: string | null;
    showBadge: boolean;
    position: number;
    /** Text on the tag: white or near-black, whichever reads better. */
    textColor: string | null;
    /** The colour as a name on a light surface, darkened as far as needed. */
    nameLight: string | null;
    /** And on a dark one, lightened. */
    nameDark: string | null;
}
/**
 * A channel's ranks in priority order, and who holds what. `assignments` maps
 * a user id to the ids of the custom ranks they hold; PHP sends an empty one
 * as `[]`.
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
     * Server-computed label. Direct channels have no stored name — they are named
     * after the other participants, from the reader's perspective — so this is the
     * only correct thing to render.
     */
    displayName: () => string;
    /** Invitation-only: absent from Browse, and not joinable by a non-member. */
    isPrivate: () => boolean;
    /** 'all' or 'moderators' — who may post here. */
    postPermission: () => string;
    threadingEnabled: () => boolean;
    /** Minimum gap between one person's messages, in seconds. 0 is off. */
    slowModeSeconds: () => number;
    /**
     * Seconds this reader must still wait, as the server sees it.
     *
     * Sent per actor rather than derived on the client: a reload has no memory of
     * when you last posted, and a composer that offers a send the server refuses
     * is worse than one that shows the wait.
     */
    slowModeRemaining: () => number;
    bypassesSlowMode: () => boolean;
    /**
     * Longest message this channel accepts, or null to follow the forum setting.
     *
     * Null is "inherit", not "unlimited" — see `Channel::maxMessageLength()` on
     * the server, which is the authority. Resolve it with
     * `resolveMaxMessageLength()` rather than reading it raw.
     */
    maxMessageLength: () => number | null;
    autoJoin: () => boolean;
    /** Subscribe a user when they reply in the bound category. */
    autoJoinOnReply: () => boolean;
    /** Announce the bound category's new discussions in the channel. */
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
    /** A pending invitation for the reader, and who sent it. */
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
    /** May join without appearing in the member list — moderators only. */
    canJoinHidden: () => boolean;
    /** The actor is in this channel, but invisibly. */
    isHiddenMember: () => boolean;
    canClose: () => boolean;
    canArchive: () => boolean;
    /** May take the channel back out of the archive. */
    canUnarchive: () => boolean;
    canDelete: () => boolean;
    canManageMembers: () => boolean;
    /** May promote members to moderators of this channel — its owner, or chat moderators. */
    canManageModerators: () => boolean;
    /** May hand the channel to another member: its owner, or an administrator. */
    canTransferOwnership: () => boolean;
    /**
     * The pending handover, as this reader may see it, or null. Filled only when
     * participants are loaded, like `moderatorIds`.
     */
    ownershipTransfer: () => OwnershipTransferState | null;
    creatorId: () => number | null;
    /** May create, edit and hand out this channel's ranks. */
    canManageRanks: () => boolean;
    /**
     * Every rank here and who holds which. Absent from the channel list, where
     * the server leaves it off; read it through `utils/ranks`, which falls back
     * to the rank each message carries.
     */
    rankBook: () => RankBook | null | undefined;
    /** Members holding the channel's own moderator role; filled when participants are loaded. */
    moderatorIds: () => number[];
    canMentionChannelWide: () => boolean;
    creator: () => false | User | null;
    lastMessage: () => false | Message | null;
    participants: () => false | (User | undefined)[];
    /**
     * People asked in who have not answered. Served only to whoever manages the
     * member list, and only when the members tab includes it.
     */
    invitedUsers: () => false | (User | undefined)[];
    /**
     * The other side of a conversation, default-included on the channel list.
     *
     * `participants` is only populated once something asks for it explicitly — the
     * members tab — so the sidebar cannot read its avatars from there. Use
     * `others()` rather than either relation directly.
     */
    directParticipants: () => false | (User | undefined)[];
    isDirect(): boolean;
    isCategory(): boolean;
    isOpen(): boolean;
    isClosed(): boolean;
    isArchived(): boolean;
    /**
     * Whether a badge should be shown. Muted channels stay listed but never
     * accrue visible pressure.
     */
    hasUnread(): boolean;
    hasUnreadMentions(): boolean;
    /**
     * Everyone in the channel except the reader, for the avatars a direct channel
     * shows in place of an icon.
     *
     * Reads the default-included `directParticipants` first and falls back to
     * `participants`, which is present once the members tab has been opened. Both
     * return `false` from `hasMany` when the relationship was never included, so
     * neither can be trusted on its own.
     */
    others(): User[];
    apiEndpoint(): string;
}
