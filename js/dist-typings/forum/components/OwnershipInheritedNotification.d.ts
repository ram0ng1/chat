import Notification from "flarum/forum/components/Notification";
import type Mithril from "mithril";
/**
 * "X left #channel, so you are now its owner."
 *
 * Sent to the oldest moderator when a channel's owner leaves and it passes to
 * them (Service\OwnershipSuccession). Nobody asked them, so they need telling:
 * the room's settings, members and its fate are theirs now.
 */
export default class OwnershipInheritedNotification extends Notification {
    icon(): string;
    href(): string;
    content(): Mithril.Children;
    excerpt(): Mithril.Children;
    onclick(e: MouseEvent): void;
}
