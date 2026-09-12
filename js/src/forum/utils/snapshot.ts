import app from "flarum/forum/app";
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

const VERSION = 1;

const KEY_PREFIX = "ramon-chat.snapshot.";

/**
 * Past this the snapshot is skipped rather than trimmed. localStorage is shared
 * with the rest of the forum and quota errors are silent; a chat that filled it
 * would take the forum's own preferences down with it.
 */
const MAX_BYTES = 1_500_000;

/** A week-old tail is more misleading than helpful. */
const MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

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
export function serialize(models: Model[], depth = 3): Document {
  const included: unknown[] = [];
  const seen = new Set<string>();

  for (const model of models) {
    seen.add(`${model.data.type}:${model.id()}`);
  }

  const visit = (resource: Model["data"], level: number): void => {
    const relationships = resource?.relationships;

    if (!relationships || level <= 0) return;

    for (const relationship of Object.values(relationships)) {
      const linkage = (relationship as { data?: unknown } | undefined)?.data;

      if (!linkage) continue;

      for (const ref of Array.isArray(linkage) ? linkage : [linkage]) {
        const type = (ref as { type?: unknown })?.type;
        const id = (ref as { id?: unknown })?.id;

        if (typeof type !== "string" || id === undefined || id === null)
          continue;

        const key = `${type}:${id}`;

        if (seen.has(key)) continue;

        const related = app.store.getById<Model>(type, String(id));

        if (!related) continue;

        seen.add(key);
        included.push(related.data);
        visit(related.data, level - 1);
      }
    }
  };

  for (const model of models) {
    visit(model.data, depth);
  }

  return { data: models.map((model) => model.data), included };
}

export function readSnapshot(userId: string): Snapshot | null {
  try {
    const raw = localStorage.getItem(KEY_PREFIX + userId);

    if (!raw) return null;

    const snapshot = JSON.parse(raw) as Partial<Snapshot> | null;

    if (!snapshot || snapshot.version !== VERSION) return null;
    if (typeof snapshot.savedAt !== "number") return null;
    if (Date.now() - snapshot.savedAt > MAX_AGE_MS) return null;
    if (!snapshot.channels || typeof snapshot.channels !== "object")
      return null;

    return {
      version: VERSION,
      savedAt: snapshot.savedAt,
      channels: snapshot.channels,
      streams: snapshot.streams ?? {},
      pinned: snapshot.pinned ?? {},
    };
  } catch {
    // Private browsing, a quota error, or hand-edited garbage. The chat simply
    // loads the way it always did.
    return null;
  }
}

export function writeSnapshot(userId: string, snapshot: Snapshot): void {
  try {
    const raw = JSON.stringify(snapshot);

    if (raw.length > MAX_BYTES) return;

    localStorage.setItem(KEY_PREFIX + userId, raw);
  } catch {
    // Non-fatal, as above.
  }
}

/**
 * Drops every account's snapshot.
 *
 * Called when the page boots with nobody signed in: the tail of somebody's
 * private channel must not outlive their session on a shared browser. Keyed by
 * user id, so a different account signing in afterwards would never read it —
 * but it should not be on the disk at all.
 */
export function purgeSnapshots(): void {
  try {
    for (let i = localStorage.length - 1; i >= 0; i--) {
      const key = localStorage.key(i);

      if (key?.startsWith(KEY_PREFIX)) localStorage.removeItem(key);
    }
  } catch {
    // Non-fatal.
  }
}
