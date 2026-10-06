import Page from "flarum/common/components/Page";
import type { IPageAttrs } from "flarum/common/components/Page";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
/**
 * Full-screen chat.
 *
 * Sidebar plus channel side by side on desktop; on mobile the router shows one or
 * the other, since a 280px sidebar beside a channel is unusable at phone widths.
 */
export default class ChatPage<CustomAttrs extends IPageAttrs = IPageAttrs> extends Page<CustomAttrs> {
    /**
     * Whether the page has nothing to draw yet.
     *
     * Seeded from the state rather than hardcoded to `true`. The channel list is
     * fetched once per session and kept current by realtime, so on every visit
     * after the first the data is already in hand — starting at `true` threw it
     * away and showed a skeleton over a list that was ready to render, which is
     * the flash that made re-entering the chat look like a reload.
     */
    private loading;
    /** What was last handed to `app.history`; see `recordHistory`. */
    private historyKey;
    /**
     * Marks `<body>` while this page is mounted, so the stylesheet can suppress
     * whatever else the forum draws around it.
     *
     * Core's `Page.bodyClass` puts its class on `#app`, which is not enough: the
     * things this page has to hide are appended to `document.body` as siblings of
     * `#app` — `huseyinfiliz/modern-footer` mounts `<footer id="modern-footer">`
     * there from an `app.mount` extender, and it is not the only extension that
     * does. A class on `#app` cannot select an element outside `#app`.
     */
    protected static readonly BODY_CLASS = "ChatFullPage";
    oncreate(vnode: Mithril.VnodeDOM<CustomAttrs, this>): void;
    /**
     * Leaving for the forum with a drawer to put back: it grows out of the page
     * this was, the reverse of the way in.
     */
    onbeforeremove(vnode: Mithril.VnodeDOM<CustomAttrs, this>): void;
    oninit(vnode: Mithril.Vnode<CustomAttrs>): void;
    /**
     * Fires when the chat is left for the rest of the forum — not when moving between
     * chat routes, which share one mounted page (see ChatPageResolver).
     */
    onremove(vnode: Mithril.VnodeDOM<CustomAttrs, this>): void;
    onbeforeupdate(vnode: Mithril.VnodeDOM<CustomAttrs, this>): void;
    /**
     * Registers the current chat route with core's history stack.
     *
     * This is what puts a back button in the phone toolbar. Core mounts its
     * `Navigation` into `#app-navigation` and that component shows the back arrow
     * only when `app.history.canGoBack()` — otherwise it shows the drawer toggle.
     * `ForumApplication.mount()` seeds the stack with one entry (the index), and
     * every core page pushes its own on arrival: `IndexPage`, `DiscussionPage`,
     * `UserPage`, `NotificationsPage`, and outside core `TagsPage` and
     * flarum/messages' `MessagesPage`. This page never did, so the stack stayed one
     * deep on every chat route and the toolbar had nothing but the hamburger — on
     * `/chat`, `/chat/threads`, `/chat/bookmarks`, `/chat/search` and `/chat/flags`
     * alike.
     *
     * Three names rather than one, because the name is what core uses to decide
     * whether a visit is a new step or a replacement (`History#push` overwrites an
     * entry of the same name). Distinct names for the list, a channel and a section
     * make "back" walk channel → list → forum, which is the order the user arrived
     * in. One shared name would collapse all three into a single step and send them
     * from a channel straight out of the chat.
     *
     * Called from `onbeforeupdate` as well as `oninit` because every chat route
     * shares one mounted page (see ChatPageResolver): moving between them is an
     * update, not a create. The key guard keeps that to one push per actual change
     * rather than one per redraw.
     */
    protected recordHistory(): void;
    /**
     * Brings the active channel and thread back in line with the URL.
     *
     * Every chat route shares one mounted page (see ChatPageResolver), so `oninit`
     * runs once and cannot be where the route is read. In-app navigation sets the
     * state before it routes, which makes this a no-op most of the time; it exists
     * for the paths that do not — browser back and forward, and a link arriving from
     * outside the page.
     */
    protected syncFromRoute(): void;
    view(): Mithril.Children;
    /**
     * Whether the route names one of the sidebar's sections rather than a channel.
     *
     * These fill the main pane on their own — a section is shown whether or not a
     * channel happens to be selected — and unlike a channel they render no header,
     * so on a phone they are also the routes that need a back control put in the
     * toolbar for them.
     */
    protected static isSectionRoute(routeName: string | undefined): boolean;
    /**
     * What the phone toolbar calls this page.
     *
     * Keyed off the route rather than the open channel, for the reason given on
     * `mobileTitleControl`. The strings are the sidebar's own, so a section is named
     * the same in the bar as in the list it came from and no new translations are
     * introduced for installs that have already been translated.
     */
    protected toolbarTitle(routeName: string | undefined): string;
    protected mainPane(routeName: string | undefined, channel: Channel | null, narrow: boolean, threadId: number | null): Mithril.Children;
    protected empty(): Mithril.Children;
    protected boot(): Promise<void>;
    protected select(channel: Channel): void;
    protected deselect(): void;
    /**
     * Closing the panel also drops the thread from the URL, so a reload does not
     * reopen what was just dismissed.
     */
    protected closeThread(channel: Channel): void;
}
