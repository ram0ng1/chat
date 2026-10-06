import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Message from "../../common/models/Message";
import type ChatState from "../state/ChatState";
export interface ChatSearchAttrs extends ComponentAttrs {
    state: ChatState;
    /** When set, the search is scoped to one channel instead of all of them. */
    channelId?: number | null;
    /**
     * Rendered over a conversation rather than as the page's main pane — the
     * drawer, which has no route to reach `chat.search` with.
     *
     * Two things change: the pane grows a header with a way back out, and opening
     * a result switches the channel in place instead of routing to it.
     */
    embedded?: boolean;
    /** Dismisses the pane. Required when embedded; ignored otherwise. */
    onClose?: () => void;
}
/**
 * Message search, scoped to one channel or to everything the actor can read.
 *
 * Queries `filter[q]` on the message searcher, so results are already restricted
 * by the same visibility scope the stream uses — there is no separate permission
 * check to keep in step here.
 */
export default class ChatSearch extends Component<ChatSearchAttrs> {
    private query;
    private results;
    private searching;
    /** Whether a query has run, to tell "no results" apart from "nothing typed". */
    private searched;
    private timer;
    /** Guards against an earlier, slower request overwriting a later one. */
    private sequence;
    onremove(): void;
    view(): Mithril.Children;
    protected body(): Mithril.Children;
    protected result(message: Message): Mithril.Children;
    protected onInput(value: string): void;
    protected run(): Promise<void>;
    protected clear(): void;
    /**
     * Opens the result's channel.
     *
     * It does not jump to the message itself: the stream pages backwards from the
     * newest message, so landing on an old one would mean loading everything in
     * between. Better to open the conversation than to appear to hang.
     */
    protected open(message: Message): void;
}
