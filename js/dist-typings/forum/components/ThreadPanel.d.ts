import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
import type Message from "../../common/models/Message";
import type ChatState from "../state/ChatState";
export interface ThreadPanelAttrs extends ComponentAttrs {
    channel: Channel;
    threadId: number;
    state: ChatState;
    /** Invoked when the panel is dismissed, so the route can drop the thread. */
    onClose: () => void;
}
/**
 * A thread's replies, beside the channel.
 *
 * The channel stream shows a thread as one root message with a reply indicator —
 * the API's channel filter drops the replies — so this panel is the only place
 * they are readable. It is a narrower ChannelView: same rows, same composer, but
 * scoped to `filter[thread]` and without the unread divider, since a thread keeps
 * no read marker of its own.
 */
export default class ThreadPanel extends Component<ThreadPanelAttrs> {
    private scroller;
    private pinned;
    private lastRenderedCount;
    oninit(vnode: Mithril.Vnode<ThreadPanelAttrs>): void;
    onbeforeupdate(vnode: Mithril.VnodeDOM<ThreadPanelAttrs, this>): void;
    oncreate(vnode: Mithril.VnodeDOM<ThreadPanelAttrs>): void;
    onupdate(): void;
    view(): Mithril.Children;
    protected load(): void;
    protected onScroll(e: Event): void;
    /**
     * Reply and edit are staged against this panel's scope, so the channel composer
     * below is unaffected.
     */
    protected reply(message: Message): void;
    protected edit(message: Message): void;
    protected onSent(): void;
    protected scrollToBottom(): void;
}
