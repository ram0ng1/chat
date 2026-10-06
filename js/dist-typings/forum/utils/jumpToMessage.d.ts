import type ChatState from "../state/ChatState";
/**
 * Centers a stream row and highlights it for a few seconds.
 *
 * The target is looked up inside the scroller that contains `from`: the topic
 * panel and the channel draw the same `ChatMessage`, and a document-wide lookup
 * would scroll the wrong stream when both are open.
 *
 * Returns `false` when the row is not in the loaded window, so the caller can
 * warn instead of swallowing the click.
 */
export declare function jumpToMessage(id: number | string, from?: HTMLElement | null): boolean;
/**
 * Jumps to a channel message, first loading the slice of history that separates
 * it from the window when it is older than what is already on screen.
 *
 * The scroller is re-read after loading because the redraw may have recreated
 * it. Returns `false` only when the message cannot be in the channel stream:
 * a topic reply, a deleted one, or beyond the page limit.
 */
export declare function revealAndJump(state: ChatState, channelId: number, id: number | string, from?: HTMLElement | null): Promise<boolean>;
