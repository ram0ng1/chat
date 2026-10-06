import Model from "flarum/common/Model";
import type User from "flarum/common/models/User";
import type Channel from "./Channel";
import type Thread from "./Thread";
import type Upload from "./Upload";
/**
 * A single reaction bucket, as sent by MessageResource::reactionSummary().
 * Pre-aggregated on the server so the client never holds every reaction row.
 */
export interface ReactionSummaryEntry {
    count: number;
    reacted: boolean;
}
export type ReactionSummary = Record<string, ReactionSummaryEntry>;
export default class Message extends Model {
    /**
     * Author-facing source text. Null on a deleted message the actor may not see.
     */
    content: () => string | null;
    contentHtml: () => string | null;
    type: () => string;
    systemKey: () => string | null;
    systemData: () => Record<string, unknown> | null;
    number: () => number | null;
    channelId: () => number;
    threadId: () => number | null;
    replyToId: () => number | null;
    createdAt: () => Date | null | undefined;
    editedAt: () => Date | null | undefined;
    deletedAt: () => Date | null | undefined;
    /**
     * Advanced by every change the row can undergo — an edit, a deletion, a pin,
     * a reaction. The polling fallback reconciles against it; see pollChanges()
     * in forum/index.tsx.
     */
    updatedAt: () => Date | null | undefined;
    isDeleted: () => boolean;
    /**
     * Whether someone other than the author removed it.
     *
     * Distinct from `isRedacted()`, which only says the text was withheld from
     * this reader — true for a self-deleted message too.
     */
    isModeratorDeleted: () => boolean;
    isEdited: () => boolean;
    isPinned: () => boolean;
    pinnedAt: () => Date | null | undefined;
    reactionSummary: () => ReactionSummary;
    mentionedUsers: () => number[];
    mentionsChannelWide: () => boolean;
    isBookmarked: () => boolean;
    /** Whether *this* reader has an open report against it. */
    isFlagged: () => boolean;
    /**
     * Open reports on this message.
     *
     * Withheld from anyone without `ramon-chat.moderate`, so it is undefined rather
     * than zero for ordinary readers — a visible count would tell everyone which
     * messages are being reported, and tell an author they had been.
     */
    flagsCount: () => number | undefined;
    canEdit: () => boolean;
    canDelete: () => boolean;
    canReact: () => boolean;
    canReply: () => boolean;
    canCreateThread: () => boolean;
    canMove: () => boolean;
    canPin: () => boolean;
    canFlag: () => boolean;
    /** Whether the row itself may be removed, tombstone and all. */
    canForceDelete: () => boolean;
    user: () => false | User | null;
    editedBy: () => false | User | null;
    deletedBy: () => false | User | null;
    replyTo: () => false | Message | null;
    thread: () => false | Thread | null;
    channel: () => false | Channel | null;
    uploads: () => false | (Upload | undefined)[];
    isSystem(): boolean;
    /**
     * Posted by the chat's bot rather than a person.
     *
     * Not a system message: it has real content and renders through the ordinary
     * message path. Only the author differs — there is no user record, so the name
     * and avatar come from the admin's settings.
     */
    isBot(): boolean;
    /**
     * True when the row exists but its text was withheld — a moderator-removed
     * message shown to someone who may not read it. The stream renders a
     * tombstone so it does not silently reflow.
     */
    isRedacted(): boolean;
    /**
     * Whether this message mentions the current user, either by name or through
     * @here / @all. Drives the mention highlight.
     */
    mentionsActor(): boolean;
    /**
     * Whether this message should render collapsed under the previous one: same
     * author, close in time, and neither is a system message.
     */
    isGroupedWith(previous: Message | null | undefined, withinSeconds?: number): boolean;
    totalReactions(): number;
    apiEndpoint(): string;
}
