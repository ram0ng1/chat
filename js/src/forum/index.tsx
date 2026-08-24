import app from "flarum/forum/app";
import { extend } from "flarum/common/extend";
import HeaderSecondary from "flarum/forum/components/HeaderSecondary";
import IndexSidebar from "flarum/forum/components/IndexSidebar";
import UserControls from "flarum/forum/utils/UserControls";
import Button from "flarum/common/components/Button";
import LinkButton from "flarum/common/components/LinkButton";
import type ItemList from "flarum/common/utils/ItemList";
import type User from "flarum/common/models/User";
import type Mithril from "mithril";

import Channel from "../common/models/Channel";
import Message from "../common/models/Message";
import Thread from "../common/models/Thread";
import Upload from "../common/models/Upload";
import MessageFlag from "../common/models/MessageFlag";

import chatState from "./state/chat";
import ChatState from "./state/ChatState";
import ChatNavButton from "./components/ChatNavButton";
import ChatDrawer from "./components/ChatDrawer";
import ChatPage from "./components/ChatPage";
import ChatSidebar from "./components/ChatSidebar";
import ChannelView from "./components/ChannelView";
import ThreadPanel from "./components/ThreadPanel";
import PinnedPanel from "./components/PinnedPanel";
import ThreadsList from "./components/ThreadsList";
import ChatSearch from "./components/ChatSearch";
import ChatMessage from "./components/ChatMessage";
import ChatComposer from "./components/ChatComposer";
import BrowseChannelsPage from "./components/BrowseChannelsPage";
import ChannelFormModal from "./components/ChannelFormModal";
import ChannelInviteNotification from "./components/ChannelInviteNotification";
import MessageFlaggedNotification from "./components/MessageFlaggedNotification";
import ChannelInfoModal from "./components/ChannelInfoModal";
import AddMembersModal from "./components/AddMembersModal";
import MessageTooLongModal from "./components/MessageTooLongModal";
import ChatSelectionBar from "./components/ChatSelectionBar";
import ChatAutocomplete from "./components/ChatAutocomplete";
import RevisionsModal from "./components/RevisionsModal";
import FlagMessageModal from "./components/FlagMessageModal";
import FlaggedMessagesList from "./components/FlaggedMessagesList";
import {
  bindRealtime,
  setPollingFallback,
  realtimeBound,
  realtimeDelivered,
} from "./realtime";
import { bindShortcuts } from "./utils/shortcuts";
import { shouldUseChatDrawer } from "./utils/surface";
import { chatTitle, chatIcon } from "./utils/branding";
import ChatPageResolver from "./resolvers/ChatPageResolver";
import bindFlagsIntegration from "./utils/flagsIntegration";

export {
  Channel,
  Message,
  Thread,
  Upload,
  MessageFlag,
  ChatState,
  chatState,
  ChatNavButton,
  ChatDrawer,
  ChatPage,
  ChatSidebar,
  ChannelView,
  ThreadPanel,
  PinnedPanel,
  ThreadsList,
  ChatSearch,
  ChatMessage,
  ChatComposer,
  BrowseChannelsPage,
  ChannelFormModal,
  ChannelInviteNotification,
  MessageFlaggedNotification,
  ChannelInfoModal,
  AddMembersModal,
  MessageTooLongModal,
  ChatSelectionBar,
  ChatAutocomplete,
  RevisionsModal,
  FlagMessageModal,
  FlaggedMessagesList,
  // Exported for diagnosis: in the console,
  //   flarum.reg.get('ramon-chat', 'forum/index').realtimeBound()
  // tells you whether the chat is on the websocket or on the polling fallback.
  realtimeBound,
  // And whether anything has ever arrived over it. `realtimeBound() === true`
  // with `realtimeDelivered() === false` after some traffic is the signature of a
  // forum whose PHP process cannot reach the websocket daemon.
  realtimeDelivered,
};

/**
 * Polling interval used when the websocket is unavailable.
 *
 * Was 15s, which is indistinguishable from "the chat is broken" — a message typed
 * in one window took up to fifteen seconds to appear in another. 3s is still a
 * fallback rather than a mode to optimise for, but it keeps a conversation usable
 * when realtime is not.
 *
 * The cost is bounded: the poll is skipped entirely while the tab is hidden, and
 * it only ever asks for messages newer than the newest one already held.
 */
