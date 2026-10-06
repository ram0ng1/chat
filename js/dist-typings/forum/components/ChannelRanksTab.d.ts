import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
import type Channel from "../../common/models/Channel";
import type { RankBook, RankEntry } from "../../common/models/Channel";
/** Mirrors ChannelRank::MAX_PER_CHANNEL and ChannelRank::NAME_MAX. */
export declare const MAX_RANKS = 20;
export declare const RANK_NAME_MAX = 32;
type RankPath = "" | "/update" | "/delete" | "/order" | "/assign";
/**
 * One rank write, answered with the channel; the new `rankBook` lands on the
 * store record every author line and the members tab read from.
 */
export declare function rankRequest(channelId: number | string, path: RankPath, attributes: Record<string, unknown>): Promise<Channel | null>;
interface RankDraft {
    /** 'new', a built-in's name, or a custom rank's id. */
    target: "new" | "owner" | "moderator" | number;
    builtin: "owner" | "moderator" | null;
    name: string;
    color: string;
    /** A built-in left at the theme's colour. */
    themeColor: boolean;
    icon: string;
    showBadge: boolean;
}
export interface ChannelRanksTabAttrs extends ComponentAttrs {
    channel: Channel;
}
/**
 * The ranks editor, for whoever may manage them: the two built-in ranks every
 * channel has, then the owner's own in priority order, each editable, the own
 * ones reorderable and removable. Handing them out happens in the Members tab,
 * where the people are.
 *
 * Draws only from the channel's `rankBook`, and every write answers with the
 * new one, so the list is always the server's word.
 */
export default class ChannelRanksTab extends Component<ChannelRanksTabAttrs> {
    private draft;
    private working;
    private loading;
    oninit(vnode: Mithril.Vnode<ChannelRanksTabAttrs, this>): void;
    view(): Mithril.Children;
    protected book(): RankBook | null;
    protected load(): Promise<void>;
    protected row(book: RankBook, rank: RankEntry, custom: RankEntry[]): Mithril.Children;
    protected holders(book: RankBook, rank: RankEntry): number;
    protected edit(rank: RankEntry | null): void;
    protected editor(draft: RankDraft): Mithril.Children;
    /**
     * The rank as it would look, on a light surface and on a dark one side by
     * side, so whoever picks a colour sees both themes before anyone else does.
     */
    protected preview(draft: RankDraft): Mithril.Children;
    protected save(draft: RankDraft): Promise<void>;
    protected move(custom: RankEntry[], index: number, by: number): Promise<void>;
    protected remove(rank: RankEntry): Promise<void>;
    protected run(path: RankPath, attributes: Record<string, unknown>, successKey: string | null, after?: () => void): Promise<void>;
}
export {};
