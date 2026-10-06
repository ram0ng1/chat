/**
 * Global chat shortcuts.
 *
 * Deliberately few and all modified: an unmodified single-letter shortcut on a page
 * that is mostly text entry is a trap, so the only bare key handled is Escape, and
 * only when the chat itself owns the current mode.
 *
 *   Alt+C          toggle the drawer
 *   Alt+Shift+C    go to the full-screen chat
 *   Alt+K          jump to search, scoped to the open channel
 *   Escape         leave selection mode, then close the thread/pinned panel
 *
 * Alt rather than Ctrl/Cmd throughout: the browser and the OS have prior claim on
 * almost every Ctrl combination, and Alt+letter is what Discord and Slack use for
 * their own navigation.
 */
export declare function bindShortcuts(): void;
/** Which key sends. Enter unless the member asked for Ctrl+Enter. */
export type SendKey = "enter" | "ctrl";
/**
 * This member's own answer, defaulting to Enter.
 *
 * Anything that is not "ctrl" reads as Enter, which also absorbs the "default"
 * value stored while the preference had a third "follow the forum" state: those
 * rows resolve to Enter without needing to be rewritten.
 */
export declare function sendKeyPreference(): SendKey;
/**
 * Whether the composer sends on Ctrl/Cmd+Enter instead of on a bare Enter.
 *
 * Read per call rather than cached: a cached copy would keep the old behaviour
 * for the rest of the session after the member changed it.
 */
export declare function sendsOnCtrlEnter(): boolean;