const POLL_INTERVAL = 3000;

/**
 * Polling interval while the websocket is subscribed but has never delivered.
 *
 * Subscribing proves only that the *client* reached the daemon. Whether the
 * *server* can reach it is a separate question, and on a forum where it cannot,
 * the old code polled never: binding succeeded, so the fallback was never
 * started, and the chat updated only on reload. Polling slowly here bounds that
 * failure at a few seconds instead of forever.
 */
const POLL_INTERVAL_UNPROVEN = 15000;

/**
 * Polling interval once the socket has actually delivered something.
 *
 * At that point pushes demonstrably work end to end and this is a backstop for a
 * daemon that dies mid-session, so it is deliberately slow enough to be
 * negligible: one conditional request a minute per open chat tab.
 */
const POLL_INTERVAL_PROVEN = 60000;

app.initializers.add("ramon-chat", () => {
  // Register JSON:API types before anything can request them — the store drops
  // payloads for types it has no model for.
  app.store.models["chat-channels"] = Channel;
  app.store.models["chat-messages"] = Message;
  app.store.models["chat-threads"] = Thread;
  app.store.models["chat-uploads"] = Upload;
  app.store.models["chat-message-flags"] = MessageFlag;

  // ── Routes ────────────────────────────────────────────────────────────────
  // Names match the server-side declarations in extend.php. Without these the
  // URLs would serve the SPA and then render "not found".
  //
  // The ChatPage routes share one resolver whose key is constant, so moving
  // between them redraws instead of remounting — see ChatPageResolver. Without it
  // opening a thread or switching channel tore the page down and rebuilt it, which
  // reads as a full reload.
  const chatPage = { component: ChatPage, resolverClass: ChatPageResolver };

  app.routes["chat.index"] = { path: "/chat", ...chatPage };
  app.routes["chat.channel"] = { path: "/chat/c/:id", ...chatPage };
  app.routes["chat.thread"] = { path: "/chat/c/:id/t/:threadId", ...chatPage };
  app.routes["chat.threads"] = { path: "/chat/threads", ...chatPage };
  app.routes["chat.search"] = { path: "/chat/search", ...chatPage };
  app.routes["chat.bookmarks"] = { path: "/chat/bookmarks", ...chatPage };
  app.routes["chat.flags"] = { path: "/chat/flags", ...chatPage };

  // A genuinely separate page, so it keeps the default resolver.
  app.routes["chat.browse"] = {
    path: "/chat/browse",
    component: BrowseChannelsPage,
  };
  app.routes["chat.browse.filter"] = {
    path: "/chat/browse/:filter",
    component: BrowseChannelsPage,
  };

  // ── Notifications ─────────────────────────────────────────────────────────
  // The component that renders the alert, and the row in the user's notification
  // preferences that lets them turn it off.
  app.notificationComponents.chatChannelInvite = ChannelInviteNotification;
  app.notificationComponents.chatMessageFlagged = MessageFlaggedNotification;

  extend(
    "flarum/forum/components/NotificationGrid",
    "notificationTypes",
    function (items: ItemList<unknown>) {
      items.add("chatChannelInvite", {
        name: "chatChannelInvite",
        icon: "fas fa-comments",
        label: app.translator.trans(
          "ramon-chat.forum.settings.notify_channel_invite",
        ),
      });

      // Administrators only, matching who the server actually notifies. Offering
      // the row to anyone else advertises a notification they can never receive,
      // and a preference that does nothing is worse than an absent one.
      if (app.session.user?.isAdmin()) {
        items.add("chatMessageFlagged", {
          name: "chatMessageFlagged",
          icon: "fas fa-flag",
          label: app.translator.trans(
            "ramon-chat.forum.settings.notify_message_flagged",
          ),
        });
      }
    },
  );

  // ── Header trigger ────────────────────────────────────────────────────────
  extend(HeaderSecondary.prototype, "items", function (items) {
    if (!canUseChat()) return;

    items.add("chat", <ChatNavButton />, 15);
  });

  // ── Forum navigation ──────────────────────────────────────────────────────
  // The same list "All Discussions", flarum/tags' "Tags" and flarum/messages'
  // "Messages" live in: `IndexSidebar#navItems`. On a phone that list is not a
  // sidebar at all — core wraps it in the `SelectDropdown` it classes
  // `App-titleControl`, which is the menu in the toolbar beside the drawer
  // toggle. Adding the entry here is therefore the whole of "put the chat in the
  // mobile menu"; nothing needs to be positioned or mounted by hand.
  //
  // A `LinkButton` to `chat.index`, so it always opens the full-screen page.
  // Deliberately not routed through `shouldUseChatDrawer()` like the header
  // button: a navigation item that sometimes navigates and sometimes pops a
  // panel over the page you are on is two controls wearing one label.
  //
  // Priority 90 puts it under core's "All Discussions" (100) and under
  // flarum/messages' "Messages" (95), which is the order those two already agree
  // on: core's own list first, then the conversational surfaces.
  extend(IndexSidebar.prototype, "navItems", function (items) {
    if (!canUseChat()) return;

    items.add(
      "chat",
      <LinkButton href={app.route("chat.index")} icon={chatIcon() ?? undefined}>
        {chatTitle()}
      </LinkButton>,
      90,
    );
  });

  // ── "Chat" on a user's profile and controls dropdown ──────────────────────
  // Same entry point flarum/messages uses for "Send message", so the two sit
  // together rather than in unrelated places.
  //
  // Named rather than inline. `@ts-ignore` suppresses the *next line* only, and
  // the formatter wraps a long call across several — which moved the offending
  // argument out from under the suppression and turned a known typing gap into a
  // build error. A short statement cannot be re-wrapped.
  //
  // The gap itself is the one flarum/messages works around: extend() infers the
  // callback signature from the target method, and UserControls' published typing
  // loses the `user` parameter, even though it is passed at runtime.
  const addChatToUserControls = (
    items: ItemList<Mithril.Children>,
    user: User,
  ) => {
    if (!canUseChat()) return;
    if (!app.forum.attribute<boolean>("canStartChatDirect")) return;

    // No point offering a chat with yourself.
    if (app.session.user?.id() === user.id()) return;

    items.add(
      "chatDirect",
      <Button icon="fas fa-envelope" onclick={() => startDirectMessage(user)}>
        {app.translator.trans("ramon-chat.forum.user_controls.start_chat")}
      </Button>,
      // Just below flarum/messages' own button, which sits at the default 0.
      -5,
    );
  };

  // @ts-ignore
  extend(UserControls, "userControls", addChatToUserControls);

  // Anything that reads `app.forum`/`app.session`, or mounts its own Mithril
  // root, has to run after ForumApplication.mount():
  //
  //  - Application.boot() runs the initializers *before* it populates `forum`
  //    and `session`, so touching either at initializer time throws.
  //  - mount() is what calls m.route(). Core deliberately mounts its own
  //    secondary roots (navigation, header) *after* that call; mounting ahead of
  //    it attaches a redraw root to a router that does not exist yet.
  //
  // `app.beforeMount()` looks like the right hook but is not: runBeforeMount()
  // has no try/catch, so anything that throws there stops mount() from ever
  // running and the whole SPA silently fails to render.
  //
  // The target is the `app` *instance*, not ForumApplication.prototype. Core
  // registers `forum/ForumApplication` through `addChunkModule`, so the class
  // lives in a lazily loaded chunk and importing it at initializer time yields
  // `undefined` — reading `.prototype` off that is a TypeError. Extending the
  // instance installs an own property that shadows the prototype method, and
  // boot() calls `this.mount()`, so the override is picked up.
  extend(app, "mount", function () {
    // Belt and braces. Core does not guard this call site either, and a chat
    // extension must never be able to take the forum down with it — a broken
    // chat is an annoyance, an unmountable forum is an outage.
    try {
      if (!canUseChat()) return;

      // ── Live updates ──────────────────────────────────────────────────────
      // Both, always. The websocket carries the messages; the poller is a
      // backstop that rate-limits itself against what the socket has proven it
      // can do, and it is idempotent — it asks only for messages newer than the
      // newest one held, so a healthy socket costs it one request a minute.
      //
      // It used to start only when binding failed. That covered the case where
      // realtime is absent and missed the one that actually strands a forum: the
      // client subscribes fine while the server cannot reach the daemon, so
      // nothing is ever pushed and nothing ever notices.
      setPollingFallback(startPolling);
      bindRealtime();
      startPolling();

      // ── Drawer ────────────────────────────────────────────────────────────
      // Its own root outside the page tree, so navigating the forum never tears
      // down an open conversation. Renders nothing until opened.
      mountDrawer();

      bindShortcuts();

      // Chat reports in flarum/flags' own list, when that extension is present.
      bindFlagsIntegration();

      // Reopen it if the last visit left it open. Dismissal is deliberate: only
      // the close button (and switching to the full-screen page) clears this.
      if (chatState.restoreDrawer()) {
        Promise.all([chatState.loadChannels(), chatState.loadDrafts()])
          .catch(() => {})
          .then(() => m.redraw());
      }
    } catch (e) {
      console.error("[ramon-chat] failed to start:", e);
    }
  });
});

