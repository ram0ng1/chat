import app from "flarum/forum/app";
import Notification from "flarum/forum/components/Notification";
import Button from "flarum/common/components/Button";
import type Mithril from "mithril";

import {
  channelIdOf,
  channelNameOf,
  fromName,
  openChannelFromNotification,
} from "./ModeratorPromotedNotification";
import { acceptTransfer, declineTransfer } from "../utils/transfers";
import { invitationErrorText } from "../utils/invitations";

/**
 * "X wants to transfer #channel to you", with the answer on the row.
 *
 * Mirrors ChannelInviteNotification: the offer is a question, so both answers
 * are right here, in the bell and in flarum/realtime's toast alike. The row
 * leaves the bell once the offer is answered or withdrawn anywhere, and the
 * channel's members tab shows the same offer with the same two buttons.
 */
export default class OwnershipTransferNotification extends Notification {
  private answered: "accepted" | "declined" | null = null;

  private busy: "accept" | "decline" | null = null;

  icon(): string {
    return "fas fa-crown";
  }

  href(): string {
    const channelId = channelIdOf(this);

    return channelId
      ? app.route("chat.channel", { id: channelId })
      : app.route("chat.index");
  }

  content(): Mithril.Children {
    return app.translator.trans(
      "ramon-chat.forum.notifications.ownership_transfer",
      {
        username: fromName(this),
        channel: channelNameOf(this),
      },
    );
  }

  excerpt(): Mithril.Children {
    if (this.answered) {
      return app.translator.trans(
        this.answered === "accepted"
          ? "ramon-chat.forum.info.transfer_accepted"
          : "ramon-chat.forum.info.transfer_declined",
      );
    }

    return (
      <span className="ChatInviteActions">
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

  onclick(e: MouseEvent): void {
    openChannelFromNotification(this, e);
  }

  /**
   * The buttons sit inside the row's link, so the event is stopped here, or a
   * click on Decline would also open the channel.
   */
  protected async answer(e: MouseEvent, accept: boolean): Promise<void> {
    e.preventDefault();
    e.stopPropagation();

    const channelId = channelIdOf(this);

    if (!channelId || this.busy) return;

    this.busy = accept ? "accept" : "decline";
    m.redraw();

    try {
      if (accept) {
        await acceptTransfer(channelId);
        this.answered = "accepted";
      } else {
        await declineTransfer(channelId);
        this.answered = "declined";
      }

      this.markAsRead();
    } catch (error: any) {
      app.alerts.show(
        { type: "error" },
        invitationErrorText(error, "ramon-chat.forum.info.transfer_failed"),
      );
    } finally {
      this.busy = null;
      m.redraw();
    }
  }
}
