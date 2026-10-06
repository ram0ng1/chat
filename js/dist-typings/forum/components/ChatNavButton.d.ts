import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
/**
 * The chat entry point in the header — Discourse's speech bubble next to search.
 *
 * The markup is a copy of core's `HeaderDropdown.getButtonContent()`, which is what
 * `flarum/messages` renders through `DialogsDropdown`: icon, then bubble, then
 * label, on a `.Button.Button--flat`. Matching it exactly is what makes the control
 * behave in both places it is drawn — as a round icon in the desktop header, and as
 * a named row inside the drawer on a phone — without a parallel set of rules.
 *
 * It is a plain `<button>` rather than a `HeaderDropdown` subclass because there is
 * no menu: the chat opens its own drawer or its page. Subclassing would drag in
 * `Dropdown-menu` markup and a toggle state that nothing here uses.
 */
export default class ChatNavButton<CustomAttrs extends ComponentAttrs = ComponentAttrs> extends Component<CustomAttrs> {
    view(): Mithril.Children;
    /**
     * Warms what opening the chat will ask for, without waiting for it.
     *
     * Drafts come along because the drawer loads both together, and the composer
     * that would otherwise appear empty and then fill in is the same flash the
     * channel list has. The UI chunk too: the drawer and the page both live in it,
     * so a hover is usually enough for the click to open without a spinner.
     */
    protected prefetch(): void;
    /**
     * Opens the drawer or the full-screen page, following the user's preference.
     *
     * Nothing closes Flarum's drawer on the way out: `ChatPage` extends core's
     * `Page`, whose `oninit` calls `app.drawer.hide()`. Doing it again here would
     * start the hide animation twice.
     */
    open(from?: HTMLElement): void;
    /**
     * Read through ChatState rather than off the user record directly.
     *
     * The serialised attribute is a snapshot from page render; the summary prefers
     * the loaded channel list when there is one and falls back to that attribute
     * otherwise — and realtime keeps the attribute moving. Reading the attribute
     * here meant the dot only ever appeared after a reload, which is precisely when
     * it is least useful.
     */
    protected unreadChannels(): number;
    protected unreadMentions(): number;
    /**
     * The bubble is aria-hidden, so this is the only thing a screen reader gets —
     * and it has to say which kind of unread it is, because the two carry different
     * urgency and the colour that distinguishes them is not announced.
     */
    protected ariaLabel(mentions: number, hasUnread: boolean, label: string): string;
}
