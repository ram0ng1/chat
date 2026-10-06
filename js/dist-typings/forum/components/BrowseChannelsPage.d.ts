import Page from "flarum/common/components/Page";
import type { IPageAttrs } from "flarum/common/components/Page";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
type BrowseFilter = "all" | "open" | "closed" | "archived" | "mine";
/**
 * Discourse's "Browse channels" page: every channel the actor can see, with join
 * controls and — for those who may — a way to create one.
 *
 * This is also the only place a channel can be created, so it is reachable from
 * the sidebar even when the actor has joined nothing.
 */
export default class BrowseChannelsPage<CustomAttrs extends IPageAttrs = IPageAttrs> extends Page<CustomAttrs> {
    private channels;
    private loading;
    private filter;
    private query;
    private searchTimer;
    oninit(vnode: Mithril.Vnode<CustomAttrs>): void;
    view(): Mithril.Children;
    /**
     * Nothing to show.
     *
     * Two different situations wearing one sentence before: a forum with no
     * channels at all, and a search that matched none. The first needs a way to
     * create one, the second needs a way back to the full list — and being told
     * "no channels match" on a forum that has none reads as a bug.
     */
    protected empty(): Mithril.Children;
    protected card(channel: Channel): Mithril.Children;
    protected setFilter(filter: BrowseFilter): void;
    /** Debounced so typing does not issue a request per keystroke. */
    protected onSearch(value: string): void;
    /** Back to the unfiltered list, without waiting out the search debounce. */
    protected clearSearch(): void;
    protected load(): Promise<void>;
    protected create(): void;
    protected edit(channel: Channel): void;
    /**
     * Joins, or with `hidden` inspects: in the channel with no place in the
     * member list, nothing announced and nothing notified. The server refuses
     * the hidden form for anyone without `inspectChannels`.
     */
    protected join(channel: Channel, hidden?: boolean): Promise<void>;
    /**
     * Accepts or declines an invitation from the card. Accepting leaves the
     * card in place as a joined one; declining leaves it as a plain channel the
     * reader may still join on their own.
     */
    protected answerInvitation(channel: Channel, accept: boolean): Promise<void>;
    protected open(channel: Channel): void;
}
export {};
