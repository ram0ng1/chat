/**
 * Admin-configurable label and icon for the chat.
 *
 * Both the header button and the drawer header read from here, so renaming the
 * feature (to "Lounge", "Radio", whatever) or swapping its icon is one setting
 * rather than a translation override plus a CSS hack.
 */
/** The chat's display name. Falls back to the translated default when unset. */
export declare function chatTitle(): string;
/**
 * The Font Awesome class for the chat icon, or null when the admin has turned the
 * icon off.
 *
 * Returning null rather than an empty string keeps the call sites honest: they
 * have to decide what to render without an icon, instead of emitting an `<i>` with
 * no class that collapses to a stray gap.
 */
export declare function chatIcon(): string | null;
