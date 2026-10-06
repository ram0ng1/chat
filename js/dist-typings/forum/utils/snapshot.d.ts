import type Model from "flarum/common/Model";
/**
 * A JSON:API document, in the shape `Store.pushPayload` accepts.
 */
export interface Document {
    data: unknown[];
    included?: unknown[];
    meta?: Record<string, unknown>;
}
/**
 * What the last visit left behind, so the next one can paint before it asks
 * the server anything.
 *
 * Only the sidebar and the tail of a few recently viewed channels — the same
 * shape the boot payload has (see Content\PreloadChat), so ChatState hydrates
 * both through the same code. Everything read from here is marked stale and
 * revalidated the moment it is on screen; the snapshot buys the first paint,
 * not the truth.
 */
export interface Snapshot {
    version: number;
    savedAt: number;
    channels: Document;
    streams: Record<string, Document>;
    pinned: Record<string, Document>;
}
/**
 * Rebuilds a JSON:API document from models already in the store.
 *
 * Every model keeps the resource object it was hydrated from as `data`, and
 * the store holds everything that arrived as `included`. Walking the primary
 * models' relationships and collecting what the store still has for each
 * linkage produces the document the server would have sent — including rows
 * that arrived over the websocket after the original request, which no captured
 * response could carry.
 *
 * Bounded by depth rather than by type so a new relationship on a model is
 * picked up without this needing to know about it. Three levels reach
 * `replyTo.user.groups`, the deepest thing the message list includes.
 */
export declare function serialize(models: Model[], depth?: number): Document;
export declare function readSnapshot(userId: string): Snapshot | null;
export declare function writeSnapshot(userId: string, snapshot: Snapshot): void;
/**
 * Drops every account's snapshot.
 *
 * Called when the page boots with nobody signed in: the tail of somebody's
 * private channel must not outlive their session on a shared browser. Keyed by
 * user id, so a different account signing in afterwards would never read it —
 * but it should not be on the disk at all.
 */
export declare function purgeSnapshots(): void;
