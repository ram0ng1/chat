import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
import type Message from "../../common/models/Message";
import type ChatState from "../state/ChatState";
export interface PinnedPanelAttrs extends ComponentAttrs {
    channel: Channel;
    state: ChatState;
    onClose: () => void;
    /**
     * True inside the drawer, where panels stack over the conversation instead of
     * being addressed by URL. Same meaning as ChannelView's attr of the same name,
     * and needed for the same reason: routing from the drawer would throw it away
     * and reopen the conversation full-screen.
     */
    embedded?: boolean;
}
/**
 * A channel's pinned messages.
 *
 * Shares the right-hand panel geometry with the thread panel — the two are never
 * open at once. Pinning and unpinning happen on the message row itself, so there is
 * one place that does it rather than two that can disagree.
 */
export default class PinnedPanel extends Component<PinnedPanelAttrs> {
    private messages;
    private loading;
    oninit(vnode: Mithril.Vnode<PinnedPanelAttrs>): void;
    onbeforeupdate(vnode: Mithril.VnodeDOM<PinnedPanelAttrs, this>): void;
    view(): Mithril.Children;
    /**
     * Replying to a pinned message.
     *
     * The panel had no handlers, so ChatMessage drew the reply and edit buttons from
     * the message's own capability flags — the server said yes — and clicking them
     * did nothing at all. Worse than a missing button, because it looks like the
     * feature is broken rather than absent.
     *
     * The reply is staged against the channel, not the panel: the panel has no
     * composer of its own, so it closes and hands the context to the one below. That
     * is also why the target must carry its thread scope — replying to a pinned
     * message that lives inside a thread has to land in that thread, not in the
     * channel where the reply would make no sense.
     */
    protected reply(message: Message): void;
    protected edit(message: Message): void;
    /**
     * Closes the panel, opens the right composer, and applies the action to it.
     */
    protected stage(message: Message, apply: (channelId: number, threadId: number | null) => void): void;
    protected load(): Promise<void>;
}
