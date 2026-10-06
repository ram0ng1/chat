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
    reason: () => string;
    /** The reporter's own words. Always rendered as text, never as HTML. */
    detail: () => string | null;
    messageId: () => number;
    createdAt: () => Date | null | undefined;
    resolvedAt: () => Date | null | undefined;
    isResolved: () => boolean;
    /** Null once the reporter deletes their account — the report outlives them. */
    user: () => false | User | null;
    message: () => false | Message | null;
    resolvedBy: () => false | User | null;
    apiEndpoint(): string;
}
