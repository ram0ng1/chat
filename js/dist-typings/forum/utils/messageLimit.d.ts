import type Channel from "../../common/models/Channel";
/**
 * How long a message may be in this channel.
 *
 * One helper because three places need the same answer and must not disagree:
 * the composer's counter, the composer's send guard, and the channel form's
 * "currently" hint. A channel's own value wins; null or zero means it follows
 * the forum, which is what the server does in `Channel::maxMessageLength()`.
 */
export declare function resolveMaxMessageLength(channel?: Channel | null): number;
/** The forum-wide setting, for channels that do not override it. */
export declare function forumMaxMessageLength(): number;
