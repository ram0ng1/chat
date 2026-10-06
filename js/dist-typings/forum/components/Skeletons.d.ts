import type Mithril from "mithril";
/**
 * The channel, thread and pinned streams: avatar, author line, message line.
 */
export declare function MessageStreamSkeleton(rows?: number): Mithril.Children;
/**
 * What the main pane looks like before a channel is ready: the header bar, the
 * stream and the composer, in their final positions.
 *
 * This is the one that matters most — it is the whole right-hand side of the page
 * on first load, and a lone spinner there leaves the layout to snap into place a
 * second later.
 */
export declare function ChannelSkeleton(): Mithril.Children;
/**
 * The channel list: quick links, a section heading, then rows with an icon and a
 * name.
 */
export declare function SidebarSkeleton(): Mithril.Children;
/**
 * Cards with the icon, title, description and footer.
 *
 * Mirrors the real card's three bands rather than approximating them: the card
 * is a column, and a skeleton laid out as a row makes the list visibly jump into
 * place when the data arrives.
 */
export declare function BrowseSkeleton(cards?: number): Mithril.Children;
export declare function SearchResultsSkeleton(rows?: number): Mithril.Children;
export declare function ThreadsSkeleton(rows?: number): Mithril.Children;
export declare function MembersSkeleton(rows?: number): Mithril.Children;
