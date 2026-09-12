import app from "flarum/forum/app";

import type Channel from "../../common/models/Channel";
import type ChatState from "../state/ChatState";
import ChannelFormModal from "../components/ChannelFormModal";
import ChannelInfoModal from "../components/ChannelInfoModal";
import {
  acceptInvitation,
  adoptChannelPayload,
  declineInvitation,
  invitationErrorText,
} from "./invitations";

/**
 * One thing the actor can do to a channel from its header.
 *
 * A description rather than a rendered control, because the two surfaces that
 * offer these draw them differently: the channel header has room for a row of
 * icon buttons, the drawer's single bar does not and puts them behind a `⋯`
 * menu, where each one needs a label. Returning descriptors is what lets both
 * render the same set without either of them owning it.
 */
export interface ChannelAction {
  key: string;
  icon: string;
  /** Already translated: it is a button title in one surface and its text in the other. */
  label: string;
  /** Drawn pressed — the pinned panel is a toggle, not a command. */
  active?: boolean;
  loading?: boolean;
  onclick: () => void;
}

/**
 * Channels with a join or leave request in flight, by id.
 *
 * Module-level because the request outlives the control that started it: the
 * drawer's menu closes on click, so a spinner held on the component would be
 * unmounted before the request landed. Keyed by channel rather than a single
 * flag so two channels acted on in quick succession do not disable each other.
 *
 * Only ever holds ids for the length of an HTTP request, so nothing actor-scoped
 * accumulates here.
 */
const inFlight = new Set<string>();

export interface ChannelActionOptions {
  /**
   * The surface has no route of its own — the drawer.
   *
   * An action that would navigate opens in place instead. Routing from the
   * drawer does not merely move the reader: ChatPage closes the drawer on
   * arrival, so "search in this channel" threw them out of the window they were
   * searching from and into full screen.
   */
  embedded?: boolean;
}

/**
 * Everything the actor may do to this channel, in the order both surfaces show
 * them.
 *
 * Every entry is gated on a server-computed flag, so a control is absent rather
 * than present-and-rejected — see ChannelPolicy.
 */
export function channelActions(
  channel: Channel,
  state: ChatState,
  options: ChannelActionOptions = {},
): ChannelAction[] {
  const trans = (key: string) =>
    app.translator.trans(`ramon-chat.forum.channel.${key}`, {}, true) as string;

  const busy = inFlight.has(String(channel.id()));
  const actions: ChannelAction[] = [];

  // The settings form, which needs the edit permission — unlike the info modal
  // behind the title, which every member can open.
  if (channel.canEdit() && channel.isCategory()) {
    actions.push({
      key: "edit",
      icon: "fas fa-pen-to-square",
      label: trans("edit"),
      onclick: () => app.modal.show(ChannelFormModal, { channel }),
    });
  }

  actions.push({
    key: "pinned",
    icon: "fas fa-thumbtack",
    label: trans("pinned_messages"),
    active: state.showPinned,
    onclick: () => {
      state.togglePinned();
      m.redraw();
    },
  });

  // The channel's threads, where the channel has them. On the page this is
  // the threads section scoped to the channel; in the drawer it is an overlay
  // like the pinned list, for the same reason search is one.
  if (channel.threadingEnabled()) {
    actions.push({
      key: "threads",
      icon: "fas fa-code-branch",
      label: trans("threads"),
      active: options.embedded ? state.showThreads : undefined,
      onclick: options.embedded
        ? () => {
            state.toggleThreads();
            m.redraw();
          }
        : () =>
            m.route.set(app.route("chat.threads", { channel: channel.id() })),
    });
  }

  actions.push({
    key: "search",
    icon: "fas fa-magnifying-glass",
    label: trans("search_in_channel"),
    // In the drawer it is a toggle over the conversation, so it is drawn pressed
    // while open — the same treatment the pinned list gets there.
    active: options.embedded ? state.showSearch : undefined,
    onclick: options.embedded
      ? () => {
          state.toggleSearch();
          m.redraw();
        }
      : () => m.route.set(app.route("chat.search", { channel: channel.id() })),
  });

  // Leaving is offered only for a channel you are actually in. A direct channel
  // keeps its history, so leaving one is not destructive.
  if (channel.isFollowing()) {
    actions.push({
      key: "leave",
      icon: "fas fa-arrow-right-from-bracket",
      label: trans("leave"),
      loading: busy,
      onclick: () => void leaveChannel(channel, state),
    });

    return actions;
  }

  // An open invitation. Accepting is joining, so it takes the join's place
  // rather than sitting beside it; declining is the other half of the answer.
  if (channel.isInvited()) {
    actions.push({
      key: "acceptInvite",
      icon: "fas fa-check",
      label: trans("accept_invite"),
      loading: busy,
      onclick: () => void answerInvitation(channel, state, true),
    });

    actions.push({
      key: "declineInvite",
      icon: "fas fa-xmark",
      label: trans("decline_invite"),
      loading: busy,
      onclick: () => void answerInvitation(channel, state, false),
    });

    return actions;
  }

  // Getting back into a channel you left. Two entries rather than one, because
  // they are different acts: an ordinary join puts you in the member list and the
  // count, a hidden one does not — which is what lets a moderator read a room
  // without their arrival changing how people talk in it. The hidden option is
  // drawn only when the server says the actor holds it.
  if (channel.canJoin()) {
    actions.push({
      key: "join",
      icon: "fas fa-arrow-right-to-bracket",
      label: trans("join"),
      loading: busy,
      onclick: () => void joinChannel(channel, state, false),
    });
  }

  if (channel.canJoinHidden()) {
    actions.push({
      key: "joinHidden",
      icon: "fas fa-user-secret",
      label: trans("join_hidden"),
      loading: busy,
      onclick: () => void joinChannel(channel, state, true),
    });
  }

  return actions;
}

