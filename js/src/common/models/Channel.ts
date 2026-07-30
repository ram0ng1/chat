import Model from "flarum/common/Model";
import type User from "flarum/common/models/User";
import type Message from "./Message";

/**
 * Per-channel notification level. Mirrors Ramon\Chat\ChannelUser.
 */
export const enum NotificationLevel {
  Never = 0,
  Mentions = 1,
  Always = 2,
}

export default class Channel extends Model {
  // ── Identity ───────────────────────────────────────────────────────────────
  type = Model.attribute<string>("type");
  name = Model.attribute<string | null>("name");
  slug = Model.attribute<string | null>("slug");
  description = Model.attribute<string | null>("description");
  emoji = Model.attribute<string | null>("emoji");
  imageUrl = Model.attribute<string | null>("imageUrl");
  status = Model.attribute<string>("status");
  tagId = Model.attribute<number | null>("tagId");

  /**
   * Server-computed label. Direct channels have no stored name — they are named
   * after the other participants, from the reader's perspective — so this is the
   * only correct thing to render.
   */
  displayName = Model.attribute<string>("displayName");

  // ── Configuration ──────────────────────────────────────────────────────────
  /** Invitation-only: absent from Browse, and not joinable by a non-member. */
  isPrivate = Model.attribute<boolean>("isPrivate");

  /** 'all' or 'moderators' — who may post here. */
  postPermission = Model.attribute<string>("postPermission");

  threadingEnabled = Model.attribute<boolean>("threadingEnabled");
  autoJoin = Model.attribute<boolean>("autoJoin");

  /** Subscribe a user when they reply in the bound category. */
  autoJoinOnReply = Model.attribute<boolean>("autoJoinOnReply");
  /** Announce the bound category's new discussions in the channel. */
  postDiscussions = Model.attribute<boolean>("postDiscussions");
  allowChannelWideMentions = Model.attribute<boolean>(
    "allowChannelWideMentions",
  );

  // ── Counters ───────────────────────────────────────────────────────────────
  messagesCount = Model.attribute<number>("messagesCount");
  userCount = Model.attribute<number>("userCount");
  lastMessageId = Model.attribute<number | null>("lastMessageId");
  lastMessageAt = Model.attribute("lastMessageAt", Model.transformDate);
  createdAt = Model.attribute("createdAt", Model.transformDate);
  archivedAt = Model.attribute("archivedAt", Model.transformDate);
  archivedDiscussionId = Model.attribute<number | null>("archivedDiscussionId");

  // ── Per-actor membership state ─────────────────────────────────────────────
  isFollowing = Model.attribute<boolean>("isFollowing");
  isMuted = Model.attribute<boolean>("isMuted");
  notificationLevel = Model.attribute<NotificationLevel>("notificationLevel");
  lastReadMessageId = Model.attribute<number>("lastReadMessageId");
  unreadCount = Model.attribute<number>("unreadCount");
  unreadMentionsCount = Model.attribute<number>("unreadMentionsCount");

  // ── Capability flags ───────────────────────────────────────────────────────
  // Rendered straight into `disabled`/visibility checks so the client never
  // offers a control the server would reject.
  canPostMessage = Model.attribute<boolean>("canPostMessage");
  canEdit = Model.attribute<boolean>("canEdit");
  canJoin = Model.attribute<boolean>("canJoin");
  /** May join without appearing in the member list — moderators only. */
  canJoinHidden = Model.attribute<boolean>("canJoinHidden");
  /** The actor is in this channel, but invisibly. */
  isHiddenMember = Model.attribute<boolean>("isHiddenMember");
  canClose = Model.attribute<boolean>("canClose");
  canArchive = Model.attribute<boolean>("canArchive");
  canDelete = Model.attribute<boolean>("canDelete");
  canManageMembers = Model.attribute<boolean>("canManageMembers");
  canMentionChannelWide = Model.attribute<boolean>("canMentionChannelWide");

  // ── Relationships ──────────────────────────────────────────────────────────
  creator = Model.hasOne<User | null>("creator");
  lastMessage = Model.hasOne<Message | null>("lastMessage");
  participants = Model.hasMany<User>("participants");

  // ── Derived helpers ────────────────────────────────────────────────────────

  isDirect(): boolean {
    return this.type() === "direct";
  }

  isCategory(): boolean {
    return this.type() === "category";
  }

  isOpen(): boolean {
    return this.status() === "open";
  }

  isClosed(): boolean {
    return this.status() === "closed";
  }

  isArchived(): boolean {
    return this.status() === "archived";
  }

  /**
   * Whether a badge should be shown. Muted channels stay listed but never
   * accrue visible pressure.
   */
  hasUnread(): boolean {
    return !this.isMuted() && (this.unreadCount() ?? 0) > 0;
  }

  hasUnreadMentions(): boolean {
    return (this.unreadMentionsCount() ?? 0) > 0;
  }

  apiEndpoint(): string {
    return "/chat-channels" + (this.exists ? "/" + this.id() : "");
  }
}
