import app from "flarum/forum/app";
import Notification from "flarum/forum/components/Notification";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import chatState from "../state/chat";
import ChatDrawer from "./ChatDrawer";
import { shouldUseChatDrawer } from "../utils/surface";

interface PromotedData {
  channelId?: number;
}

/**
 * "X made you a moderator of #channel."
 *
 * The stored data is ids only; the channel's name is read from the
 * notification's subject, which the notification list loads through the
 * channel's own visibility rules. The recipient is a member, so it is there.
 */
export default class ModeratorPromotedNotification extends Notification {
  icon(): string {
    return "fas fa-user-shield";
  }

  href(): string {
    const channelId = channelIdOf(this);

    return channelId
      ? app.route("chat.channel", { id: channelId })
      : app.route("chat.index");
  }

  content(): Mithril.Children {
    return app.translator.trans(
      "ramon-chat.forum.notifications.moderator_promoted",
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

/** The notification's channel id, from its data or its subject. */
export function channelIdOf(notification: Notification): number | null {
  const data =
    (notification.attrs.notification.content() as PromotedData | null) ?? {};
  const subject = notification.attrs.notification.subject() as Channel | null;
  const id = data.channelId ?? (subject ? Number(subject.id()) : null);

  return id ? Number(id) : null;
}

/** The channel's name as the reader sees it, or the chat's own title. */
export function channelNameOf(notification: Notification): string {
  const subject = notification.attrs.notification.subject() as Channel | null;

  return (
    subject?.displayName?.() ||
    (app.translator.trans("ramon-chat.forum.nav.chat", {}, true) as string)
  );
}

/** Who sent the notification, or "Someone". */
export function fromName(notification: Notification): string {
  const user = notification.attrs.notification.fromUser();

  return user
    ? user.displayName()
    : (app.translator.trans(
        "ramon-chat.forum.notifications.someone",
        {},
        true,
      ) as string);
}

/**
 * Opens the drawer when that is the reader's preference instead of following
 * the href, the same choice the header button makes.
 */
export function openChannelFromNotification(
  notification: Notification,
  e: MouseEvent,
): void {
  const channelId = channelIdOf(notification);

  if (!channelId || !shouldUseChatDrawer()) return;

  e.preventDefault();

  chatState.setActiveChannel(channelId);
  ChatDrawer.open();
}
