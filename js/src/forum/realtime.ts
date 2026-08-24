import app from "flarum/forum/app";

import chatState from "./state/chat";
import { playNotificationSound } from "./utils/sound";
import type Message from "../common/models/Message";
import { NotificationLevel } from "../common/models/Channel";

/**
 * Wire event names. Must match Ramon\Chat\Realtime\BroadcastListener.
 */
const EVENT_MESSAGE = "ramonChat.message";
const EVENT_MESSAGE_CHANGED = "ramonChat.messageChanged";
const EVENT_MESSAGE_PURGED = "ramonChat.messagePurged";
const EVENT_REACTION = "ramonChat.reaction";
const EVENT_THREAD = "ramonChat.thread";
const EVENT_CHANNEL = "ramonChat.channel";
const EVENT_TYPING = "ramonChat.typing";

interface UploadPayload {
  id: number;
  fileName: string;
  mimeType: string | null;
  size: number;
  width: number | null;
  height: number | null;
  url: string;
  isImage: boolean;
  createdAt: string | null;
}

interface GroupPayload {
  id: number;
  nameSingular: string;
  namePlural: string;
  color: string | null;
  icon: string | null;
}

interface MessagePayload {
  id: number;
  channelId: number;
  threadId: number | null;
  replyToId: number | null;
  number: number | null;
  userId: number | null;
  type: string;
  systemKey: string | null;
  /** Placeholders the system string interpolates. See BroadcastListener. */
  systemData?: Record<string, unknown> | null;
  /** The author, inlined so a recipient who has never seen them can still draw the row. */
  user?: {
    id: number;
    username: string;
    displayName: string;
    avatarUrl: string | null;
    slug: string;
    /** Non-hidden groups only — see BroadcastListener::groupsPayload. */
    groups?: GroupPayload[];
  } | null;
  contentHtml: string | null;
  createdAt: string | null;
  editedAt: string | null;
  isDeleted: boolean;
  /** Whether the deletion was somebody else's doing. See BroadcastListener. */
  isModeratorDeleted?: boolean;
  deletedById?: number | null;
  isPinned?: boolean;
  pinnedAt?: string | null;
  uploads?: UploadPayload[];
  /** Who the message is addressed to. Drives the highlight and the sound. */
  mentionedUsers?: number[];
  mentionsChannelWide?: boolean;
}

/**
 * Binds chat events on the actor's private websocket channel.
 *
 * Chat is delivered per-user rather than on the shared `public` channel — see
 * Ramon\Chat\Realtime\ChatBroadcaster for why that is a privacy boundary. The
 * client therefore only ever binds to the user channel.
 *
 * Returns false when flarum/realtime is unavailable, so the caller can fall back
 * to polling.
 */
let bound = false;

/**
 * Binds the handlers to a Pusher channel. Idempotent.
 */
function bindTo(channel: any): void {
  if (bound || !channel?.bind) return;

  // Wrapped rather than each handler setting the flag itself: `delivered` has to
  // be true for *any* chat event, and a handler added later would otherwise be a
  // silent hole in the proof.
  const on = (event: string, handler: (data: any) => void) =>
    channel.bind(event, (data: any) => {
      delivered = true;
      handler(data);
    });

  on(EVENT_MESSAGE, (data: MessagePayload) => onMessage(data));
  on(EVENT_MESSAGE_CHANGED, (data: MessagePayload) => onMessageChanged(data));
  on(EVENT_MESSAGE_PURGED, (data: any) => onMessagePurged(data));
  on(EVENT_REACTION, (data: any) => onReaction(data));
  on(EVENT_THREAD, (data: any) => onThread(data));
  on(EVENT_CHANNEL, (data: any) => onChannel(data));
  on(EVENT_TYPING, (data: any) => onTyping(data));

  bound = true;
}

