import app from "flarum/forum/app";
import Notification from "flarum/forum/components/Notification";
import type Mithril from "mithril";

import {
  channelIdOf,
  channelNameOf,
  fromName,
  openChannelFromNotification,
} from "./ModeratorPromotedNotification";

/**
 * "X declined to take over #channel." Sent to whoever offered the channel, so
 * a refusal is an answer rather than an offer that quietly stopped pending.
 */
export default class OwnershipTransferDeclinedNotification extends Notification {
  icon(): string {
    return "fas fa-user-xmark";
  }

  href(): string {
    const channelId = channelIdOf(this);

    return channelId
      ? app.route("chat.channel", { id: channelId })
      : app.route("chat.index");
  }

  content(): Mithril.Children {
    return app.translator.trans(
      "ramon-chat.forum.notifications.ownership_transfer_declined",
      {
        username: fromName(this),
        channel: channelNameOf(this),
      },
    );
  }

  excerpt(): Mithril.Children {
    return null;
  }

  onclick(e: MouseEvent): void {
    openChannelFromNotification(this, e);
  }
}
