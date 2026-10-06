import type Mithril from "mithril";
import type { RankBook, RankEntry } from "../../common/models/Channel";
import type Message from "../../common/models/Message";
/**
 * Channel ranks on the client: which rank each person displays, and how to
 * draw it.
 *
 * Priority mirrors Ramon\Chat\Rank\RankBook: owner, moderator, then the owner's
 * ranks in the owner's order. Pure functions, no state: this module ends up in
 * more than one chunk, and state here would be one copy per chunk.
 */
export declare const ICON_PATTERN: RegExp;
export declare function displayedKey(book: RankBook, userId: number): string | null;
export declare function displayedRank(book: RankBook, userId: number): RankEntry | null;
/** The custom ranks a member holds, as their ids. */
export declare function heldRankIds(book: RankBook, userId: number): number[];
export declare function bookFor(channelId: number | string): RankBook | null;
/**
 * The rank before a message's author.
 *
 * The channel's book wins when it is loaded: realtime keeps it current, so a
 * rank renamed or taken away a moment ago is already right there. Without it,
 * the rank the server attached to the message when it was read.
 */
export declare function messageRank(message: Message): RankEntry | null;
/** The label: what the owner typed, or the translated default of a built-in. */
export declare function rankName(rank: RankEntry): string;
export declare function validIcon(icon: string | null | undefined): string | null;
/**
 * The tag. A custom colour comes in as custom properties the stylesheet reads;
 * a built-in left at its default has none, and its class picks the theme's.
 */
export declare function rankTag(rank: RankEntry, className?: string): Mithril.Children;
/**
 * Attributes for the element around a name drawn in the rank's colour: the
 * rank without its tag. Empty for a rank shown as a tag, and for none.
 */
export declare function rankNameAttrs(rank: RankEntry | null): {
    className?: string;
    style?: Record<string, string>;
    title?: string;
};