/**
 * Opens (or creates) a direct channel with a user and shows it.
 *
 * The endpoint reuses an existing conversation only while every participant is
 * still in it. Once someone leaves, a fresh start opens a new channel rather than
 * dragging them back into the history they walked away from.
 */
export async function startDirectMessage(user: User): Promise<void> {
  try {
    const payload = await app.request<{ data: { id: string } }>({
      method: "POST",
      url: `${app.forum.attribute("apiUrl")}/chat/direct`,
      body: { data: { attributes: { userIds: [Number(user.id())] } } },
    });

    const channelId = Number(payload.data?.id);

    if (!channelId) return;

    // Pull the channel into the store and the sidebar before showing it, so the
    // drawer does not open on an empty frame.
    try {
      const channel = (await app.store.find(
        "chat-channels",
        String(channelId),
      )) as unknown as Channel;

      if (channel && !chatState.channels.some((c) => c.id() === channel.id())) {
        chatState.channels.unshift(channel);
      }
    } catch {
      // The channel exists; a failed fetch just means the list refreshes later.
    }

    chatState.setActiveChannel(channelId);

    if (shouldUseChatDrawer()) {
      await ChatDrawer.open();
    } else {
      m.route.set(app.route("chat.channel", { id: channelId }));
    }
  } catch (e: any) {
    app.alerts.show(
      { type: "error" },
      e?.response?.errors?.[0]?.detail ??
        app.translator.trans(
          "ramon-chat.forum.user_controls.start_chat_failed",
        ),
    );
  }
}

