import Model from "flarum/common/Model";
import type User from "flarum/common/models/User";
import type Channel from "./Channel";
import type Message from "./Message";
/**
 * Per-thread tracking level. Mirrors Ramon\Chat\ThreadUser.
 */
export declare const enum ThreadTracking {
    Never = 0,
    Mentions = 1,
    Always = 2
}
export default class Thread extends Model {
    title: () => string | null;
    status: () => string;
    channelId: () => number;
    originalMessageId: () => number | null;
    repliesCount: () => number;
    lastMessageId: () => number | null;
    lastMessageAt: () => Date | null | undefined;
    createdAt: () => Date | null | undefined;
    notificationLevel: () => ThreadTracking;
    unreadCount: () => number;
    lastReadMessageId: () => number;
    isParticipating: () => boolean;
    canRename: () => boolean;
    canPostMessage: () => boolean;
    canClose: () => boolean;
    creator: () => false | User | null;
    channel: () => false | Channel | null;
    originalMessage: () => false | Message | null;
    lastMessage: () => false | Message | null;
    isOpen(): boolean;
    hasUnread(): boolean;
    /**
     * Falls back to an excerpt of the root message when the thread has no title,
     * which is how an untitled thread stays identifiable in "My Threads".
     */
    displayTitle(excerptLength?: number): string;
    apiEndpoint(): string;
}
