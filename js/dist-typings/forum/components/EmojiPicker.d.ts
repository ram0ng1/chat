import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
export interface EmojiPickerAttrs extends ComponentAttrs {
    /** Current value: a shortcode or a Unicode emoji. */
    value: string | null;
    onchange: (value: string | null) => void;
    disabled?: boolean;
}
/**
 * A searchable emoji picker.
 *
 * The discussion composer's picker is an autocomplete triggered by typing `:`
 * mid-sentence, which is the right interaction for prose but wrong for a field
 * whose entire value is one emoji. Here the field *is* the search box: type to
 * filter, click or press Enter to choose.
 *
 * Stores Unicode rather than a shortcode. A stored `❤️` renders everywhere with no
 * map lookup and survives the extension being disabled, whereas a stored `heart`
 * is meaningless without this table.
 */
export default class EmojiPicker extends Component<EmojiPickerAttrs> {
    private open;
    private query;
    private highlighted;
    oninit(vnode: Mithril.Vnode<EmojiPickerAttrs>): void;
    view(): Mithril.Children;
    oncreate(vnode: Mithril.VnodeDOM<EmojiPickerAttrs>): void;
    onremove(vnode: Mithril.VnodeDOM<EmojiPickerAttrs>): void;
    private outsideHandler?;
    protected openPicker(): void;
    protected onInput(e: Event): void;
    protected onKeyDown(e: KeyboardEvent): void;
    protected choose(value: string | null): void;
}
