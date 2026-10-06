import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import type Mithril from "mithril";
export interface StickerPickerAttrs extends ComponentAttrs {
    onInsert: (shortcode: string) => void;
    onClose: () => void;
}
/**
 * The chat's own sticker picker.
 *
 * ramon/stickers ships one, and reusing it was the first attempt — but it is
 * `require`d lazily inside that extension's own open handler, so it registers
 * itself in Flarum's export registry only *after* somebody has opened it from a
 * discussion composer. Until then the lookup correctly reports it as absent, and
 * the chat's button never appeared. Nothing here can force that module to load.
 *
 * So this reads the same data through the API instead: `stickers` is a JSON:API
 * resource, and the extension registers its model in the store at boot, which is
 * a signal that is true from the first render.
 *
 * Inserting is a shortcode, not markup: the formatter turns `:name:` into the
 * sticker on send, exactly as it does for a message typed by hand.
 */
export default class StickerPicker extends Component<StickerPickerAttrs> {
    private stickers;
    private loading;
    private filter;
    private outsideListener?;
    oninit(vnode: Mithril.Vnode<StickerPickerAttrs>): void;
    oncreate(vnode: Mithril.VnodeDOM<StickerPickerAttrs>): void;
    /**
     * Hydrate after every render, not only on create: the grid is rebuilt whenever
     * the filter changes, and the new nodes arrive unhydrated.
     */
    onupdate(vnode: Mithril.VnodeDOM<StickerPickerAttrs>): void;
    onremove(): void;
    view(): Mithril.Children;
    /**
     * The thumbnail, in the markup ramon/stickers itself emits.
     *
     * A `.tgs` is gzip-compressed Lottie and a `.json` is Lottie; neither renders in
     * an `<img>`, and the first version of this drew their names instead — which on
     * a library of animated stickers meant a grid of words.
     *
     * Rather than reimplementing those renderers, this emits the exact markup the
     * extension's formatter produces, and its MutationObserver on `document.body`
     * picks them up and animates them. That observer already exists to hydrate
     * stickers inside posts; it does not care where the nodes came from.
     *
     * The path is resolved the same way too: a relative one is served from the forum
     * root, which is what their PHP does before writing the attribute.
     */
    protected thumbnail(sticker: any): Mithril.Children;
    protected absolute(path: string): string;
    protected onKey(e: KeyboardEvent): void;
    protected load(): Promise<void>;
}
