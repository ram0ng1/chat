import app from "flarum/forum/app";
import Notification from "flarum/forum/components/Notification";
import type Mithril from "mithril";

import chatState from "../state/chat";
import ChatDrawer from "./ChatDrawer";
import { shouldUseChatDrawer } from "../utils/surface";

/**
 * "X added you to #channel."
 *
 * Clicking it opens the channel rather than navigating to a page about the
 * notification — being told you were added is only useful if the next click gets
 * you there.
 */
export default class ChannelInviteNotification extends Notification {
  icon(): string {
    return "fas fa-comments";
  }

  href(): string {
    const channelId = this.channelId();

    return channelId
      ? app.route("chat.channel", { id: channelId })
      : app.route("chat.index");
  }

  content(): Mithril.Children {
    const notification = this.attrs.notification;
    const inviter = notification.fromUser();

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
    // Marking a private channel as such is the whole context: it explains why the
    // channel appeared without you having found it yourself.
    const data = this.attrs.notification.content() as {
      isPrivate?: boolean;
    } | null;

    return data?.isPrivate
      ? app.translator.trans("ramon-chat.forum.new_channel.private")
      : null;
  }

  /**
   * Opens the drawer when that is the user's preference, instead of following the
   * href — the same choice the header button makes, so a notification and the
   * header do not disagree about where the chat lives.
   */
  onclick(e: MouseEvent): void {
    const channelId = this.channelId();

    if (!channelId) return;

    // Falling through to the href is the right behaviour when the drawer is not
    // the surface — including inside Flarum's own drawer, where the notification
    // list is a page and the chat should be one too.
    if (!shouldUseChatDrawer()) return;

    e.preventDefault();

    chatState.setActiveChannel(channelId);
    ChatDrawer.open();
  }

  protected channelId(): number | null {
    const data = this.attrs.notification.content() as {
      channelId?: number;
    } | null;

    return data?.channelId ? Number(data.channelId) : null;
  }

  /**
   * Read from the notification's stored data, not from the channel record: a
   * private channel is only loadable by its members, and the name was captured
   * when the notification was written for exactly that reason.
   */
  protected channelName(): string {
    const data = this.attrs.notification.content() as {
      channelName?: string;
    } | null;

    return (
      data?.channelName ??
      app.translator.trans("ramon-chat.forum.nav.chat", {}, true)
    );
  }
}
