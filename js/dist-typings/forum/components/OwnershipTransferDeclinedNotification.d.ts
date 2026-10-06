import Notification from "flarum/forum/components/Notification";
import type Mithril from "mithril";
/**
 * "X declined to take over #channel." Sent to whoever offered the channel, so
 * a refusal is an answer rather than an offer that quietly stopped pending.
 */
export default class OwnershipTransferDeclinedNotification extends Notification {
    icon(): string;
    href(): string;
    content(): Mithril.Children;
    excerpt(): Mithril.Children;
    onclick(e: MouseEvent): void;
}