/**
 * Returns the user's private channel, subscribing if realtime has not already.
 *
 * Reads `app.websocket_channels.user` — the channel realtime stores on the app
 * instance — rather than going through `flarum.reg`. Registry lookups are the
 * fragile path: a module registered via `addChunkModule` resolves to `undefined`
 * until its chunk executes, and a silent `undefined` here means the handlers are
 * never bound and every message arrives via the polling fallback instead. Plain
 * instance properties have no such failure mode.
 *
 * `subscribe()` is safe to call again: Pusher returns the existing subscription
 * for a channel name it already holds.
 */
function userChannel(): any | null {
  const ws = (app as any).websocket;
  const channels = (app as any).websocket_channels;

  if (channels?.user) return channels.user;

  const id = app.session.user?.id();

  if (!ws?.subscribe || !id) return null;

  const channel = ws.subscribe("private-user=" + id);

  if (channels) channels.user = channel;

  return channel;
}

/**
 * Binds chat events on the actor's private websocket channel.
 *
 * Chat is delivered per-user rather than on the shared `public` channel — see
 * Ramon\Chat\Realtime\ChatBroadcaster for why that is a privacy boundary.
 *
 * Returns whether binding succeeded. It retries briefly because realtime creates
 * `app.websocket` inside its own `mount` extension, and extension load order is
 * not guaranteed — without the retry, being a few milliseconds early would
 * permanently downgrade the client to polling.
 */
export function bindRealtime(): boolean {
  if (!("flarum-realtime" in (flarum.extensions ?? {}))) return false;

  // The sanctioned hand-off, and the fast one.
  //
  // flarum/realtime creates its client inside its own `mount` extender, so
  // whether the channel exists when we ask depends on extension load order —
  // which is why this used to poll. It registers `RealtimeState` eagerly at the
  // top level of its forum bundle (not inside an initializer, and not as a
  // lazily loaded chunk) precisely so extensions do not have to: the state
  // object runs our callback the moment the user channel is subscribed, or
  // immediately if it already is.
  //
  // That removes up to 4 seconds of the chat sitting on the polling fallback at
  // startup, and with it the window where a message that arrived during boot was
  // only picked up by the next poll.
  if (bindViaRealtimeState()) return true;

  bindTo(userChannel());

  if (bound) return true;

  // Realtime is present but older than the RealtimeState hand-off, or has not set
  // up its client yet. Retry on a short schedule; the window is generous because
  // the cost of giving up is a 15× slower chat.
  let attempts = 0;

  const timer = window.setInterval(() => {
    attempts++;

    try {
      bindTo(userChannel());
    } catch {
      // Keep trying — a transient failure during startup is not terminal.
    }

    if (bound || attempts >= 40) {
      window.clearInterval(timer);

      if (!bound) {
        console.warn(
          "[ramon-chat] websocket unavailable; falling back to polling",
        );
        startPollingFallback();
      }
    }
  }, 100);

  // Reported as bound: the retry either succeeds or starts polling itself, and
  // returning false here would start a second poller.
  return true;
}

/**
 * Registers the handlers through flarum/realtime's own readiness callback.
 *
 * Returns whether the hand-off was available at all — not whether the channel
 * was ready. A registered callback that has not fired yet is still a success:
 * it will fire, and starting the retry loop alongside it would race it.
 *
 * Read through `flarum.reg` because this is another extension's module. The
 * registry is normally the fragile path — an `addChunkModule` entry resolves to
 * `undefined` until its chunk executes — but this particular entry is added by a
 * bare `flarum.reg.add()` at the top level of realtime's forum bundle, so it is
 * present as soon as that bundle has run.
 */
function bindViaRealtimeState(): boolean {
  try {
    const state = flarum.reg.get("flarum-realtime", "forum/RealtimeState") as
      { onUserChannelReady?: (cb: (channel: any) => void) => void } | undefined;

    if (typeof state?.onUserChannelReady !== "function") return false;

    state.onUserChannelReady((channel: any) => bindTo(channel));

    return true;
  } catch {
    // An older realtime, or a registry that does not hold it. The caller falls
    // back to reading the channel off the app instance.
    return false;
  }
}

/** Set by index.tsx so the retry can start polling without a circular import. */
let startPollingFallback: () => void = () => {};

export function setPollingFallback(fn: () => void): void {
  startPollingFallback = fn;
}

