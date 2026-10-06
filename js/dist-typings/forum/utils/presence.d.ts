import type User from "flarum/common/models/User";
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
export declare function isOnline(user: User | null | undefined): boolean;