/**
 * The channel's details — notification level, member list, and the state actions
 * the actor is allowed. Available to every member, unlike the settings form.
 */
export function openChannelInfo(channel: Channel): void {
  app.modal.show(ChannelInfoModal, { channel });
}

async function joinChannel(
  channel: Channel,
  state: ChatState,
  hidden: boolean,
): Promise<void> {
  const id = String(channel.id());

  if (inFlight.has(id)) return;

  inFlight.add(id);
  m.redraw();

  try {
    const payload = await app.request<any>({
      method: "POST",
      url: `${app.forum.attribute("apiUrl")}/chat-channels/${id}/join`,
      body: { data: { attributes: { hidden } } },
    });

    // The endpoint answers with the channel as the member now sees it, flags
    // included. Pushing it is what makes the composer appear at once: the
    // optimistic attributes this used to set left `canPostMessage` as it was
    // before the join, and the channel read as closed until a reload.
    if (!adoptChannelPayload(payload)) {
      channel.pushAttributes({
        isFollowing: true,
        isHiddenMember: hidden,
        canPostMessage: channel.isOpen(),
        // A hidden join is absent from the count, so it must not appear to move it.
        userCount: hidden
          ? channel.userCount()
          : (channel.userCount() ?? 0) + 1,
      });
    }

    state.rememberChannel(channel);

    if (hidden) {
      app.alerts.show(
        { type: "success" },
        app.translator.trans("ramon-chat.forum.channel.joined_hidden"),
      );
    }
  } catch (e: any) {
    app.alerts.show(
      { type: "error" },
      e?.response?.errors?.[0]?.detail ??
        app.translator.trans("ramon-chat.forum.channel.join_failed"),
    );
  } finally {
    inFlight.delete(id);
    m.redraw();
  }
}

/**
 * Answers an open invitation from the channel's own controls.
 *
 * Accepting lands the member in the channel they are already looking at, so
 * nothing navigates; the composer simply appears. Declining on a public channel
 * leaves the reader where they are, with the invitation gone from the record.
 */
async function answerInvitation(
  channel: Channel,
  state: ChatState,
  accept: boolean,
): Promise<void> {
  const id = String(channel.id());

  if (inFlight.has(id)) return;

  if (
    !accept &&
    !confirm(
      app.translator.trans(
        "ramon-chat.forum.channel.decline_invite_confirm",
        {},
        true,
      ),
    )
  ) {
    return;
  }

  inFlight.add(id);
  m.redraw();

  try {
    if (accept) {
      const joined = await acceptInvitation(Number(channel.id()));

      if (joined) state.rememberChannel(joined);

      app.alerts.show(
        { type: "success" },
        app.translator.trans("ramon-chat.forum.channel.invite_accepted"),
      );
    } else {
      await declineInvitation(Number(channel.id()));

      app.alerts.show(
        { type: "success" },
        app.translator.trans("ramon-chat.forum.channel.invite_declined"),
      );
    }
  } catch (e: any) {
    app.alerts.show(
      { type: "error" },
      invitationErrorText(e, "ramon-chat.forum.channel.invite_failed"),
    );
  } finally {
    inFlight.delete(id);
    m.redraw();
  }
}

/**
 * Leaves the channel. The membership row is retained server-side, so read state
 * and history survive rejoining — and for a direct channel, restarting the
 * conversation links back to the earlier messages.
 */
async function leaveChannel(channel: Channel, state: ChatState): Promise<void> {
  const id = String(channel.id());

  if (inFlight.has(id)) return;

  if (
    !confirm(
      app.translator.trans("ramon-chat.forum.channel.leave_confirm", {}, true),
    )
  ) {
    return;
  }

  inFlight.add(id);
  m.redraw();

  try {
    await app.request({
      method: "POST",
      url: `${app.forum.attribute("apiUrl")}/chat-channels/${id}/leave`,
    });

    channel.pushAttributes({
      isFollowing: false,
      unreadCount: 0,
      unreadMentionsCount: 0,
      userCount: Math.max(0, (channel.userCount() ?? 1) - 1),
    });

    // Drop it from the sidebar and step away from the now-unfollowed channel.
    state.channels = state.channels.filter((c) => c.id() !== channel.id());
    state.setActiveChannel(null);

    if ((m.route.get() ?? "").includes("/chat/c/")) {
      m.route.set(app.route("chat.index"));
    }
  } catch (e: any) {
    app.alerts.show(
      { type: "error" },
      e?.response?.errors?.[0]?.detail ??
        app.translator.trans("ramon-chat.forum.channel.leave_failed"),
    );
  } finally {
    inFlight.delete(id);
    m.redraw();
  }
}