function mountDrawer(): void {
  const attach = () => {
    if (document.getElementById("ramon-chat-drawer")) return;

    const node = document.createElement("div");
    node.id = "ramon-chat-drawer";
    document.body.appendChild(node);

    m.mount(node, ChatDrawer);
  };

  if (document.body) {
    attach();
  } else {
    document.addEventListener("DOMContentLoaded", attach, { once: true });
  }
}

/**
 * Whether to show the chat at all.
 *
 * An account is required. A guest read permission existed briefly and has been
 * withdrawn; the `/chat/*` routes now answer 404 for anyone without access, so
 * this and the server agree rather than one of them silently offering something
 * the other refuses.
 */
function canUseChat(): boolean {
  if (!app.forum.attribute<boolean>("canUseChat")) return false;
  if (!app.session.user) return false;

  // Honour the per-user opt-out from /settings.
  return app.session.user.preferences()?.["ramon-chat.enabled"] !== false;
}

/** Guards against a second loop when both callers ask for one. */
let polling = false;

/**
 * Refreshes the channel list and the open channel's tail on an interval.
 *
 * Runs unconditionally, at a rate set by how much the websocket has proven it can
 * do — see the three POLL_INTERVAL_* constants. It used to start only when
 * binding failed, which covered the wrong failure: a socket that binds and then
 * never carries anything looks identical to a healthy one from here, and the
 * chat simply stopped updating.
 *
 * Scheduled with a chained timeout rather than setInterval so the rate can change
 * mid-session — the first delivered event drops it from seconds to a minute.
 *
 * Only runs while the document is visible: a backgrounded tab polling every 15s
 * for hours is exactly the behaviour that gets an extension blamed for load.
 */
function startPolling(): void {
  if (polling) return;

  polling = true;

  const schedule = () => {
    window.setTimeout(() => {
      poll();
      schedule();
    }, pollInterval());
  };

  schedule();
}

/**
 * How long until the next poll. Read per tick, so a socket that starts or stops
 * delivering changes the rate without restarting anything.
 */
function pollInterval(): number {
  if (!realtimeBound()) return POLL_INTERVAL;

  return realtimeDelivered() ? POLL_INTERVAL_PROVEN : POLL_INTERVAL_UNPROVEN;
}