/** Whether the websocket handlers are live, for diagnostics. */
export function realtimeBound(): boolean {
  return bound;
}

/**
 * Whether anything has ever actually arrived over the socket.
 *
 * Distinct from `bound`, and the distinction is the whole point: subscribing
 * succeeds on the client whatever the server can or cannot do. If the forum's PHP
 * process cannot reach the websocket daemon — a common enough split, since the
 * queue worker and the web process are not always on the same side of a
 * firewall — every client sits on a healthy-looking socket that will never carry
 * a chat event, and the chat quietly stops updating until the page is reloaded.
 *
 * A delivered event is the only proof the whole round-trip works, so the poller
 * uses this to decide how hard it has to work. See startPolling() in index.tsx.
 */
export function realtimeDelivered(): boolean {
  return delivered;
}

let delivered = false;

/**
 * Pushes an incoming message into the store and the channel's stream.
 *
 * The payload is a compact projection rather than a JSON:API document, so it is
 * translated into the store's shape here. Relationships are attached by id only
 * when the referenced user is already known; a missing author renders as an
 * unnamed row rather than triggering a fetch per message.
 */
function onMessage(data: MessagePayload): void {
  const message = pushMessage(data);

  if (!message) return;

  chatState.upsertMessage(message);

  // Whatever they were typing, they have now said it. The typing entry would
  // otherwise sit under their own message for the rest of its window — which is
  // what "X is typing…" under a message from X was.
  if (data.userId) {
    chatState.clearTyping(data.channelId, data.userId);
  }

  bumpChannel(data);
  bumpThread(data);
  announce(data, message);
  queueReconcile(data.channelId, data.id);

  m.redraw();
}

/**
 * Oldest realtime-delivered message id still awaiting an authoritative read,
 * per channel.
 */
const reconcileFloor = new Map<number, number>();
let reconcileTimer: number | null = null;

/**
 * Delay before reconciling. Long enough that a burst of messages costs one
 * request rather than one each, short enough that the row's actions appear while
 * the message is still the thing being looked at.
 */
const RECONCILE_DELAY = 500;

/**
 * Re-reads recently pushed messages from the API.
 *
 * The wire payload is a lossy projection of the resource: it is built once and
 * sent to every member of the channel, so it cannot carry anything that depends
 * on who is receiving it. `canDelete`, `canEdit`, `canPin` and the rest are
 * therefore pushed closed — offering an action the server would refuse is worse
 * than withholding one — and a moderator watching a live channel saw no controls
 * on anything until the next page load.
 *
 * Reading the rows back is what fills those in, and it is the same fetch the
 * polling fallback already makes, so the Index endpoint's `defaultInclude` also
 * supplies the relations the payload can only reference by id. Batched by
 * channel: a run of twenty messages resolves in one request for the tail.
 */
function queueReconcile(channelId: number, messageId: number): void {
  const floor = reconcileFloor.get(channelId);

  if (floor === undefined || messageId < floor) {
    reconcileFloor.set(channelId, messageId);
  }

  if (reconcileTimer !== null) return;

  reconcileTimer = window.setTimeout(() => {
    reconcileTimer = null;

    const pending = [...reconcileFloor.entries()];
    reconcileFloor.clear();

    for (const [channel, oldest] of pending) {
      app.store
        .find<Message[]>("chat-messages", {
          // `greaterThan` is exclusive, so step back one to include the oldest
          // message of the burst itself.
          filter: { channel, greaterThan: oldest - 1 },
          sort: "id",
          page: { limit: 50 },
        })
        .then((results) => {
          for (const message of (Array.isArray(results)
            ? results
            : []) as Message[]) {
            chatState.upsertMessage(message);
          }

          m.redraw();
        })
        // A failed reconcile leaves the row as the push built it: visible, with
        // its actions withheld. That is the state before this existed, and it
        // resolves on the next load — not worth an alert.
        .catch(() => {});
    }
  }, RECONCILE_DELAY);
}

