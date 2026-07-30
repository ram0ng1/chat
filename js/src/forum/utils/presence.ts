import type User from 'flarum/common/models/User';

/**
 * How long after their last activity a user still counts as online.
 *
 * Matches the window core's own online indicator uses, so the chat does not
 * disagree with the rest of the forum about who is around.
 */
const ONLINE_WINDOW_MS = 5 * 60 * 1000;

/**
 * Whether a user is online.
 *
 * Derived from `lastSeenAt`, not from a socket presence channel: flarum/realtime
 * exposes only the shared `public` channel and one private channel per user, with
 * no presence membership to subscribe to. Reading last-seen is therefore the only
 * signal available without inventing a heartbeat, and it is the same one core uses
 * for its own online dot — so the halo agrees with the rest of the forum instead of
 * offering a second, contradictory answer.
 *
 * The cost is granularity: someone who closed their tab four minutes ago still
 * reads as online. That is the accepted trade in core too.
 */
export function isOnline(user: User | null | undefined): boolean {
  if (!user) return false;

  // Users can hide their activity; core nulls the attribute in that case, and a
  // hidden user must not be revealed by the chat.
  const seen = user.lastSeenAt();

  if (!seen) return false;

  return Date.now() - seen.getTime() < ONLINE_WINDOW_MS;
}
