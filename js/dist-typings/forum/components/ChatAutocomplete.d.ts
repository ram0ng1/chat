import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type User from "flarum/common/models/User";
import type Mithril from "mithril";
/**
 * One suggestion. `insert` is what replaces the typed fragment, `label` is what
 * the row shows — they differ for an emoji, where the row shows the glyph and the
 * insert is the shortcode.
 *
 * `emoji` is `Children` rather than `string` because a Flamoji custom emoji is an
 * image, not a codepoint. Mithril renders a string child as text either way, so
 * the Unicode case is unaffected.
 */
export interface Suggestion {
    key: string;
    insert: string;
    label: string;
    hint?: string | null;
    user?: User | null;
    emoji?: Mithril.Children;
    icon?: string | null;
}
export interface ChatAutocompleteAttrs extends ComponentAttrs {
    suggestions: Suggestion[];
    activeIndex: number;
    onSelect: (suggestion: Suggestion) => void;
    onHover: (index: number) => void;
}
/**
 * The suggestion list above the composer.
 *
 * Deliberately presentational: which trigger opened it, what matched and how the
 * insertion is spliced into the textarea all live in ChatComposer, because they
 * depend on the caret. This only draws rows and reports clicks.
 */
export default class ChatAutocomplete extends Component<ChatAutocompleteAttrs> {
    onupdate(vnode: Mithril.VnodeDOM<ChatAutocompleteAttrs>): void;
    view(): Mithril.Children;
}
