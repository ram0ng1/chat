import Notification from "flarum/forum/components/Notification";
import type Mithril from "mithril";
interface DeclinedData {
    channelId?: number;
    channelName?: string;
    isPrivate?: boolean;
}
/**
 * "X declined your invitation to #channel."
 *
 * Sent to the channel's owner and to whoever invited, so a refusal is an answer
 * rather than an invitation that quietly stopped being pending. Clicking opens
 * the channel: the next thing to do about it, if anything, is invite somebody
 * else from its members tab.
 */
export default class ChannelInviteDeclinedNotification extends Notification {
    icon(): string;
    href(): string;
    content(): Mithril.Children;
    excerpt(): Mithril.Children;
    protected data(): DeclinedData;
}
export {};
