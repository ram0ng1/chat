export declare function setConnectionHandlers(handlers: {
    reconnect: () => void;
    change: () => void;
}): void;
/**
 * Whether the socket is connected and has proven it delivers end to end.
 *
 * The one state in which polling is pure waste: everything the poller would
 * fetch is already being pushed.
 */
export declare function realtimeLive(): boolean;
/**
 * Binds chat events on the actor's private websocket channel.
 *
 * Chat is delivered per-user rather than on the shared `public` channel — see
 * Ramon\Chat\Realtime\ChatBroadcaster for why that is a privacy boundary.
 *
 * Returns whether binding succeeded. It retries briefly because realtime creates
 * `app.websocket` inside its own `mount` extension, and extension load order is
 * not guaranteed — without the retry, being a few milliseconds early would
 * permanently downgrade the client to polling.
 */
export declare function bindRealtime(): boolean;
export declare function setPollingFallback(fn: () => void): void;
/** Whether the websocket handlers are live, for diagnostics. */
export declare function realtimeBound(): boolean;
/**
 * Whether anything has ever actually arrived over the socket.
 *
 * Distinct from `bound`, and the distinction is the whole point: subscribing
 * succeeds on the client whatever the server can or cannot do. If the forum's PHP
 * process cannot reach the websocket daemon — a common enough split, since the
 * queue worker and the web process are not always on the same side of a
 * firewall — every client sits on a healthy-looking socket that will never carry
 * a chat event, and the chat quietly stops updating until the page is reloaded.
 *
 * A delivered event is the only proof the whole round-trip works, so the poller
 * uses this to decide how hard it has to work. See startPolling() in index.tsx.
 */
export declare function realtimeDelivered(): boolean;
/**
 * Re-reads one message so the actor's own capability flags are the server's.
 *
 * The Show endpoint carries the same `defaultInclude` the listing does, so this
 * also lands `deletedBy` — which is what lets a tombstone name the moderator
 * rather than falling back to the unnamed wording.
 *
 * Exported because the moderator who performs the deletion needs it too, and
 * cannot get it from here: `whenMessageChanged` excludes the actor, so their own
 * client never sees the event. See ChatMessage.delete().
 */
export declare function refreshMessageCapabilities(id: number): void;
