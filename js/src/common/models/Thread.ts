import app from "flarum/forum/app";
import Model from "flarum/common/Model";
import type User from "flarum/common/models/User";
import type Channel from "./Channel";
import type Message from "./Message";

/**
 * Per-thread tracking level. Mirrors Ramon\Chat\ThreadUser.
 */
export const enum ThreadTracking {
  Never = 0,
  Mentions = 1,
  Always = 2,
}

export default class Thread extends Model {
  title = Model.attribute<string | null>("title");
  status = Model.attribute<string>("status");

  channelId = Model.attribute<number>("channelId");
  originalMessageId = Model.attribute<number | null>("originalMessageId");
  repliesCount = Model.attribute<number>("repliesCount");
  lastMessageId = Model.attribute<number | null>("lastMessageId");

  lastMessageAt = Model.attribute("lastMessageAt", Model.transformDate);
  createdAt = Model.attribute("createdAt", Model.transformDate);

  // ── Per-actor tracking state ───────────────────────────────────────────────
  notificationLevel = Model.attribute<ThreadTracking>("notificationLevel");
  unreadCount = Model.attribute<number>("unreadCount");
  lastReadMessageId = Model.attribute<number>("lastReadMessageId");
  isParticipating = Model.attribute<boolean>("isParticipating");

  canRename = Model.attribute<boolean>("canRename");
  canPostMessage = Model.attribute<boolean>("canPostMessage");
  canClose = Model.attribute<boolean>("canClose");

  creator = Model.hasOne<User | null>("creator");
  channel = Model.hasOne<Channel | null>("channel");
  originalMessage = Model.hasOne<Message | null>("originalMessage");
  lastMessage = Model.hasOne<Message | null>("lastMessage");

  isOpen(): boolean {
    return this.status() === "open";
  }

  hasUnread(): boolean {
    return (this.unreadCount() ?? 0) > 0;
  }

  /**
   * Falls back to an excerpt of the root message when the thread has no title,
   * which is how an untitled thread stays identifiable in "My Threads".
   */
  displayTitle(excerptLength = 60): string {
    const title = this.title();

    if (title) return title;

    // `hasOne` resolves to `false` when the relationship is not loaded, which is
    // distinct from a loaded-but-null relationship.
    const root = this.originalMessage();
    const content = root ? root.content() : null;

    if (!content) {
      return app.translator.trans("ramon-chat.forum.thread.untitled", {}, true);
    }

    return content.length > excerptLength
      ? content.slice(0, excerptLength).trimEnd() + "…"
      : content;
  }

  apiEndpoint(): string {
    return "/chat-threads" + (this.exists ? "/" + this.id() : "");
  }
}