function poll(): void {
  if (document.hidden) return;
  if (!chatState.channelsLoaded) return;

  // Forced, because this is the one caller that genuinely wants a refetch: the
  // list is cached for the session precisely so navigation does not re-fetch it,
  // and the poller is the backstop that keeps it current when the websocket is
  // not delivering. A cached read here would poll for nothing.
  chatState.loadChannels(true).catch(() => {});

  const activeId = chatState.activeChannelId;

  if (activeId === null) return;

  const stream = chatState.streams[activeId];

  if (!stream || stream.loading) return;

  // Only what is newer than the newest known message.
  const newest = stream.messages[stream.messages.length - 1];

  app.store
    .find<Message[]>("chat-messages", {
      filter: {
        channel: activeId,
        ...(newest ? { greaterThan: Number(newest.id()) } : {}),
      },
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
    .catch(() => {});

  pollChanges(`channel:${activeId}`, { channel: activeId }, stream.messages);

  // The thread panel renders from its own stream, and nothing above reaches it:
  // the channel filter drops thread replies by design, so an open thread was
  // never polled at all. Its replies, and every reaction and edit inside it,
  // waited for a reload.
  const threadId = chatState.activeThreadId;

  if (threadId === null) return;

  const threadStream = chatState.threadStreams[threadId];

  if (!threadStream || threadStream.loading) return;

  const newestReply = threadStream.messages[threadStream.messages.length - 1];

  app.store
    .find<Message[]>("chat-messages", {
      filter: {
        thread: threadId,
        ...(newestReply ? { greaterThan: Number(newestReply.id()) } : {}),
      },
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
    .catch(() => {});

  pollChanges(
    `thread:${threadId}`,
    { thread: threadId },
    threadStream.messages,
  );
}

/**
 * How far each stream's change cursor has advanced, keyed by stream.
 *
 * Kept here rather than derived from the loaded window on every tick, because
 * the window is the wrong place to read it from: an edit to a message that has
 * scrolled out of the window never lands in it, so a cursor recomputed from the
 * window would sit still and re-request that same edit on every poll for the
 * rest of the session.
 */
const changeCursors = new Map<string, number>();

/**
 * Re-reads the messages of a stream that *changed* rather than arrived.
 *
 * The polls above only ever reach forward from the newest id, which is the right
 * shape for arrivals and cannot see anything else: a reaction, an edit, a
 * deletion or a pin lands on a row they have already gone past. On a forum
 * running the websocket that gap is covered by the push handlers in realtime.ts,
 * and on a forum without one — or with one the server cannot reach — nothing
 * covered it, so those changes appeared only after a reload.
 *
 * Nothing is inserted into the stream from the result. Flarum's store keeps one
 * record per id and updates it in place, and the stream holds those same
 * objects, so re-reading a row is enough to redraw it. Appending would be
 * actively wrong: a changed message from outside the loaded window would be
 * spliced into the middle of it as though the gap between them did not exist.
 */
function pollChanges(
  key: string,
  filter: Record<string, unknown>,
  messages: Message[],
): void {
  const since = changeCursors.get(key) ?? newestChange(messages);

  if (since === null) return;

  app.store
    .find<Message[]>("chat-messages", {
      filter: { ...filter, updatedSince: since },
      sort: "id",
      page: { limit: 50 },
    })
    .then((results) => {
      const advanced = newestChange(
        (Array.isArray(results) ? results : []) as Message[],
      );

      // Only ever forwards. A page truncated at the limit can come back with a
      // lower maximum than the cursor already holds, and moving backwards would
      // re-request the same rows indefinitely.
      if (advanced !== null && advanced > since) {
        changeCursors.set(key, advanced);
      } else {
        changeCursors.set(key, since);
      }

      m.redraw();
    })
    .catch(() => {});
}

/**
 * The most recent `updatedAt` across a set of messages, as a unix timestamp.
 *
 * A server-issued value rather than the browser's own clock: a client whose
 * clock runs fast would ask for changes from a moment that has not happened yet
 * and never see any, and the skew needed to break it is a few seconds.
 */
function newestChange(messages: Message[]): number | null {
  let newest = 0;

  for (const message of messages) {
    const at = message.updatedAt()?.getTime();

    if (at && at > newest) newest = at;
  }

  return newest > 0 ? Math.floor(newest / 1000) : null;
}
