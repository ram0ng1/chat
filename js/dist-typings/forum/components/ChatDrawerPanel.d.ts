import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
/**
 * The floating chat panel, pinned bottom-right over whatever page is open.
 *
 * Mounted once at the app root rather than per-page, so navigating the forum does
 * not tear down an open conversation — which is the entire point of a drawer.
 *
 * Below the mobile breakpoint it is not a panel at all. There is no room for one
 * over the page: it filled the viewport, covering the header, the phone toolbar
 * and its drawer toggle, so the forum underneath was unreachable until the chat
 * was closed. At that width the open drawer renders as a floating button
 * instead, and tapping it hands the conversation to the full-screen page — the
 * surface a phone has for the chat anyway (see `shouldUseChatDrawer`).
 *
 * Lives in the lazily loaded chat UI chunk; ChatDrawer is the eager shell that
 * mounts it once that chunk has arrived, and owns `open()`.
 */
export default class ChatDrawerPanel extends Component<ComponentAttrs> {
    /** Last known answer to `isNarrowViewport()`, so resize only redraws on a change. */
    private narrow;
    private onResize;
    /**
     * Nothing else watches the viewport, and the panel-versus-button choice is made
     * in `view()` rather than by a media query, so without this a rotation left the
     * old shape on screen until some other event happened to redraw.
     */
    /** Whether the drawer's box was on screen at the last render. */
    private shown;
    oncreate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void;
    onupdate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void;
    /**
     * The moment the drawer appears it grows out of whatever opened it — the
     * header button, or the full-screen page it is coming back from. See
     * utils/morph; without a recent origin this does nothing.
     */
    protected morphWhenShown(dom: Element | null): void;
    onremove(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void;
    view(): Mithril.Children;
    /**
     * Clicking the bar folds the drawer — unless the click was on something in it.
     *
     * The controls used to each call `stopPropagation` to opt out, which worked
     * only for as long as every one of them was a plain button. It stopped working
     * the moment the overflow menu arrived: Bootstrap opens a dropdown from a
     * handler delegated on `document`, so the very thing that kept the bar from
     * folding also kept the menu from opening.
     *
     * Asking what was clicked instead leaves the event free to reach the document,
     * which is what both opening the menu and closing it by clicking away depend
     * on. `.Dropdown` is listed alongside the elements because the menu's own
     * padding is part of it and is not a button.
     */
    protected onHeaderClick(e: Event): void;
    /**
     * The channel's actions, as a menu.
     *
     * The wrapper carries no click handler, and must not: Bootstrap opens a
     * dropdown from a handler delegated on `document` —
     * `on('click.bs.dropdown.data-api', '[data-toggle="dropdown"]', …)` — so a
     * `stopPropagation` anywhere between the toggle and the document swallows the
     * click that would have opened it. That is what left this menu inert. The
     * header guards itself instead; see `onHeaderClick`.
     */
    protected channelMenu(channel: Channel): Mithril.Children;
    /**
     * The drawer, at a width where a panel over the page would cover the page.
     *
     * A bubble in the corner, over the forum rather than instead of it: the header,
     * the phone toolbar and its drawer toggle all stay reachable, which is the whole
     * complaint against the full-bleed panel this replaces. Tapping it opens the
     * full-screen chat, so the button is a way *in* rather than a second chat.
     *
     * The dismiss cross is small but present, and deliberately so: `drawerOpen`
     * survives reloads, so without it a drawer opened once on a desktop would
     * follow the reader onto their phone with no way to send it away — the header
     * button only opens the chat.
     */
    protected floatingButton(): Mithril.Children;
    /**
     * The drawer's contents: the conversation, or the channel list, plus the pinned
     * panel over the top when it is open.
     *
     * Built as an array rather than a JSX fragment with a conditional slot. Mithril
     * decides a fragment is keyed from its first child and then demands every other
     * child be keyed too — and it counts `null` as unkeyed. `[<ChannelView key=…/>,
     * null]`, which is what a closed pinned panel produced, therefore threw "In
     * fragments, vnodes must either all have keys or none have keys" on every redraw.
     * The drawer is mounted at the app root, so that fired on every page of the forum,
     * not only inside the chat.
     */
    protected body(channel: Channel | null): Mithril.Children;
    protected select(channel: Channel): void;
    protected toggleCollapsed(): void;
    /**
     * The only way to close the drawer. Navigating the forum, reloading the page and
     * collapsing the header all leave it open — dismissal has to be deliberate.
     */
    protected close(): void;
    /**
     * Hands the current channel over to the full-screen page. The drawer closes so
     * the two never render the same conversation at once.
     *
     * Suspended rather than closed: the user did not dismiss the chat, they moved it,
     * so leaving the full-screen page puts the drawer back where it was. See
     * ChatPage.onremove, which is what restores it.
     */
    protected goFullScreen(): void;
}
