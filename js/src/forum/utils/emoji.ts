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
 * The reactions the hover bar and the picker open with, plus a few obvious ones,
 * so the common case renders synchronously and the chunk load is invisible.
 */
const COMMON: Record<string, string> = {
  heart: "❤️",
  "+1": "👍",
  "-1": "👎",
  tada: "🎉",
  eyes: "👀",
  laughing: "😆",
  smile: "😄",
  cry: "😢",
  fire: "🔥",
  rocket: "🚀",
  thinking: "🤔",
  clap: "👏",
  speech_balloon: "💬",
  white_check_mark: "✅",
  x: "❌",
};

/** name → unicode, built by inverting the package's unicode → names map. */
let full: Record<string, string> | null = null;
let loading = false;

/**
 * Kicks off the chunk load. Safe to call repeatedly; only the first call fetches.
 *
 * Redraws on completion so anything already rendered from COMMON (or rendered as
 * a raw shortcode) upgrades in place.
 */
export function loadEmojiMap(): void {
  if (full || loading) return;

  loading = true;

  // `./emojiMap`, not the package by name: the chunk registration keys off a path
  // resolved relative to this file, so a bare package name yields an unregistered
  // chunk whose URL 404s. See the comment in emojiMap.ts.
  import("./emojiMap")
    .then((module) => {
      const source = (module.default ?? module) as Record<string, string[]>;
      const inverted: Record<string, string> = {};

      for (const [unicode, names] of Object.entries(source)) {
        if (!Array.isArray(names)) continue;

        for (const name of names) {
          // First name wins: the package lists the canonical shortcode first, and
          // later aliases would otherwise overwrite it with the same value anyway.
          if (!(name in inverted)) inverted[name] = unicode;
        }
      }

      full = inverted;
      m.redraw();
    })
    .catch(() => {
      // Stay on COMMON. A missing chunk must not break the chat.
      full = {};
    })
    .finally(() => {
      loading = false;
    });
}

/** Strips the delimiters and whitespace from `:name:`. */
function normalise(input: string): string {
  return input.trim().replace(/^:+|:+$/g, "");
}

/**
 * True when the string already contains an emoji (or any non-ASCII pictograph),
 * meaning it needs no translation.
 *
 * Deliberately loose: the goal is to distinguish "the user pasted 💬" from "the
 * user typed speech_balloon", not to police which codepoints qualify.
 */
export function looksLikeEmoji(input: string): boolean {
  const value = input.trim();

  if (value === "" || value.length > 8) return false;

  return /[\p{Extended_Pictographic}\p{Emoji_Presentation}]/u.test(value);
}

/**
 * Resolves a shortcode, with or without colons, to a Unicode emoji.
 *
 * Returns the input unchanged when it is already an emoji, and `null` when the
 * shortcode is unknown — callers decide whether to show a fallback or nothing.
 */
export function resolveEmoji(input: string | null | undefined): string | null {
  if (!input) return null;

  if (looksLikeEmoji(input)) return input.trim();

  const name = normalise(input).toLowerCase();

  if (name === "") return null;

  // Trigger the full map so an unknown-now shortcode resolves after it lands.
  loadEmojiMap();

  return full?.[name] ?? COMMON[name] ?? null;
}

/**
 * Renders an emoji for display, falling back to the raw shortcode so an unknown
 * value is visible and debuggable rather than silently blank.
 */
export function displayEmoji(
  input: string | null | undefined,
  fallback = "",
): string {
  if (!input) return fallback;

  return resolveEmoji(input) ?? `:${normalise(input)}:`;
}

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
export function searchEmoji(query: string, limit = 48): EmojiSuggestion[] {
  loadEmojiMap();

  const source = full && Object.keys(full).length > 0 ? full : COMMON;
  const term = normalise(query).toLowerCase();

  const prefix: EmojiSuggestion[] = [];
  const contains: EmojiSuggestion[] = [];

  for (const [name, unicode] of Object.entries(source)) {
    if (term === "") {
      prefix.push({ name, unicode });

      if (prefix.length >= limit) break;

      continue;
    }

    const at = name.indexOf(term);

    if (at === 0) {
      prefix.push({ name, unicode });
    } else if (at > 0) {
      contains.push({ name, unicode });
    }

    if (prefix.length >= limit) break;
  }

  return [...prefix, ...contains].slice(0, limit);
}

/** True once the full map is available, so the picker can say it is still loading. */
export function emojiMapReady(): boolean {
  return full !== null;
}

/**
 * Whether a value is acceptable as a channel's emoji. Mirrors the server-side
 * check in ChannelResource so the form rejects it before the request.
 */
export function isValidEmoji(input: string | null | undefined): boolean {
  if (!input || input.trim() === "") return true; // empty is allowed

  return resolveEmoji(input) !== null;
}
