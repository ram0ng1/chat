import Notification from "flarum/forum/components/Notification";
import type Mithril from "mithril";
/**
 * "X wants to transfer #channel to you", with the answer on the row.
 *
 * Mirrors ChannelInviteNotification: the offer is a question, so both answers
 * are right here, in the bell and in flarum/realtime's toast alike. The row
 * leaves the bell once the offer is answered or withdrawn anywhere, and the
 * channel's members tab shows the same offer with the same two buttons.
 */
export default class OwnershipTransferNotification extends Notification {
    private answered;
    private busy;
    icon(): string;
    href(): string;
    content(): Mithril.Children;
    excerpt(): Mithril.Children;
    onclick(e: MouseEvent): void;
    /**
     * The buttons sit inside the row's link, so the event is stopped here, or a
     * click on Decline would also open the channel.
     */
    protected answer(e: MouseEvent, accept: boolean): Promise<void>;
}