/**
 * Sounds the notification for an incoming message.
 *
 * Skipped when the message's channel is the one on screen and the tab has focus:
 * you watched it arrive, so a chime adds nothing. Skipped for a muted channel for
 * the same reason the badge is.
 *
 * And skipped according to the channel's notification level, which is the fix for
 * a chat that beeped continuously. Every membership already carries that setting
 * — Never, Mentions or Always — and it defaults to Mentions, but the sound
 * consulted only `isMuted()`. So a channel the user had explicitly set to
 * "mentions only" still chimed on every message that arrived in it, and a member
 * of a dozen busy channels heard a chime for traffic they had asked not to be
 * told about. The badge honoured the setting; the sound did not.
 *
 * Own messages never reach here — ChatBroadcaster excludes the actor from its own
 * broadcast.
 */
function announce(data: MessagePayload, message: Message): void {
  const channel = chatState.channel(data.channelId);

  if (channel?.isMuted()) return;

  const watching =
    chatState.activeChannelId === data.channelId &&
    !chatState.drawerCollapsed &&
    document.visibilityState === "visible" &&
    document.hasFocus();

  if (watching) return;

  // Absent means the channel is not in the sidebar yet; treat it the way the
  // default membership does rather than as "tell me everything".
  const level = channel?.notificationLevel() ?? NotificationLevel.Mentions;

  if (level === NotificationLevel.Never) return;

  if (level === NotificationLevel.Mentions) {
    // A direct message is addressed to you by construction — there is nobody
    // else in the room to have meant it for.
    const direct = channel?.isDirect() ?? false;

    if (!direct && !message.mentionsActor()) return;
  }

  playNotificationSound();
}

/**
 * Advances a thread's reply count when one of its replies arrives.
 *
 * The server increments the authoritative counter, but only broadcasts a thread
 * event when a thread is *created*. Without this the indicator under the root
 * message would keep showing a stale count until the next fetch.
 */
function bumpThread(data: MessagePayload): void {
  if (!data.threadId) return;

  const thread = app.store.getById("chat-threads", String(data.threadId));

  if (!thread) return;

  // Not counted for the root itself, which is the thread, not a reply to it.
  if (thread.attribute<number | null>("originalMessageId") === data.id) return;

  // Nor for anything the thread's own count already reaches. Creating a thread
  // produces two broadcasts describing one reply — the thread event, whose
  // `repliesCount` includes the message that opened it, and the message event
  // itself — and counting both made every new thread arrive claiming two.
  if (data.id <= Number(thread.attribute<number>("lastMessageId") ?? 0)) return;

  thread.pushAttributes({
    repliesCount: Number(thread.attribute<number>("repliesCount") ?? 0) + 1,
    lastMessageId: data.id,
  });
}

function onMessageChanged(data: MessagePayload): void {
  const existing = app.store.getById("chat-messages", String(data.id)) as
    Message | undefined;

  // Only reconcile messages already on this client. A change to something never
  // loaded is not worth materialising.
  if (!existing) {
    // With one exception. A pin on a message we are not holding is precisely
    // what the pinned bar's fetched preview exists to show — it sits above the
    // loaded window — and there is no model here to update in place. Dropping
    // the cached preview makes the next open of the channel ask again.
    if (data.isPinned) {
      chatState.invalidatePinnedPreview(data.channelId);
    }

    return;
  }

  const wasDeleted = Boolean(existing.isDeleted());

  existing.pushAttributes({
    contentHtml: data.contentHtml,
    editedAt: data.editedAt,
    isDeleted: data.isDeleted,
    isEdited: Boolean(data.editedAt),
    // The tombstone's wording turns on this. Left unset, a moderator's deletion
    // read to its author exactly like their own — "this message was deleted" —
    // and the fact that it had been moderated surfaced only on reload.
    isModeratorDeleted: Boolean(data.isModeratorDeleted),
    // Carried on the same event as edits and deletions: a pin changes what
    // everyone in the channel sees first, so it has to land without a refresh.
    isPinned: Boolean(data.isPinned),
    pinnedAt: data.pinnedAt ?? null,
    ...(data.isDeleted ? { content: null } : {}),
  });

  // Names the moderator, when the recipient already holds that user. The
  // tombstone falls back to the unnamed wording when it does not, so this is an
  // upgrade rather than a requirement — and it costs no request.
  if (data.isModeratorDeleted && data.deletedById) {
    const moderator = app.store.getById("users", String(data.deletedById));

    if (moderator) {
      existing.pushData({ relationships: { deletedBy: moderator } } as any);
    }
  }

  // Deleting or restoring moves what this actor may do *to* the row, and those
  // answers differ per recipient so they cannot ride on the broadcast. Purging
  // in particular only becomes possible once the message is already deleted, so
  // the control for it appeared only on the next page load — the flag it is
  // drawn from was false when the row was pushed and nothing had refreshed it.
  //
  // Only on a flip: an edit or a pin shares this event and moves none of them.
  if (Boolean(data.isDeleted) !== wasDeleted) {
    refreshMessageCapabilities(data.id);
  }

  m.redraw();
}

