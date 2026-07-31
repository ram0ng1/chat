import app from "flarum/forum/app";
import { extend } from "flarum/common/extend";
import HeaderSecondary from "flarum/forum/components/HeaderSecondary";
import UserControls from "flarum/forum/utils/UserControls";
import Button from "flarum/common/components/Button";
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
import ChatSelectionBar from "./components/ChatSelectionBar";
import ChatAutocomplete from "./components/ChatAutocomplete";
import RevisionsModal from "./components/RevisionsModal";
import FlagMessageModal from "./components/FlagMessageModal";
import FlaggedMessagesList from "./components/FlaggedMessagesList";
import { bindRealtime, setPollingFallback, realtimeBound } from "./realtime";
import { bindShortcuts } from "./utils/shortcuts";
import { shouldUseChatDrawer } from "./utils/surface";
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
  ChatSelectionBar,
  ChatAutocomplete,
  RevisionsModal,
  FlagMessageModal,
  FlaggedMessagesList,
  // Exported for diagnosis: in the console,
  //   flarum.reg.get('ramon-chat', 'forum/index').realtimeBound()
  // tells you whether the chat is on the websocket or on the polling fallback.
  realtimeBound,
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
      // bindRealtime() retries for a few seconds before conceding, so it is given
      // the poller to start itself rather than being asked for a verdict now.
      setPollingFallback(startPolling);

      if (!bindRealtime()) {
        startPolling();
      }

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

/**
 * Refreshes the channel list and the open channel's tail on an interval.
 *
 * Only runs while the document is visible: a backgrounded tab polling every 15s
 * for hours is exactly the behaviour that gets an extension blamed for load.
 */
function startPolling(): void {
  window.setInterval(() => {
    if (document.hidden) return;
    if (!chatState.channelsLoaded) return;

    chatState.loadChannels().catch(() => {});

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
  }, POLL_INTERVAL);
}
