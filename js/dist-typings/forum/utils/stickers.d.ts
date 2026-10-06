import type Mithril from "mithril";
export declare function stickersAvailable(): boolean;
/**
 * Opens the picker over the page, inserting the chosen shortcode.
 *
 * Mounted on a node appended to the body: the composer clips its overflow, and a
 * panel rendered inside it would be cut off at the first row.
 *
 * @param trigger The button that opened it, used to position the panel.
 * @param onInsert Receives the shortcode, e.g. `:wave:`.
 */
export declare function openStickerPicker(trigger: HTMLElement | null, onInsert: (text: string) => void): Promise<void>;
export declare function close(): void;
/**
 * Hydrates the animated stickers inside a node, paused until hovered.
 *
 * The extension's own MutationObserver would do this eventually, but with the
 * options its admin setting dictates — and with `hover_play` off that means every
 * sticker in the grid animating at once: a wall of motion, and a lot of work for
 * one panel.
 *
 * A grid is not a message. In the stream the setting governs and this is not
 * called; here it is forced, because a picker exists to be scanned.
 *
 * Their renderers skip nodes already marked as initialised, so running first
 * means the observer leaves these alone rather than re-hydrating them without the
 * option. Unlike their picker component, these two modules are registered at
 * boot, so they are actually resolvable.
 */
export declare function playOnHover(node: HTMLElement): void;
export declare function isStickerPickerOpen(): boolean;
export declare function stickerIcon(): Mithril.Children;
/** Only meaningful while the extension is present. */
export declare function stickerLabel(): string;