/** Messages with a capability refetch already in flight. */
const refetchingMessages = new Set<number>();

/**
 * Re-reads one message so the actor's own capability flags are the server's.
 *
 * The Show endpoint carries the same `defaultInclude` the listing does, so this
 * also lands `deletedBy` — which is what lets a tombstone name the moderator
 * rather than falling back to the unnamed wording.
 *
 * Exported because the moderator who performs the deletion needs it too, and
 * cannot get it from here: `whenMessageChanged` excludes the actor, so their own
 * client never sees the event. See ChatMessage.delete().
 */
export function refreshMessageCapabilities(id: number): void {
  if (refetchingMessages.has(id)) return;

  refetchingMessages.add(id);

  app.store
    .find("chat-messages", String(id))
    // A purge racing the refetch leaves nothing to read. The row is being
    // removed anyway, so there is nothing to report.
    .catch(() => {})
    .then(() => {
      refetchingMessages.delete(id);
      m.redraw();
    });
}

/**
 * Drops a message that was removed outright.
 *
 * Its own handler rather than a branch of `onMessageChanged`, because there is
 * no row left to restyle: an ordinary deletion becomes a tombstone and keeps its
 * place in the stream, and a purge is what clears the tombstone away. Without
 * this, "delete for everyone" cleared it for the moderator alone and everyone
 * else kept the tombstone until they reloaded.
 */
function onMessagePurged(data: {
  id: number;
  channelId: number;
  threadId: number | null;
}): void {
  chatState.removeMessage(data.channelId, data.id);

  // The thread's own panel renders from a separate stream keyed by thread id, so
  // a purge inside one has to be dropped there as well.
  if (data.threadId) {
    chatState.removeThreadMessage(data.threadId, data.id);

    // The count under the root now overstates the thread by one. Never below
    // zero: the reply may have arrived before this client ever counted it.
    const thread = app.store.getById("chat-threads", String(data.threadId));

    if (thread) {
      thread.pushAttributes({
        repliesCount: Math.max(
          0,
          Number(thread.attribute<number>("repliesCount") ?? 0) - 1,
        ),
      });
    }
  }

  m.redraw();
}

function onReaction(data: {
  messageId: number;
  emoji: string;
  userId: number;
  added: boolean;
}): void {
  const message = app.store.getById("chat-messages", String(data.messageId)) as
    Message | undefined;

  if (!message) return;

  const summary = { ...(message.reactionSummary() ?? {}) };
  const entry = summary[data.emoji] ?? { count: 0, reacted: false };

  const isActor = Number(app.session.user?.id()) === data.userId;

  summary[data.emoji] = {
    count: Math.max(0, entry.count + (data.added ? 1 : -1)),
    // Never let someone else's reaction flip our own "reacted" flag.
    reacted: isActor ? data.added : entry.reacted,
  };

  if (summary[data.emoji].count === 0) delete summary[data.emoji];

  message.pushAttributes({ reactionSummary: summary });
  m.redraw();
}

