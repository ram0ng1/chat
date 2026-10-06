import Notification from "flarum/forum/components/Notification";
import type Mithril from "mithril";
/**
 * "X made you a moderator of #channel."
 *
 * The stored data is ids only; the channel's name is read from the
 * notification's subject, which the notification list loads through the
 * channel's own visibility rules. The recipient is a member, so it is there.
 */
export default class ModeratorPromotedNotification extends Notification {
    icon(): string;
    href(): string;
    content(): Mithril.Children;
    excerpt(): Mithril.Children;
    onclick(e: MouseEvent): void;
}
/** The notification's channel id, from its data or its subject. */
export declare function channelIdOf(notification: Notification): number | null;
/** The channel's name as the reader sees it, or the chat's own title. */
export declare function channelNameOf(notification: Notification): string;
/** Who sent the notification, or "Someone". */
export declare function fromName(notification: Notification): string;
/**
 * Opens the drawer when that is the reader's preference instead of following
 * the href, the same choice the header button makes.
 */
export declare function openChannelFromNotification(notification: Notification, e: MouseEvent): void;
