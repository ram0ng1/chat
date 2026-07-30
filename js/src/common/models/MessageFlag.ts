import Model from "flarum/common/Model";
import type User from "flarum/common/models/User";

import type Message from "./Message";

/**
 * A report filed against a chat message.
 *
 * The chat keeps its own rather than reusing flarum/flags: that extension's
 * `flags.post_id` is a non-nullable foreign key into `posts`, so a chat message id
 * cannot be stored there at all.
 */
export default class MessageFlag extends Model {
  /** One of the keys MessageFlag::REASONS lists on the server. */
  reason = Model.attribute<string>("reason");

  /** The reporter's own words. Always rendered as text, never as HTML. */
  detail = Model.attribute<string | null>("detail");

  messageId = Model.attribute<number>("messageId");

  createdAt = Model.attribute("createdAt", Model.transformDate);
  resolvedAt = Model.attribute("resolvedAt", Model.transformDate);
  isResolved = Model.attribute<boolean>("isResolved");

  /** Null once the reporter deletes their account — the report outlives them. */
  user = Model.hasOne<User | null>("user");
  message = Model.hasOne<Message | null>("message");
  resolvedBy = Model.hasOne<User | null>("resolvedBy");

  apiEndpoint(): string {
    return "/chat-message-flags" + (this.exists ? "/" + this.id() : "");
  }
}
