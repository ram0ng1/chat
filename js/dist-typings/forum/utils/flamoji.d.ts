import type Mithril from "mithril";
export interface CustomEmoji {
    /** Bare shortcode, no colons — `kappa`. The key everything matches on. */
    name: string;
    /** The exact trigger to type, as stored — usually `:kappa:`. */
    insert: string;
    /** Absolute image URL. */
    url: string;
    /** Admin-set label, falling back to the name. */
    title: string;
}
/**
 * Whether Flamoji is installed and enabled.
 *
 * Keyed on its picker button component rather than on a forum attribute. Both
 * would work, but the button is what the composer actually needs — testing for
 * the thing being used keeps the check honest if Flamoji ever reshuffles its
 * settings.
 *
 * Unlike ramon/stickers' picker, this component is registered in Flamoji's main
 * bundle at boot, so the lookup is reliable from the first render rather than
 * only after somebody has opened it once.
 */
export declare function flamojiAvailable(): boolean;
/**
 * Fetches the custom-emoji set once per page.
 *
 * The same unpaginated endpoint Flamoji's picker uses, hit directly rather than
 * through the store: these records are read-only here, and pushing them into
 * `app.store` would mean depending on Flamoji having registered its `flamojis`
 * model — one more thing to be absent.
 *
 * Redraws on completion so anything already rendered from an empty set (a
 * reaction chip showing `:kappa:`, an autocomplete list without customs)
 * upgrades in place.
 */
export declare function loadCustomEmoji(): void;
/** The custom emoji for a bare shortcode, or null. */
export declare function customEmoji(shortcode: string | null | undefined): CustomEmoji | null;
/**
 * Searches custom emoji by shortcode, for the composer's `:` autocomplete.
 *
 * Ranked the same way `searchEmoji` ranks the Unicode set — prefix before
 * substring — so a merged list is ordered consistently rather than by which
 * source it came from.
 */
export declare function searchCustomEmoji(query: string, limit?: number): CustomEmoji[];
/** The `<img>` a custom emoji renders as, sized by our own LESS. */
export declare function customEmojiImage(emoji: CustomEmoji, className?: string): Mithril.Children;
/**
 * The composer's emoji button, or nothing when Flamoji is absent.
 *
 * @param onInsert Receives the chosen emoji — a shortcode for a custom one, the
 *   literal glyph for a standard one, per the admin's `picker_set`.
 * @param disabled Mirrors the other tools while a send is in flight.
 */
export declare function flamojiPickerButton(onInsert: (text: string) => void, disabled?: boolean): Mithril.Children;
