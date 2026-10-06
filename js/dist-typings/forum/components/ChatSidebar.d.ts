import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
import type ChatState from "../state/ChatState";
export interface ChatSidebarAttrs extends ComponentAttrs {
    state: ChatState;
    onSelect?: (channel: Channel) => void;
}
/** An icon button in a section header. */
interface SectionAction {
    icon: string;
    title: string;
    action: () => void;
}
export default class ChatSidebar extends Component<ChatSidebarAttrs> {
    /** Pending hover-intent timer; see the pointer handlers on a channel row. */
    private prefetchTimer;
    oncreate(vnode: Mithril.VnodeDOM<ChatSidebarAttrs, this>): void;
    onupdate(vnode: Mithril.VnodeDOM<ChatSidebarAttrs, this>): void;
    onremove(vnode: Mithril.VnodeDOM<ChatSidebarAttrs, this>): void;
    /**
     * Loads the conversations someone is most likely to open next — the unread
     * ones, mentions first — while the browser is idle, so the click lands on
     * messages already drawn. Only while the websocket is live: that is what keeps
     * a stream loaded in the background current until it is opened.
     */
    protected warmUnread(): void;
    view(): Mithril.Children;
    /**
     * One of the views above the channel list.
     *
     * Written once rather than three times, which is what makes the active state
     * affordable: three hand-rolled buttons had no way to say which view was open,
     * so the sidebar looked identical whether you were reading a channel, your
     * threads or your bookmarks.
     */
    protected quickLink(routeName: string, icon: string, key: string, badge?: number): Mithril.Children;
    /**
     * A labelled group of channels, with any affordances that belong to it.
     *
     * Takes a list rather than a single action because the channels header now
     * carries two — browse and create — and they are not alternatives: the create
     * button used to be rendered only in the empty state below, so it vanished the
     * moment you joined your first channel and the only remaining route to it was
     * through Browse.
     */
    protected section(labelKey: string, channels: Channel[], actions: SectionAction[]): Mithril.Children;
    protected row(channel: Channel): Mithril.Children;
    /**
     * Starts warming a channel once the pointer has settled on its row.
     *
     * Replaces any pending one: only the row currently under the pointer is worth
     * fetching, and the previous timer belongs to a row already left behind.
     */
    protected schedulePrefetch(channel: Channel): void;
    protected cancelPrefetch(): void;
    protected createChannel(): void;
}
export {};
