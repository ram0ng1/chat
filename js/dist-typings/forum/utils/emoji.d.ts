/**
 * Shortcode → Unicode emoji resolution.
 *
 * ## Why a local map rather than flarum/emoji's
 *
 * flarum/emoji exposes the same data, but only through a dynamic `import()`, so it
 * lives in a lazily loaded chunk that is fetched when the composer autocomplete
 * first needs it — possibly never. Reading it through `flarum.reg.get()` would
 * therefore return `undefined` most of the time, which is the same trap that made
 * `forum/ForumApplication` unusable at initializer time.
 *
 * We import `simple-emoji-map` ourselves instead. It is the exact package
 * flarum/emoji builds its map from, so `:heart:` means the same thing in a chat
 * reaction as in a post — consistency that matters more than the 55 KB.
 *
 * ## Why lazily
 *
 * 55 KB of JSON has no business in the main bundle for a feature most page views
 * never touch. The dynamic import puts it in its own chunk, and a small inline
 * table covers the common reaction set so the first paint is never blank.
 */
/**
 * Kicks off the chunk load. Safe to call repeatedly; only the first call fetches.
 *
 * Redraws on completion so anything already rendered from COMMON (or rendered as
 * a raw shortcode) upgrades in place.
 */
export declare function loadEmojiMap(): void;
/**
 * True when the string already contains an emoji (or any non-ASCII pictograph),
 * meaning it needs no translation.
 *
 * Deliberately loose: the goal is to distinguish "the user pasted 💬" from "the
 * user typed speech_balloon", not to police which codepoints qualify.
 */
export declare function looksLikeEmoji(input: string): boolean;
/**
 * Resolves a shortcode, with or without colons, to a Unicode emoji.
 *
 * Returns the input unchanged when it is already an emoji, and `null` when the
 * shortcode is unknown — callers decide whether to show a fallback or nothing.
 */
export declare function resolveEmoji(input: string | null | undefined): string | null;
/**
 * Renders an emoji for display, falling back to the raw shortcode so an unknown
 * value is visible and debuggable rather than silently blank.
 */
export declare function displayEmoji(input: string | null | undefined, fallback?: string): string;
export interface EmojiSuggestion {
    /** Canonical shortcode, without colons. */
    name: string;
    unicode: string;
}
/**
 * Searches the map by shortcode for the picker.
 *
 * Prefix matches rank above substring matches, so typing "hea" surfaces `heart`
 * before `broken_heart`. Results are capped because the dropdown is scrollable,
 * not infinite, and scoring 1949 entries on every keystroke is enough work
 * already.
 */
export declare function searchEmoji(query: string, limit?: number): EmojiSuggestion[];
/** True once the full map is available, so the picker can say it is still loading. */
export declare function emojiMapReady(): boolean;
/**
 * Whether a value is acceptable as a channel's emoji. Mirrors the server-side
 * check in ChannelResource so the form rejects it before the request.
 */
export declare function isValidEmoji(input: string | null | undefined): boolean;
