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
 * "X left #channel, so you are now its owner."
 *
 * Sent to the oldest moderator when a channel's owner leaves and it passes to
 * them (Service\OwnershipSuccession). Nobody asked them, so they need telling:
 * the room's settings, members and its fate are theirs now.
 */
export default class OwnershipInheritedNotification extends Notification {
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
      "ramon-chat.forum.notifications.ownership_inherited",
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
