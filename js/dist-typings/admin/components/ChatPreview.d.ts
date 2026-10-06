import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
export interface BrandingPreviewAttrs extends ComponentAttrs {
    title: string;
    icon: string;
    showIcon: boolean;
}
/**
 * How the chat's name and icon will read in the forum, drawn from the values
 * still being edited, so a typo in a Font Awesome class shows as a missing icon
 * before it is saved rather than after.
 *
 * The two places mirror the forum's own fallbacks (see forum/utils/branding):
 * an empty title reads "Chat", an empty icon reads `fas fa-comments`, and with
 * the icon off the header button carries the name instead.
 */
export declare class BrandingPreview extends Component<BrandingPreviewAttrs> {
    view(): Mithril.Children;
}
export interface MessagePreviewAttrs extends ComponentAttrs {
    name: string;
    avatarUrl: string | null;
    /** True when a real account announces: no bot label next to the name. */
    isUser: boolean;
}
/**
 * One announcement as it lands in a channel: the avatar, the name, the bot
 * label when the bot posts, and a sample line. The avatar is drawn at the size
 * the stream uses, so a busy picture that will not read small shows here.
 */
export declare class MessagePreview extends Component<MessagePreviewAttrs> {
    view(): Mithril.Children;
}
