import app from "flarum/forum/app";
import Notification from "flarum/forum/components/Notification";
import Button from "flarum/common/components/Button";
import type Mithril from "mithril";

import chatState from "../state/chat";
import ChatDrawer from "./ChatDrawer";
import { shouldUseChatDrawer } from "../utils/surface";
import {
  acceptInvitation,
  declineInvitation,
  invitationErrorText,
} from "../utils/invitations";

interface InviteData {
  channelId?: number;
  channelName?: string;
  isPrivate?: boolean;
}

/**
 * "X invited you to #channel", with the answer on the row.
 *
 * The two buttons are the point of the notification: being asked into a channel
 * is a question, and a row that only linked somewhere would leave the invitee
 * to find the answer elsewhere. For a private channel there is nowhere else to
 * find it, since the channel is not visible until the invitation is accepted.
 *
 * Accepting opens the channel where the reader prefers to chat, drawer or page.
 * The row is drawn the same way in the bell and in flarum/realtime's toast, so
 * the invitation can be answered the moment it arrives.
 */
export default class ChannelInviteNotification extends Notification {
  /** What this row did, once it did something; the buttons give way to it. */
  private answered: "accepted" | "declined" | null = null;

  private busy: "accept" | "decline" | null = null;

  icon(): string {
    return "fas fa-envelope-open";
  }

  /**
   * A private channel cannot be opened before the invitation is accepted, so
   * the row itself leads to the chat rather than to a 404.
   */
  href(): string {
    const channelId = this.channelId();

    return channelId && (!this.data().isPrivate || this.answered === "accepted")
      ? app.route("chat.channel", { id: channelId })
      : app.route("chat.index");
  }

  content(): Mithril.Children {
    const inviter = this.attrs.notification.fromUser();

    return app.translator.trans(
      "ramon-chat.forum.notifications.channel_invite",
      {
        username: inviter
          ? inviter.displayName()
          : app.translator.trans(
              "ramon-chat.forum.notifications.someone",
              {},
              true,
            ),
        channel: this.channelName(),
      },
    );
  }

  excerpt(): Mithril.Children {
    if (this.answered) {
      return app.translator.trans(
        this.answered === "accepted"
          ? "ramon-chat.forum.channel.invite_accepted"
          : "ramon-chat.forum.channel.invite_declined",
      );
    }

    // A row whose channel is already in the sidebar has been answered from
    // somewhere else, or on another tab; offering the buttons again would send
    // a second accept the server refuses.
    const channelId = this.channelId();
    const known = channelId ? chatState.channel(channelId) : null;

    if (known?.isFollowing()) {
      return app.translator.trans("ramon-chat.forum.channel.invite_accepted");
    }

    return (
      <span className="ChatInviteActions">
        {this.data().isPrivate ? (
          <span className="ChatInviteActions-hint">
            <i className="fas fa-lock" aria-hidden="true" />
            {app.translator.trans("ramon-chat.forum.new_channel.private")}
          </span>
        ) : null}

        <Button
          className="Button Button--primary Button--compact"
          icon="fas fa-check"
          loading={this.busy === "accept"}
          disabled={this.busy !== null}
          onclick={(e: MouseEvent) => this.answer(e, true)}
        >
          {app.translator.trans("ramon-chat.forum.notifications.invite_accept")}
        </Button>

        <Button
          className="Button Button--compact"
          icon="fas fa-xmark"
          loading={this.busy === "decline"}
          disabled={this.busy !== null}
          onclick={(e: MouseEvent) => this.answer(e, false)}
        >
          {app.translator.trans(
            "ramon-chat.forum.notifications.invite_decline",
          )}
        </Button>
      </span>
    );
  }

  /**
   * Opens the drawer when that is the user's preference, instead of following the
   * href — the same choice the header button makes, so a notification and the
   * header do not disagree about where the chat lives.
   */
  onclick(e: MouseEvent): void {
    const channelId = this.channelId();

    if (!channelId) return;

    if (this.data().isPrivate && this.answered !== "accepted") return;

    // Falling through to the href is the right behaviour when the drawer is not
    // the surface — including inside Flarum's own drawer, where the notification
    // list is a page and the chat should be one too.
    if (!shouldUseChatDrawer()) return;

    e.preventDefault();

    chatState.setActiveChannel(channelId);
    ChatDrawer.open();
  }

  /**
   * The buttons sit inside the row's own link, so the event is stopped here:
   * without it a click on "Decline" would also follow the row to the channel
   * it just declined.
   */
  protected async answer(e: MouseEvent, accept: boolean): Promise<void> {
    e.preventDefault();
    e.stopPropagation();

    const channelId = this.channelId();

    if (!channelId || this.busy) return;

    this.busy = accept ? "accept" : "decline";
    m.redraw();

    try {
      if (accept) {
        await acceptInvitation(channelId);
        this.answered = "accepted";
      } else {
        await declineInvitation(channelId);
        this.answered = "declined";
      }

      this.markAsRead();
    } catch (error: any) {
      // A 404 is an invitation that is no longer open: answered on another
      // tab, or withdrawn. Either way there is nothing left to press.
      if (error?.status === 404) {
        this.answered = "declined";
      }

      app.alerts.show(
        { type: "error" },
        invitationErrorText(error, "ramon-chat.forum.channel.invite_failed"),
      );
    } finally {
      this.busy = null;
      m.redraw();
    }

    if (this.answered === "accepted") this.open(channelId);
  }

  /** Lands the new member in the channel, where they prefer to chat. */
  protected open(channelId: number): void {
    chatState.setActiveChannel(channelId);

    if (shouldUseChatDrawer()) {
      ChatDrawer.open();

      return;
    }

    m.route.set(app.route("chat.channel", { id: channelId }));
  }

  protected channelId(): number | null {
    const id = this.data().channelId;

    return id ? Number(id) : null;
  }

  /**
   * Read from the notification's stored data, not from the channel record: a
   * private channel is only loadable by its members, and the name was captured
   * when the notification was written for exactly that reason.
   */
  protected channelName(): string {
    return (
      this.data().channelName ??
      (app.translator.trans("ramon-chat.forum.nav.chat", {}, true) as string)
    );
  }

  protected data(): InviteData {
    return (this.attrs.notification.content() as InviteData | null) ?? {};
  }
}
