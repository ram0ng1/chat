import app from "flarum/forum/app";

import type Channel from "../../common/models/Channel";

/** Used when the forum has no setting of its own — mirrors the server default. */
const FALLBACK = 3000;

/**
 * How long a message may be in this channel.
 *
 * One helper because three places need the same answer and must not disagree:
 * the composer's counter, the composer's send guard, and the channel form's
 * "currently" hint. A channel's own value wins; null or zero means it follows
 * the forum, which is what the server does in `Channel::maxMessageLength()`.
 */
export function resolveMaxMessageLength(channel?: Channel | null): number {
  const own = channel?.maxMessageLength?.();

  if (own && own > 0) return own;

  return forumMaxMessageLength();
}

/** The forum-wide setting, for channels that do not override it. */
export function forumMaxMessageLength(): number {
  const forum = Number(
    app.forum.attribute<number>("ramon-chat.maxMessageLength") ?? FALLBACK,
  );

  return forum > 0 ? forum : FALLBACK;
}