/**
 * Lands a thread that was just created, or refreshes one already known.
 *
 * This used to `getById` and give up when the lookup missed — which is every
 * `ThreadWasCreated` broadcast, because a thread that has just come into
 * existence is by definition not in anyone else's store yet. The recipient
 * therefore never learned the thread existed and the root message kept drawing
 * without its "N replies" strip until the next page load, where the Index
 * endpoint's `defaultInclude` supplies both the record and the link to it.
 *
 * `originalMessageId` is read from the payload rather than ignored: the strip is
 * drawn from the *message's* `thread` relationship, and the message's own wire
 * payload carries only a `threadId`. Nothing else connects the two.
 */
function onThread(data: {
  threadId: number;
  channelId: number;
  originalMessageId: number | null;
  repliesCount: number;
  lastMessageId: number | null;
  title: string | null;
}): void {
  const thread = app.store.pushPayload({
    data: {
      type: "chat-threads",
      id: String(data.threadId),
      attributes: {
        channelId: data.channelId,
        originalMessageId: data.originalMessageId,
        repliesCount: data.repliesCount,
        // How far `repliesCount` reaches, so bumpThread() does not count the
        // opening reply a second time when its own event lands.
        lastMessageId: data.lastMessageId,
        title: data.title,
      },
    },
  } as any);

  // Only when the root is already loaded. Pushing a relationship onto an absent
  // message would mint a stub record carrying nothing but a thread id, and
  // `getById` elsewhere would then hand that stub out as though it were the
  // message. A root nobody has scrolled to has no strip to draw anyway.
  const root =
    data.originalMessageId !== null
      ? app.store.getById("chat-messages", String(data.originalMessageId))
      : null;

  if (root && thread) {
    // Attributes merge, so this leaves the rest of the row alone.
    root.pushData({
      attributes: { threadId: data.threadId },
      relationships: { thread },
    } as any);
  }

  m.redraw();
}

interface ChannelPayload {
  channelId: number;
  status: string;
  postPermission?: string;
  isPrivate?: boolean;
  threadingEnabled?: boolean;
  slowModeSeconds?: number;
  name?: string | null;
  emoji?: string | null;
  description?: string | null;
}

/** Channels with a capability refetch already in flight. */
const refetching = new Set<number>();

function onChannel(data: ChannelPayload): void {
  const channel = chatState.channel(data.channelId);

  if (!channel) return;

  const before = channel.postPermission();
  const slowModeBefore = channel.slowModeSeconds();

  channel.pushAttributes({
    status: data.status,
    ...(data.postPermission !== undefined
      ? { postPermission: data.postPermission }
      : {}),
    ...(data.isPrivate !== undefined ? { isPrivate: data.isPrivate } : {}),
    ...(data.threadingEnabled !== undefined
      ? { threadingEnabled: data.threadingEnabled }
      : {}),
    ...(data.slowModeSeconds !== undefined
      ? { slowModeSeconds: data.slowModeSeconds }
      : {}),
    ...(data.name !== undefined ? { name: data.name } : {}),
    ...(data.emoji !== undefined ? { emoji: data.emoji } : {}),
    ...(data.description !== undefined
      ? { description: data.description }
      : {}),
  });

  // Some of what these settings decide is answered per user, so it cannot ride
  // on a broadcast — a moderator and a member get different answers from the
  // same change. `canPostMessage` follows `postPermission`, and
  // `slowModeRemaining` follows the slow-mode window, since a holder of
  // `bypassSlowMode` reads it as zero whatever the channel says. Refetch this
  // client's own record and let the server say.
  //
  // Only when a rule actually moved, so an ordinary rename does not cost every
  // member in the channel a request.
  const permissionMoved =
    data.postPermission !== undefined && data.postPermission !== before;

  const slowModeMoved =
    data.slowModeSeconds !== undefined &&
    data.slowModeSeconds !== slowModeBefore;

  if (permissionMoved || slowModeMoved) {
    refreshCapabilities(data.channelId);
  }

  m.redraw();
}

/**
 * Re-reads one channel to pick up the actor's own capability flags.
 *
 * Guarded against overlapping calls: a burst of edits would otherwise queue a
 * request per event, and they would land out of order.
 */
