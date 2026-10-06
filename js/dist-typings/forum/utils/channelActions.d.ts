import type Channel from "../../common/models/Channel";
import type ChatState from "../state/ChatState";
/**
 * One thing the actor can do to a channel from its header.
 *
 * A description rather than a rendered control, because the two surfaces that
 * offer these draw them differently: the channel header has room for a row of
 * icon buttons, the drawer's single bar does not and puts them behind a `⋯`
 * menu, where each one needs a label. Returning descriptors is what lets both
 * render the same set without either of them owning it.
 */
export interface ChannelAction {
    key: string;
    icon: string;
    /** Already translated: it is a button title in one surface and its text in the other. */
    label: string;
    /** Drawn pressed — the pinned panel is a toggle, not a command. */
    active?: boolean;
    loading?: boolean;
    onclick: () => void;
}
export interface ChannelActionOptions {
    /**
     * The surface has no route of its own — the drawer.
     *
     * An action that would navigate opens in place instead. Routing from the
     * drawer does not merely move the reader: ChatPage closes the drawer on
     * arrival, so "search in this channel" threw them out of the window they were
     * searching from and into full screen.
     */
    embedded?: boolean;
}
/**
 * Everything the actor may do to this channel, in the order both surfaces show
 * them.
 *
 * Every entry is gated on a server-computed flag, so a control is absent rather
 * than present-and-rejected — see ChannelPolicy.
 */
export declare function channelActions(channel: Channel, state: ChatState, options?: ChannelActionOptions): ChannelAction[];
/**
 * The channel's details — notification level, member list, and the state actions
 * the actor is allowed. Available to every member, unlike the settings form.
 */
export declare function openChannelInfo(channel: Channel): void;
