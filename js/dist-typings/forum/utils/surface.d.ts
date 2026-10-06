/**
 * The width below which the chat has no room for two panes side by side.
 *
 * 767 is `@chat-mobile-breakpoint`, which is core's `@phone` bound. Kept here
 * rather than repeated as a literal at each call site: the number appeared in
 * three files and in the stylesheet, and a breakpoint that only *mostly* agrees
 * with itself produces layouts nobody can reproduce.
 */
export declare const CHAT_MOBILE_BREAKPOINT = 767;
/** Whether the viewport is narrow enough for the chat's single-pane layout. */
export declare function isNarrowViewport(): boolean;
/**
 * Where the chat should open: its own drawer, or the full-screen page.
 *
 * Four call sites asked this independently — the header button, the invite
 * notification, "start a chat" on a profile, and the drawer itself — and had
 * drifted into three different answers. One of them is now core's, so the drift
 * had started to matter rather than just being untidy.
 */
export declare function shouldUseChatDrawer(): boolean;