function refreshCapabilities(channelId: number): void {
  if (refetching.has(channelId)) return;

  refetching.add(channelId);

  app.store
    .find("chat-channels", String(channelId))
    .catch(() => {
      // The channel may have become invisible to us — a private channel we were
      // removed from. Leaving the stale record is better than throwing; the next
      // channel list refresh drops it.
    })
    .then(() => {
      refetching.delete(channelId);
      m.redraw();
    });
}

function onTyping(data: {
  channelId: number;
  userId: number;
  username: string;
  typing: boolean;
  expiresIn: number;
}): void {
  chatState.noteTyping(
    data.channelId,
    data.userId,
    data.username,
    data.typing,
    data.expiresIn,
  );
  m.redraw();
}

/**
 * Translates the compact wire payload into a store record.
 *
 * Attachments are pushed as `included` records with a to-many `uploads`
 * relationship — the same shape the Create endpoint's `defaultInclude` returns.
 * Without them the sender saw their own image (their view came from the API
 * response) while every recipient rendered the message with nothing in it.
 */
function pushMessage(data: MessagePayload): Message | null {
  const uploads = Array.isArray(data.uploads) ? data.uploads : [];

  // The author goes in `included` beside the uploads, so the `user` relationship
  // below resolves to a record instead of a dangling reference. Without it, a
  // recipient who has never seen this person renders the row as "[deleted]" —
  // which is what happens on a fresh page for every author but yourself.

  // The author's groups ride along so the badges on their avatar are drawn on the
  // pushed row, not only after the next load. They go in as their own `groups`
  // records with the relationship pointing at them, which is the shape the API
  // sends for `include=user.groups` — anything else and `user.badges()` reads an
  // unresolved reference and renders nothing.
  const groups = data.user?.groups ?? [];
  const groupRecords = groups.map((group) => ({
    type: "groups",
    id: String(group.id),
    attributes: {
      nameSingular: group.nameSingular,
      namePlural: group.namePlural,
      color: group.color,
      icon: group.icon,
      // Stated rather than left undefined: the broadcast carries only non-hidden
      // groups (BroadcastListener::groupsPayload), so this is the true value and
      // anything reading `isHidden()` off a pushed record gets an answer.
      isHidden: false,
    },
  }));

  const author = data.user
    ? [
        {
          type: "users",
          id: String(data.user.id),
          attributes: {
            username: data.user.username,
            displayName: data.user.displayName,
            avatarUrl: data.user.avatarUrl,
            slug: data.user.slug,
          },
          // Omitted rather than sent empty when the payload carries no groups:
          // pushing `[]` would clear the groups an earlier load had already put
          // on this user, blanking badges that were rendering a moment ago.
          ...(groupRecords.length
            ? {
                relationships: {
                  groups: {
                    data: groupRecords.map(({ type, id }) => ({ type, id })),
                  },
                },
              }
            : {}),
        },
      ]
    : [];

  try {
    return app.store.pushPayload<Message>({
      included: [
        ...author,
        ...groupRecords,
        ...uploads.map((upload) => ({
          type: "chat-uploads",
          id: String(upload.id),
          attributes: {
            fileName: upload.fileName,
            mimeType: upload.mimeType,
            size: upload.size,
            width: upload.width,
            height: upload.height,
            url: upload.url,
            isImage: upload.isImage,
            createdAt: upload.createdAt,
            // Not null: `isPending()` treats a null messageId as an attachment
            // still sitting in someone's composer, which would render it as a
            // draft chip instead of a sent image.
            messageId: data.id,
          },
          relationships: data.userId
            ? { user: { data: { type: "users", id: String(data.userId) } } }
            : {},
        })),
      ],
      data: {
        type: "chat-messages",
        id: String(data.id),
        attributes: {
          channelId: data.channelId,
          threadId: data.threadId,
          replyToId: data.replyToId,
          number: data.number,
          type: data.type,
          systemKey: data.systemKey,
          systemData: data.systemData ?? null,
          contentHtml: data.contentHtml,
          createdAt: data.createdAt,
          editedAt: data.editedAt,
          isDeleted: data.isDeleted,
          isModeratorDeleted: Boolean(data.isModeratorDeleted),
          isEdited: Boolean(data.editedAt),
          isPinned: Boolean(data.isPinned),
          pinnedAt: data.pinnedAt ?? null,
          reactionSummary: {},
          // Read from the payload rather than blanked. Hardcoding these meant a
          // message arriving live was never recognised as a mention: it drew
          // without the highlight, and the sound had no way to tell an @you from
          // ordinary chatter.
          mentionedUsers: Array.isArray(data.mentionedUsers)
            ? data.mentionedUsers
            : [],
          mentionsChannelWide: Boolean(data.mentionsChannelWide),
          isBookmarked: false,
          // Capability flags default closed: the push payload cannot know them,
          // and offering an action the server would refuse is worse than
          // withholding it until the row is re-fetched.
          canEdit: false,
          canDelete: false,
          canReact: true,
          canReply: true,
          canCreateThread: false,
          canMove: false,
          canPin: false,
        },
        relationships: {
          ...(data.userId
            ? { user: { data: { type: "users", id: String(data.userId) } } }
            : {}),
          // The quoted line above a reply is drawn from this relationship, not
          // from `replyToId` — and only the attribute was being set, so a reply
          // arriving live rendered as an ordinary message and only grew its
          // quote on the next page load, where the Index endpoint's
          // `defaultInclude` supplies the relation.
          //
          // A linkage is enough: you reply to something you can see, so the
          // target is already a record in the store and `hasOne` resolves it.
          // When it is not — the target scrolled out of the loaded window —
          // `hasOne` yields undefined and `replyPreview` renders nothing, which
          // is what happens today anyway.
          ...(data.replyToId
            ? {
                replyTo: {
                  data: {
                    type: "chat-messages",
                    id: String(data.replyToId),
                  },
                },
              }
            : {}),
          // Always sent, even empty: `hasMany` returns false for an absent
          // relationship, and the message row cannot distinguish "no
          // attachments" from "not loaded yet" without it.
          uploads: {
            data: uploads.map((upload) => ({
              type: "chat-uploads",
              id: String(upload.id),
            })),
          },
        },
      },
    } as any);
  } catch {
    return null;
  }
}

