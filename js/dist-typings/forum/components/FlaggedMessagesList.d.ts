import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Message from "../../common/models/Message";
import type MessageFlag from "../../common/models/MessageFlag";
import type ChatState from "../state/ChatState";
export interface FlaggedMessagesListAttrs extends ComponentAttrs {
    state: ChatState;
}
/**
 * The chat's moderation queue.
 *
 * flarum/flags cannot back this: its `flags.post_id` is a non-nullable foreign key
 * into `posts`, so a chat message id is refused by the database. Rather than alter
 * another extension's schema, the chat keeps its own reports — same shape, same
 * two decisions at the end of each one: the message stays, or it goes.
 *
 * Rows are summaries, as in BookmarksList: a full ChatMessage draws reply, edit and
 * pin actions from its own capability flags, and none of those are what a moderator
 * came here to do.
 */
export default class FlaggedMessagesList extends Component<FlaggedMessagesListAttrs> {
    private flags;
    private loading;
    /** Id of the flag whose row is mid-request, so only that row shows a spinner. */
    private working;
    /** Whether resolved reports are shown alongside the open ones. */
    private showResolved;
    oninit(vnode: Mithril.Vnode<FlaggedMessagesListAttrs>): void;
    view(): Mithril.Children;
    protected body(): Mithril.Children;
    protected row(flag: MessageFlag): Mithril.Children;
    protected target(message: Message): Mithril.Children;
    protected load(): Promise<void>;
    /**
     * Opens the channel the reported message lives in, and its thread when it has
     * one — a thread reply is not in the channel window, so routing to the channel
     * alone would land somewhere the message is not.
     */
    protected open(message: Message): void;
    protected resolve(flag: MessageFlag): Promise<void>;
    /**
     * Deletes the reported message. The server closes every open report about it in
     * the same breath — see ResolveFlagsOnModeration — so this row is done too.
     */
    protected deleteMessage(flag: MessageFlag, message: Message): Promise<void>;
    /**
     * Drops the row from the open queue, or marks it in place when resolved reports
     * are on screen — removing it there would hide the very thing being shown.
     */
    protected afterResolved(flag: MessageFlag): void;
}
