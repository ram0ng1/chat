import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
import type Message from "../../common/models/Message";
import type ChatState from "../state/ChatState";
export interface ChannelViewAttrs extends ComponentAttrs {
    channel: Channel;
    state: ChatState;
    /** Rendered in the header; lets the drawer show a back button the page doesn't need. */
    onBack?: () => void;
    /**
     * The view is inside a container that shows the thread panel itself — the
     * drawer. Opening a thread then sets the state and stops, instead of routing
     * away to the full-screen page and closing the drawer to do it.
     */
    embedded?: boolean;
}
/**
 * A channel's header, message stream and composer.
 *
 * The stream is bottom-anchored: it stays pinned to the newest message while the
 * user is at the bottom, and holds its scroll position when they are not. Getting
 * this wrong is the single most noticeable chat bug, so the anchoring logic is
 * kept explicit rather than left to the browser.
 */
export default class ChannelView extends Component<ChannelViewAttrs> {
    private scroller;
    /** Whether the user is at (or near) the bottom of the stream. */
    private pinned;
    /** Scroll height before a prepend, used to restore position after paging up. */
    private heightBeforePrepend;
    private lastRenderedCount;
    oninit(vnode: Mithril.Vnode<ChannelViewAttrs>): void;
    onbeforeupdate(vnode: Mithril.VnodeDOM<ChannelViewAttrs, this>): void;
    oncreate(vnode: Mithril.VnodeDOM<ChannelViewAttrs>): void;
    onupdate(vnode: Mithril.VnodeDOM<ChannelViewAttrs>): void;
    view(): Mithril.Children;
    /**
     * The pinned message, as a strip under the header — the WhatsApp arrangement.
     *
     * Shown in the drawer as well as the full-screen page: the drawer has no room for
     * the pinned panel, and a pin nobody can see is pointless. Clicking it jumps to
     * the message, first loading the history between it and the window when it is
     * older than what is on screen — a pin far up a busy channel used to be a dead
     * label. A pin the channel stream cannot carry (a thread reply) opens the
     * pinned panel instead.
     */
    protected pinnedBar(): Mithril.Children;
    protected jumpToPinned(pinned: Message): void;
    /**
     * The channel's own bar: mark, name, description, actions.
     *
     * Absent when embedded. The drawer is 320px of vertical space and drew two
     * bars stacked, both naming the same channel — its own, for the window
     * controls, and this one. It now carries both, so this would be the second
     * copy rather than the only one.
     */
    protected header(): Mithril.Children;
    /**
     * Interleaves date separators and the unread divider with the message rows.
     */
    protected rows(messages: Message[], dividerAfterId: number | null): Mithril.Children;
    protected dateLabel(date: Date): string;
    protected typingIndicator(): Mithril.Children;
    protected skeleton(): Mithril.Children;
    protected load(): Promise<void>;
    protected onScroll(e: Event): void;
    /**
     * The way back down, for when the stream has been left somewhere above the
     * newest message.
     *
     * Jumping to a pinned message is the case that makes this necessary: it can
     * land you hours up the conversation with no affordance but a long scroll, and
     * on a phone that is a lot of dragging. Same control WhatsApp draws in the same
     * corner, for the same reason.
     *
     * Tied to `pinned` rather than to a scroll-offset threshold of its own —
     * `pinned` is already what decides whether the stream follows new messages, so
     * the button is visible exactly when it is not following, and never lingers
     * over a stream that is already at the bottom.
     */
    protected scrollDownButton(): Mithril.Children;
    /**
     * Jumps the stream to the newest message.
     *
     * Instant, including for the button — an animated version of this does not
     * survive its surroundings. `onupdate` re-anchors to the bottom the moment a
     * message arrives while pinned, which overrides an animation mid-flight; and a
     * smooth `scrollTo` resolves its target once, so rows still laying out below
     * (an image finishing, the reconcile filling a row in) leave it settling short
     * of the bottom — on whichever row happened to be there.
     */
    protected scrollToBottom(): void;
    protected onSent(): void;
    protected reply(message: Message): void;
    protected edit(message: Message): void;
    protected openThread(message: Message): void;
}