/**
 * Advances the channel's last-message pointer and its unread badge.
 *
 * The badge is incremented locally rather than re-fetched: the server has already
 * done the authoritative increment, and a request per incoming message would
 * defeat the point of using a websocket.
 */
function bumpChannel(data: MessagePayload): void {
  const channel = chatState.channel(data.channelId);

  // The channel is not in the loaded list — usually because the chat has never
  // been opened this session. The per-channel badge has nothing to attach to, but
  // the header count and the nav dot read the actor's own counters, and those
  // still have to move or the user is never told anything arrived.
  if (!channel) {
    chatState.bumpUnreadCounters(1, 0, true);

    // Traffic in a channel the list has never heard of means the list is behind
    // — an invite accepted elsewhere, or a direct message opened by someone
    // else. The list is otherwise fetched once per session and kept current by
    // these same events, so nothing would ever correct it; dropping the cache
    // makes the next read go to the server instead of returning a list that is
    // missing the channel the user was just told about.
    chatState.invalidateChannels();

    return;
  }

  const attrs: Record<string, unknown> = {
    lastMessageId: data.id,
    lastMessageAt: data.createdAt,
  };

  const isActive =
    chatState.activeChannelId === data.channelId && !chatState.drawerCollapsed;

  if (isActive) {
    // Reading it now — tell the server, do not badge.
    chatState.markRead(data.channelId);
  } else if (!channel.isMuted()) {
    const before = channel.unreadCount() ?? 0;

    attrs.unreadCount = before + 1;

    // `newChannel` only when this channel went from nothing-unread to something:
    // the channel counter counts channels, not messages.
    chatState.bumpUnreadCounters(1, 0, before === 0);
  }

  channel.pushAttributes(attrs);
}
