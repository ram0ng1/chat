import app from 'flarum/forum/app';

import chatState from './state/chat';
import type Message from '../common/models/Message';

/**
 * Wire event names. Must match Ramon\Chat\Realtime\BroadcastListener.
 */
const EVENT_MESSAGE = 'ramonChat.message';
const EVENT_MESSAGE_CHANGED = 'ramonChat.messageChanged';
const EVENT_REACTION = 'ramonChat.reaction';
const EVENT_THREAD = 'ramonChat.thread';
const EVENT_CHANNEL = 'ramonChat.channel';
const EVENT_TYPING = 'ramonChat.typing';

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

interface MessagePayload {
  id: number;
  channelId: number;
  threadId: number | null;
  replyToId: number | null;
  number: number | null;
  userId: number | null;
  type: string;
  systemKey: string | null;
  contentHtml: string | null;
  createdAt: string | null;
  editedAt: string | null;
  isDeleted: boolean;
  isPinned?: boolean;
  pinnedAt?: string | null;
  uploads?: UploadPayload[];
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

  channel.bind(EVENT_MESSAGE, (data: MessagePayload) => onMessage(data));
  channel.bind(EVENT_MESSAGE_CHANGED, (data: MessagePayload) => onMessageChanged(data));
  channel.bind(EVENT_REACTION, (data: any) => onReaction(data));
  channel.bind(EVENT_THREAD, (data: any) => onThread(data));
  channel.bind(EVENT_CHANNEL, (data: any) => onChannel(data));
  channel.bind(EVENT_TYPING, (data: any) => onTyping(data));

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

  const channel = ws.subscribe('private-user=' + id);

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
  if (!('flarum-realtime' in (flarum.extensions ?? {}))) return false;

  bindTo(userChannel());

  if (bound) return true;

  // Realtime has not set up its client yet. Retry on a short schedule; the window
  // is generous because the cost of giving up is a 15× slower chat.
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
        console.warn('[ramon-chat] websocket unavailable; falling back to polling');
        startPollingFallback();
      }
    }
  }, 100);

  // Reported as bound: the retry either succeeds or starts polling itself, and
  // returning false here would start a second poller.
  return true;
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
  bumpChannel(data);
  bumpThread(data);

  m.redraw();
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

  const thread = app.store.getById('chat-threads', String(data.threadId));

  if (!thread) return;

  // Not counted for the root itself, which is the thread, not a reply to it.
  if (thread.attribute<number | null>('originalMessageId') === data.id) return;

  thread.pushAttributes({
    repliesCount: Number(thread.attribute<number>('repliesCount') ?? 0) + 1,
    lastMessageId: data.id,
  });
}

function onMessageChanged(data: MessagePayload): void {
  const existing = app.store.getById('chat-messages', String(data.id)) as Message | undefined;

  // Only reconcile messages already on this client. A change to something never
  // loaded is not worth materialising.
  if (!existing) return;

  existing.pushAttributes({
    contentHtml: data.contentHtml,
    editedAt: data.editedAt,
    isDeleted: data.isDeleted,
    isEdited: Boolean(data.editedAt),
    // Carried on the same event as edits and deletions: a pin changes what
    // everyone in the channel sees first, so it has to land without a refresh.
    isPinned: Boolean(data.isPinned),
    pinnedAt: data.pinnedAt ?? null,
    ...(data.isDeleted ? { content: null } : {}),
  });

  m.redraw();
}

function onReaction(data: { messageId: number; emoji: string; userId: number; added: boolean }): void {
  const message = app.store.getById('chat-messages', String(data.messageId)) as Message | undefined;

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

function onThread(data: { threadId: number; channelId: number; repliesCount: number; title: string | null }): void {
  const thread = app.store.getById('chat-threads', String(data.threadId));

  if (thread) {
    thread.pushAttributes({ repliesCount: data.repliesCount, title: data.title });
    m.redraw();
  }
}

function onChannel(data: { channelId: number; status: string }): void {
  const channel = chatState.channel(data.channelId);

  if (!channel) return;

  channel.pushAttributes({ status: data.status });
  m.redraw();
}

function onTyping(data: { channelId: number; userId: number; username: string; typing: boolean; expiresIn: number }): void {
  chatState.noteTyping(data.channelId, data.userId, data.username, data.typing, data.expiresIn);
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

  try {
    return app.store.pushPayload<Message>({
      included: uploads.map((upload) => ({
        type: 'chat-uploads',
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
        relationships: data.userId ? { user: { data: { type: 'users', id: String(data.userId) } } } : {},
      })),
      data: {
        type: 'chat-messages',
        id: String(data.id),
        attributes: {
          channelId: data.channelId,
          threadId: data.threadId,
          replyToId: data.replyToId,
          number: data.number,
          type: data.type,
          systemKey: data.systemKey,
          contentHtml: data.contentHtml,
          createdAt: data.createdAt,
          editedAt: data.editedAt,
          isDeleted: data.isDeleted,
          isEdited: Boolean(data.editedAt),
          isPinned: Boolean(data.isPinned),
          pinnedAt: data.pinnedAt ?? null,
          reactionSummary: {},
          mentionedUsers: [],
          mentionsChannelWide: false,
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
          ...(data.userId ? { user: { data: { type: 'users', id: String(data.userId) } } } : {}),
          // Always sent, even empty: `hasMany` returns false for an absent
          // relationship, and the message row cannot distinguish "no
          // attachments" from "not loaded yet" without it.
          uploads: { data: uploads.map((upload) => ({ type: 'chat-uploads', id: String(upload.id) })) },
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

  if (!channel) return;

  const attrs: Record<string, unknown> = {
    lastMessageId: data.id,
    lastMessageAt: data.createdAt,
  };

  const isActive = chatState.activeChannelId === data.channelId && !chatState.drawerCollapsed;

  if (isActive) {
    // Reading it now — tell the server, do not badge.
    chatState.markRead(data.channelId);
  } else if (!channel.isMuted()) {
    attrs.unreadCount = (channel.unreadCount() ?? 0) + 1;
  }

  channel.pushAttributes(attrs);
}
