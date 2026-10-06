import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Thread from "../../common/models/Thread";
import type ChatState from "../state/ChatState";
export interface ThreadsListAttrs extends ComponentAttrs {
    state: ChatState;
    /**
     * One channel's threads, every one of them, rather than the actor's own
     * across the chat. From the channel header and the drawer's menu.
     */
    channelId?: number | null;
    /** Drawn over the conversation in the drawer, with its own header. */
    embedded?: boolean;
    /** Dismisses the pane. Required when embedded; ignored otherwise. */
    onClose?: () => void;
}
/**
 * Threads: the actor's own across every channel, or all of one channel's.
 *
 * The counterpart to the thread panel: the panel reads one thread, this is how
 * you find it again afterwards. Selecting a row opens the panel over its own
 * channel: on the page by routing, in the drawer by setting the thread on the
 * state, since routing from the drawer closes it.
 */
export default class ThreadsList extends Component<ThreadsListAttrs> {
    private threads;
    private loading;
    oninit(vnode: Mithril.Vnode<ThreadsListAttrs>): void;
    onbeforeupdate(vnode: Mithril.VnodeDOM<ThreadsListAttrs, this>): void;
    view(): Mithril.Children;
    /**
     * A title, and in the drawer a way back to the conversation. The page's
     * sections carry their own title in the toolbar, so only the scoped list
     * names itself there.
     */
    protected header(): Mithril.Children;
    protected body(): Mithril.Children;
    protected row(thread: Thread): Mithril.Children;
    protected load(): Promise<void>;
    protected open(thread: Thread): void;
}
