import Notification from "flarum/forum/components/Notification";
import type Mithril from "mithril";
interface FlaggedData {
    flagId?: number;
    messageId?: number;
    channelId?: number;
    channelName?: string;
    reason?: string;
}
/**
 * "X reported a message in #channel."
 *
 * Sent to moderators, so a report reaches them the way a flag on a post does
 * instead of waiting to be found the next time someone opens the queue.
 *
 * Clicking goes to the queue rather than to the message: the decision to be made
 * is about the report, and the queue is where both the reporter's words and the
 * two actions are.
 */
export default class MessageFlaggedNotification extends Notification {
    icon(): string;
    href(): string;
    content(): Mithril.Children;
    /**
     * The reason, not the reported text. The notification's stored data is returned
     * verbatim with no policy re-check, so a message hidden after the report would
     * still be readable here — see MessageFlaggedBlueprint.
     */
    excerpt(): Mithril.Children;
    protected data(): FlaggedData;
}
export {};
