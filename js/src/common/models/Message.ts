import app from "flarum/forum/app";
import Model from "flarum/common/Model";
import type User from "flarum/common/models/User";
import type Channel from "./Channel";
import type Thread from "./Thread";
import type Upload from "./Upload";

/**
 * A single reaction bucket, as sent by MessageResource::reactionSummary().
 * Pre-aggregated on the server so the client never holds every reaction row.
 */
export interface ReactionSummaryEntry {
  count: number;
  reacted: boolean;
}

export type ReactionSummary = Record<string, ReactionSummaryEntry>;

export default class Message extends Model {
  // ── Content ────────────────────────────────────────────────────────────────
  /**
   * Author-facing source text. Null on a deleted message the actor may not see.
   */
  content = Model.attribute<string | null>("content");
  contentHtml = Model.attribute<string | null>("contentHtml");

  type = Model.attribute<string>("type");
  systemKey = Model.attribute<string | null>("systemKey");
  systemData = Model.attribute<Record<string, unknown> | null>("systemData");

  // ── Position ───────────────────────────────────────────────────────────────
  number = Model.attribute<number | null>("number");
  channelId = Model.attribute<number>("channelId");
  threadId = Model.attribute<number | null>("threadId");
  replyToId = Model.attribute<number | null>("replyToId");

  createdAt = Model.attribute("createdAt", Model.transformDate);
  editedAt = Model.attribute("editedAt", Model.transformDate);
  deletedAt = Model.attribute("deletedAt", Model.transformDate);

  isDeleted = Model.attribute<boolean>("isDeleted");

  /**
   * Whether someone other than the author removed it.
   *
   * Distinct from `isRedacted()`, which only says the text was withheld from
   * this reader — true for a self-deleted message too.
   */
  isModeratorDeleted = Model.attribute<boolean>("isModeratorDeleted");
  isEdited = Model.attribute<boolean>("isEdited");

  isPinned = Model.attribute<boolean>("isPinned");
  pinnedAt = Model.attribute("pinnedAt", Model.transformDate);

  // ── Engagement ─────────────────────────────────────────────────────────────
  reactionSummary = Model.attribute<ReactionSummary>("reactionSummary");
  mentionedUsers = Model.attribute<number[]>("mentionedUsers");
  mentionsChannelWide = Model.attribute<boolean>("mentionsChannelWide");
  isBookmarked = Model.attribute<boolean>("isBookmarked");

  /** Whether *this* reader has an open report against it. */
  isFlagged = Model.attribute<boolean>("isFlagged");

  /**
   * Open reports on this message.
   *
   * Withheld from anyone without `ramon-chat.moderate`, so it is undefined rather
   * than zero for ordinary readers — a visible count would tell everyone which
   * messages are being reported, and tell an author they had been.
   */
  flagsCount = Model.attribute<number | undefined>("flagsCount");

  // ── Capability flags ───────────────────────────────────────────────────────
  canEdit = Model.attribute<boolean>("canEdit");
  canDelete = Model.attribute<boolean>("canDelete");
  canReact = Model.attribute<boolean>("canReact");
  canReply = Model.attribute<boolean>("canReply");
  canCreateThread = Model.attribute<boolean>("canCreateThread");
  canMove = Model.attribute<boolean>("canMove");
  canPin = Model.attribute<boolean>("canPin");
  canFlag = Model.attribute<boolean>("canFlag");

  /** Whether the row itself may be removed, tombstone and all. */
  canForceDelete = Model.attribute<boolean>("canForceDelete");

  // ── Relationships ──────────────────────────────────────────────────────────
  user = Model.hasOne<User | null>("user");
  editedBy = Model.hasOne<User | null>("editedBy");
  deletedBy = Model.hasOne<User | null>("deletedBy");
  replyTo = Model.hasOne<Message | null>("replyTo");
  thread = Model.hasOne<Thread | null>("thread");
  channel = Model.hasOne<Channel | null>("channel");
  uploads = Model.hasMany<Upload>("uploads");

  // ── Derived helpers ────────────────────────────────────────────────────────

  isSystem(): boolean {
    return this.type() === "system";
  }

  /**
   * Posted by the chat's bot rather than a person.
   *
   * Not a system message: it has real content and renders through the ordinary
   * message path. Only the author differs — there is no user record, so the name
   * and avatar come from the admin's settings.
   */
  isBot(): boolean {
    return this.type() === "bot";
  }

  /**
   * True when the row exists but its text was withheld — a moderator-removed
   * message shown to someone who may not read it. The stream renders a
   * tombstone so it does not silently reflow.
   */
  isRedacted(): boolean {
    return Boolean(this.isDeleted()) && this.content() === null;
  }

  /**
   * Whether this message mentions the current user, either by name or through
   * @here / @all. Drives the mention highlight.
   */
  mentionsActor(): boolean {
    const actor = app.session.user;

    if (!actor) return false;

    if (this.mentionsChannelWide()) return true;

    return (this.mentionedUsers() ?? []).includes(Number(actor.id()));
  }

  /**
   * Whether this message should render collapsed under the previous one: same
   * author, close in time, and neither is a system message.
   */
  isGroupedWith(
    previous: Message | null | undefined,
    withinSeconds = 300,
  ): boolean {
    if (!previous) return false;
    if (this.isSystem() || previous.isSystem()) return false;
    if (this.threadId() !== previous.threadId()) return false;

    // Two bot posts in a row have the same author, the same as two from one
    // person. Without this they compare `user()` — null for both — and fail the
    // "unknown author" check below, so consecutive announcements each drew their
    // own avatar and header instead of collapsing into a run. A bot post and a
    // human one are never the same author, whichever way round they fall.
    if (this.isBot() || previous.isBot()) {
      if (!this.isBot() || !previous.isBot()) return false;
    } else {
      const a = this.user();
      const b = previous.user();

      if (!a || !b || a.id() !== b.id()) return false;
    }

    // Reached by both branches: same author is necessary but not sufficient, and
    // the time window applies to the bot exactly as it does to anyone else.
    const t1 = this.createdAt();
    const t2 = previous.createdAt();

    if (!t1 || !t2) return false;

    return (t1.getTime() - t2.getTime()) / 1000 <= withinSeconds;
  }

  totalReactions(): number {
    const summary: ReactionSummary = this.reactionSummary() ?? {};

    return Object.values(summary).reduce<number>(
      (sum, entry) => sum + entry.count,
      0,
    );
  }

  apiEndpoint(): string {
    return "/chat-messages" + (this.exists ? "/" + this.id() : "");
  }
}
