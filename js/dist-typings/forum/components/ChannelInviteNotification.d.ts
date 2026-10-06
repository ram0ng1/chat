import Notification from "flarum/forum/components/Notification";
import type Mithril from "mithril";
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
    private answered;
    private busy;
    icon(): string;
    /**
     * A private channel cannot be opened before the invitation is accepted, so
     * the row itself leads to the chat rather than to a 404.
     */
    href(): string;
    content(): Mithril.Children;
    excerpt(): Mithril.Children;
    /**
     * Opens the drawer when that is the user's preference, instead of following the
     * href — the same choice the header button makes, so a notification and the
     * header do not disagree about where the chat lives.
     */
    onclick(e: MouseEvent): void;
    /**
     * The buttons sit inside the row's own link, so the event is stopped here:
     * without it a click on "Decline" would also follow the row to the channel
     * it just declined.
     */
    protected answer(e: MouseEvent, accept: boolean): Promise<void>;
    /** Lands the new member in the channel, where they prefer to chat. */
    protected open(channelId: number): void;
    protected channelId(): number | null;
    /**
     * Read from the notification's stored data, not from the channel record: a
     * private channel is only loadable by its members, and the name was captured
     * when the notification was written for exactly that reason.
     */
    protected channelName(): string;
    protected data(): InviteData;
}
export {};
