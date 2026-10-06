import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Message from "../../common/models/Message";
import type ChatState from "../state/ChatState";
export interface BookmarksListAttrs extends ComponentAttrs {
    state: ChatState;
}
/**
 * The messages the actor has bookmarked, across every channel.
 *
 * The bookmark button has been on every message row from the start, and the filter
 * behind this has been in the API just as long — but nothing ever listed them, so
 * bookmarking was a one-way action: you could save a message and then had no way to
 * find it again. This is the other half.
 *
 * Rows are summaries rather than full ChatMessage components on purpose. A message
 * row draws reply, edit and pin actions from its own capability flags, and those
 * only make sense next to the conversation they belong to; here the useful action
 * is "take me to it", so each row is a link to the message in its channel.
 */
export default class BookmarksList extends Component<BookmarksListAttrs> {
    private messages;
    private loading;
    private working;
    oninit(vnode: Mithril.Vnode<BookmarksListAttrs>): void;
    view(): Mithril.Children;
    protected row(message: Message): Mithril.Children;
    protected excerpt(message: Message): string;
    protected load(): Promise<void>;
    /**
     * Opens the channel the message lives in, and its thread when it has one — a
     * bookmarked thread reply is not in the channel window, so routing to the channel
     * alone would land somewhere the message is not.
     */
    protected open(message: Message): void;
    protected remove(message: Message): Promise<void>;
}
