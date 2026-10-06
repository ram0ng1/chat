import type Channel from "../../common/models/Channel";
import type Message from "../../common/models/Message";
import type Thread from "../../common/models/Thread";
import type Upload from "../../common/models/Upload";
/**
 * One channel's loaded message window plus its paging state.
 */
interface ChannelStream {
    messages: Message[];
    /** False once a page comes back shorter than the page size. */
    hasMore: boolean;
    loading: boolean;
    loadedInitial: boolean;
    /**
     * The read marker as it stood when the channel was opened. Frozen on purpose:
     * the "new messages" divider must stay put while you read, not jump to the
     * bottom as each message is marked read.
     */
    dividerAfterId: number | null;
    /**
     * Seeded from the local snapshot rather than from the server. Painted at once,
     * and replaced by the server's answer the first time the channel is shown —
     * see loadChannel() and revalidateStream().
     */
    stale: boolean;
}
interface TypingEntry {
    username: string;
    /** Epoch ms after which the indicator is dropped. */
    expiresAt: number;
    /**
     * Redraw scheduled for the moment it expires.
     *
     * `typistsIn()` drops expired entries, but only when something calls it — and
     * a typist who simply stops produces no further events, so nothing redraws and
     * the last frame keeps their name on screen indefinitely. The timer is what
     * makes the expiry visible.
     */
    timer: number;
}
/**
 * Single source of truth for the chat UI.
 *
 * Components read from here and stay presentational. Keeping paging, read state,
 * optimistic sends and realtime reconciliation in one place is what stops the
 * drawer and the full-screen page from drifting apart — they are two views over
 * this one object.
 */
export default class ChatState {
    /** Channels in the sidebar, newest activity first. */
    channels: Channel[];
    channelsLoading: boolean;
    channelsLoaded: boolean;
    /**
     * The in-flight channel request, so concurrent callers share one round trip.
     *
     * The old guard returned `this.channels` the moment a request was already
     * running, which is not the same thing: on a cold start that array is still
     * empty, so whichever caller arrived second was handed `[]` and carried on as
     * though the chat had no channels. Mount and the full-screen page's boot both
     * fire on a direct visit to /chat, so the two raced on every such visit.
     */
    private channelsRequest;
    /**
     * Whether drafts have been fetched this session.
     *
     * Drafts are written back by the composer as they change, so once fetched the
     * in-memory copy is the newer of the two — refetching would at best confirm
     * what is already held and at worst overwrite it with a slower round trip.
     */
    private draftsLoaded;
    private draftsRequest;
    /** The sidebar came from the local snapshot; refetch it on first use. */
    private channelsStale;
    /** Channels whose pinned preview came from the local snapshot. */
    private pinnedStale;
    /** Pin previews being fetched, so a prefetch and the view share one request. */
    private pinnedInFlight;
    /** Pending debounced snapshot write, if any. */
    private snapshotTimer;
    /**
     * Channels viewed this session, most recent first. What the snapshot keeps
     * message tails for — the ones the reader is likely to open next visit.
     */
    private recentChannelIds;
    /** Loaded message windows, keyed by channel id. */
    streams: Record<number, ChannelStream>;
    /** Threads loaded per channel, keyed by channel id. */
    threads: Record<number, Thread[]>;
    /** Loaded message windows for opened threads, keyed by thread id. */
    threadStreams: Record<number, ChannelStream>;
    /**
     * The newest pinned message per channel, for the bar above the stream.
     *
     * Fetched separately because the pinned message is usually far above the loaded
     * window — that is the point of pinning it. `null` means "asked, nothing pinned",
     * which is distinct from `undefined`, "not asked yet".
     */
    pinnedPreviews: Record<number, Message | null>;
    /**
     * How many pinned messages each channel has, as the server last counted them.
     *
     * Read from the preview request's own pagination meta rather than asked for
     * separately. Kept apart from `pinnedPreviews` because it answers a different
     * question — that one is "what does the bar show", this one is "is there more
     * than that" — and because a channel can have a total without a preview when
     * the preview request failed.
     */
    private pinnedTotals;
    /** Composer drafts, keyed by `channelId` or `channelId:threadId`. */
    drafts: Record<string, string>;
    /** Typing indicators, keyed by channel id then user id. */
    typing: Record<number, Record<number, TypingEntry>>;
    /** Currently viewed channel and thread. */
    activeChannelId: number | null;
    activeThreadId: number | null;
    /**
     * Whether the pinned-messages panel is open.
     *
     * It shares the right-hand slot with the thread panel, so opening one closes the
     * other — see togglePinned() / setActiveThread().
     */
    showPinned: boolean;
    /**
     * Whether the drawer is showing search instead of the conversation.
     *
     * Drawer-only, and it exists because the full-screen page has a route for this
     * and the drawer has none: `chat.search` is a page, so reaching it from the
     * drawer navigated away and closed the drawer to do it — searching a
     * conversation threw you out of the window you were searching from.
     *
     * Shares the overlay slot with the thread and pinned panels; the three toggles
     * clear each other.
     */
    showSearch: boolean;
    /**
     * Drawer open/collapsed state.
     *
     * Persisted, so the drawer survives a reload and closes only when the user
     * explicitly dismisses it. Mutate through the setters below rather than
     * assigning directly, or the change will not be remembered.
     */
    drawerOpen: boolean;
    drawerCollapsed: boolean;
    /**
     * The drawer was closed to go full screen, not dismissed.
     *
     * Both leave `drawerOpen` false, and the difference matters on the way back: a
     * drawer the user closed with the X should stay closed, while one that only
     * stepped aside for the full-screen page should come back when the page is left.
     * Without this the two were indistinguishable, so returning to the forum always
     * looked like the chat had been closed.
     */
    drawerSuspended: boolean;
    /** Selection mode, for quote/copy/move. */
    selecting: boolean;
    selected: Set<number>;
    /**
     * Message being replied to or edited, keyed by composer scope — the same
     * `channelId` / `channelId:threadId` key the drafts use.
     *
     * Scoped rather than global because the channel and an open thread render two
     * composers over this one state object. Sharing a single `editing` field meant
     * starting an edit in the thread panel put the channel composer into edit mode
     * too, and sending from there would PATCH that message from the wrong scope.
     */
    private replyTargets;
    private editTargets;
    /**
     * Reply scopes whose next send should branch into a new thread.
     *
     * Replying and branching stage the same way — both put a target in
     * `replyTargets` — so without recording which one was asked for, the composer
     * had nothing to tell them apart and inferred it: any reply in a channel with
     * threading on became a thread, and a plain reply was unreachable.
     */
    private branchTargets;
    /** Attachments staged in the composer but not yet sent. */
    pendingUploads: Upload[];
    /**
     * Optimistic rows awaiting their server response, keyed by a client-generated
     * token. They are rendered greyed out and replaced in place on success.
     */
    private pending;
    private pendingSeq;
    /**
     * Keyed by user so a shared browser cannot restore one account's open channel
     * into another's session.
     */
    private storageKey;
    /**
     * Reads back the drawer state saved by a previous visit.
     *
     * Returns whether the drawer should be open, so the caller can decide whether
     * to pay for loading the channel list.
     */
    restoreDrawer(): boolean;
    private persistDrawer;
    /**
     * Mirrors "drawer open, on this channel" into a cookie the server can read.
     *
     * localStorage is invisible to the page render, so a reload on a forum page
     * with the drawer open used to boot with nothing preloaded and assemble the
     * conversation over three round trips. Content\PreloadChat reads this cookie
     * and puts the drawer's channel list and conversation into the boot payload
     * of every page, the way it already does for /chat routes.
     *
     * Only a channel id, never content; absent whenever the drawer is closed, so
     * the server does no chat work for a page the reader is not chatting on.
     */
    private persistDrawerCookie;
    setDrawerOpen(open: boolean): void;
    /**
     * Closes the drawer because the full-screen page is taking over the view.
     *
     * Distinct from both `setDrawerOpen(false)`, which would clear a pending
     * suspension, and `suspendDrawer()`, which would create one: arriving at the page
     * by any route other than the drawer's own full-screen button must not earn a
     * reopening on the way out.
     */
    hideDrawerForPage(): void;
    /**
     * Closes the drawer on the understanding that it is owed a reopening.
     *
     * Used when handing the conversation over to the full-screen page, which is the
     * one case where the drawer disappears without the user having dismissed it.
     */
    suspendDrawer(): void;
    /**
     * Reopens a suspended drawer, and reports whether there was one.
     *
     * Consuming the flag here rather than at the call site keeps "restored at most
     * once" a property of the state instead of a rule each caller has to remember.
     */
    resumeDrawer(): boolean;
    setDrawerCollapsed(collapsed: boolean): void;
    /**
     * Records which channel is being viewed, so a reload reopens the same one.
     */
    setActiveChannel(channelId: number | null): void;
    /** Whether the boot payload has already been read; it is only ever good once. */
    private bootHydrated;
    /**
     * Fills the state from the payload Content\PreloadChat put in the page.
     *
     * This is what removes the staggered flash the chat opened with. The list, the
     * conversation, the pinned strip and the drafts were four requests fired after
     * the page had already mounted, each swapping a placeholder for content as it
     * landed; here they are already answered, and reading them is synchronous, so
     * the first frame the user sees is the finished chat.
     *
     * Called from the initializer, before anything mounts. `app.data` is populated
     * by `Application#load()`, which runs ahead of the initializers, so the payload
     * is there — but `app.forum` and `app.session` are not yet, which is why
     * nothing here may touch them.
     *
     * Every step is optional and independently guarded. A section the server could
     * not produce, or a stream something has already loaded, is left alone and the
     * ordinary async path serves it.
     */
    hydrateFromBoot(): void;
    /**
     * Seeds whatever the boot payload did not carry from the copy the last visit
     * left in localStorage — the sidebar and the tails of the channels most
     * recently viewed.
     *
     * Runs right after hydrateFromBoot() and before anything mounts, which is why
     * the snapshot lives in localStorage rather than IndexedDB: the read has to be
     * synchronous to land before the first view(), and at the size kept here
     * (see MAX_BYTES in utils/snapshot) it costs a few milliseconds. This is what
     * lets the chat open finished on a page the server did not preload — the
     * drawer opened by its button after a reload, or a channel visited last time.
     *
     * Nothing read here is trusted past the first paint. Each section is marked
     * stale, and the first thing that shows it replaces it with the server's
     * answer in the background — see loadChannels(), loadChannel() and
     * loadPinnedPreview(). The reader sees the conversation as it was, then the
     * few rows that changed, rather than a skeleton and then everything.
     *
     * Reads the user id from `app.data`, not `app.session`, for the same reason
     * hydrateFromBoot() does: the session is not built yet.
     */
    hydrateFromSnapshot(): void;
    private snapshotUserId;
    /**
     * Writes the snapshot soon, once things settle.
     *
     * Called wherever the sidebar or a stream changes — a page landing, a message
     * arriving, a channel being read. Debounced because those come in bursts, and
     * serialising fifty messages on every one of them would cost more than the
     * snapshot saves.
     */
    scheduleSnapshot(): void;
    /**
     * Writes the snapshot now. Also the `pagehide` and hidden-tab hook, so a
     * message that arrived a moment before the tab closed is in the next boot.
     *
     * Only what the server has confirmed: a stale stream is the previous
     * snapshot's, and writing it back would only ever age it.
     */
    flushSnapshot(): void;
    /** Drops every account's snapshot; see utils/snapshot. */
    purgeSnapshots(): void;
    /**
     * Pushes a JSON:API document into the store and returns its primary models.
     *
     * `null` for a document that is absent or malformed, which the callers treat as
     * "the server did not answer this one". That is distinct from an empty `data`,
     * which is a real answer — an account with no channels, a conversation with
     * nothing pinned — and must be honoured rather than refetched.
     */
    private pushDocument;
    private hydrateChannels;
    private hydrateDrafts;
    /**
     * Seeds one message window from a preloaded page.
     *
     * The page arrives newest-first, the way the endpoint sorts it, and is reversed
     * for the same reason `fetchInto` reverses its own — the stream is held
     * oldest-first so paging upwards prepends.
     *
     * `channelId` is passed only for a channel stream: it is what the unread
     * divider is read from, and threads carry no read marker of their own.
     */
    private hydrateStream;
    private hydratePinned;
    /**
     * Fetches the sidebar's channel list, at most once per session.
     *
     * Entering the chat used to refetch the whole list every time, which is what
     * made the page flash its skeleton and rebuild itself on each visit: nothing
     * distinguished "never asked" from "asked a moment ago", so leaving the chat
     * and coming back paid for a full round trip before anything could render.
     *
     * The list does not need refetching, because nothing else lets it go stale.
     * New messages, joins, leaves and channel edits all arrive over the websocket
     * and are applied to these same objects; the poller is the backstop when the
     * socket is down. A refetch on entry would duplicate work realtime has already
     * done — which is the definition of the reload the user sees.
     *
     * `force` exists for the cases where that is not true: signing in or out
     * changes whose channels these are, and the browse page can join one behind
     * the list's back.
     *
     * `errorHandler` is for the poller, which refetches on nobody's request and
     * must not raise core's alert when the answer is that the session has ended.
     */
    loadChannels(force?: boolean, errorHandler?: (error: {
        status?: number;
    }) => false | void): Promise<Channel[]>;
    /**
     * Drops the cached channel list so the next read refetches.
     *
     * For the changes that invalidate the whole list rather than one row — an
     * account change, or joining a channel from somewhere that does not already
     * hold the model.
     */
    /**
     * Marks a brand-new channel as loaded and empty, without asking the server.
     *
     * Only ever correct for a channel that was just created — the caller has to
     * know that, which is why it is a separate method rather than a branch inside
     * `loadChannel`. A channel inserted a moment ago in the transaction that
     * answered the request has no messages and nothing pinned, and asking for
     * either is a round trip whose answer is already known.
     *
     * Two requests saved on the path that most needs them: "Send message" on a
     * profile, where the whole point is that the conversation opens at once.
     */
    seedEmptyChannel(channelId: number): void;
    /**
     * Puts a channel at the top of the sidebar, if it is not already listed.
     *
     * The list is sorted by last activity and a conversation just started is the
     * most recent thing there is, so the front is where it belongs — and where the
     * server would put it on the next fetch anyway.
     */
    rememberChannel(channel: Channel): void;
    /**
     * Takes a channel off the sidebar, and off the screen if it was open.
     *
     * For a membership that ended: leaving, being removed, or a private channel
     * the reader can no longer see. The store record is left alone; a public
     * channel is still readable and the next fetch says what it may still do.
     */
    /**
     * A channel that no longer exists for this reader: deleted, or put out of
     * their reach. It leaves the list, its cached stream and pin, and the local
     * snapshot, so a reload cannot bring it back; whoever is looking at it is
     * stepped out of it with a notice rather than left on a page whose every
     * request now answers 404.
     */
    channelGone(channelId: number): void;
    /**
     * Error handler for requests scoped to one channel: a 403 or 404 means the
     * channel is gone for this reader, which `channelGone` settles quietly in
     * place of core's generic "not found" alert. Anything else falls through.
     */
    private goneHandler;
    forgetChannel(channelId: number): void;
    /**
     * Who wants to know when a channel's membership changes.
     *
     * The members tab is the one surface drawn from a list the realtime payload
     * cannot carry, so it re-reads the list when told. Listeners rather than a
     * counter on the state: a modal that is not open should cost nothing.
     */
    private membershipListeners;
    /** Returns the function that unsubscribes. */
    onMembershipChange(listener: (channelId: number) => void): () => void;
    notifyMembershipChange(channelId: number): void;
    /**
     * Warms everything opening a channel would ask for, without waiting for it.
     *
     * The boot payload only helps a page that was loaded from the server; arriving
     * at the chat from a link, or switching channels once inside it, is a client-
     * side navigation with nothing preloaded. Called from hover and focus, it turns
     * the round trip into something that happens while the pointer is still moving,
     * so the click lands on a conversation that is already there.
     *
     * Both calls are idempotent and self-guarding — a channel already loaded costs
     * nothing — so this is safe to fire as often as the pointer moves.
     */
    prefetchChannel(channelId: number): void;
    invalidateChannels(): void;
    channel(id: number | null): Channel | null;
    /** Category channels, for the sidebar's "Channels" section. */
    categoryChannels(): Channel[];
    /** Direct and group channels, for the "Direct Messages" section. */
    directChannels(): Channel[];
    /**
     * Channels with unread messages, and how many mentions are outstanding.
     *
     * Prefers the loaded channel list, because that reflects realtime pushes the
     * moment they land — `bumpChannel` increments it directly.
     *
     * Falls back to the counters serialised onto the session user, which are present
     * in the page payload from the first paint. Without the fallback the collapsed
     * drawer showed nothing until `loadChannels()` resolved, and nothing at all if
     * the drawer was opened without ever loading the list — which read as "the dot
     * does not work".
     */
    unreadSummary(): {
        channels: number;
        messages: number;
        mentions: number;
    };
    /**
     * Moves the actor's own serialised counters.
     *
     * They are a snapshot taken when the page was rendered, and the header badge and
     * the nav dot fall back to them whenever the channel list has not been loaded —
     * which is exactly the situation the badge exists for. Without this the dot only
     * ever appeared after a reload.
     */
    bumpUnreadCounters(messages: number, mentions: number, newChannel: boolean): void;
    stream(channelId: number): ChannelStream;
    /**
     * Loads the newest page and freezes the unread divider at the read marker as
     * it stood on entry.
     */
    loadChannel(channelId: number): Promise<void>;
    /**
     * Fetches one page older than what is loaded. The API sorts newest-first, so a
     * page is reversed before being prepended.
     */
    fetchPage(channelId: number): Promise<void>;
    /**
     * Pages one stream backwards from a base filter.
     *
     * Shared by the channel and thread streams: both are windows over the same
     * endpoint differing only in which filter selects them, and paging that drifts
     * between the two would show a thread a different history than the channel.
     */
    private fetchInto;
    /**
     * Makes sure a message is in the channel's loaded window, so it can be scrolled
     * to.
     *
     * The window only ever grows upwards from the newest message, so anything
     * older than it — a pin, a quoted reply — was unreachable: the pinned strip
     * drew its jump disabled and a quote said "not loaded". This pages the gap
     * between the oldest loaded row and the target in one run, keeping the window
     * contiguous. Resolves to whether the message is now there; a thread reply or
     * a deleted row never is, since the channel stream does not carry them.
     */
    revealMessage(channelId: number, messageId: number): Promise<boolean>;
    /**
     * Replaces a snapshot-seeded window with the server's newest page.
     *
     * One request, the same one a cold open makes — but not awaited, because the
     * conversation is already drawn. Within the range the page covers the server
     * is the authority: rows it no longer returns were deleted while this browser
     * was away and go; rows it returns land edited, reacted and re-pinned as they
     * now are. Rows older than the page were not asked about and are kept — the
     * reader scrolled to them last time and can still see them.
     *
     * On failure the snapshot stays on screen unrevalidated; the poller's
     * `greaterThan` and `updatedSince` cursors catch it up on the next tick.
     */
    private revalidateStream;
    /**
     * A thread's own message window, keyed by thread id.
     *
     * Kept apart from `streams` rather than filtered out of the channel window: the
     * channel shows a thread only as an indicator under its root message, so the
     * replies are never in that window to begin with.
     */
    threadStream(threadId: number): ChannelStream;
    loadThread(threadId: number): Promise<void>;
    fetchThreadPage(threadId: number): Promise<void>;
    /**
     * Loads the thread record itself, for the panel's title and reply count.
     */
    findThread(threadId: number): Promise<Thread | null>;
    closeThread(): void;
    /**
     * Leaves whatever is covering the conversation in the drawer.
     *
     * Called when the drawer changes channel: a search or a pin list belongs to
     * the channel it was opened from, and carrying it across to the next one shows
     * one channel's results over another's conversation.
     */
    closeOverlays(): void;
    /**
     * Fetches the newest pin that sits above the loaded window, once per channel.
     *
     * `latestPinned()` already answers from the loaded window whenever it can, so
     * this only exists for a pin far enough back that the stream has not reached
     * it — which is a fixed fact about the channel's history, not something that
     * needs re-asking on every visit. It was being refetched on every channel
     * open, and each one is a full message read: the resource resolves nine
     * capability policies and four relation-backed summaries to return one row.
     *
     * `channelId in this.pinnedPreviews` rather than a truthiness check, because
     * `null` here means "asked, nothing pinned" and must not be re-asked; only
     * `undefined` means "never asked".
     *
     * Realtime keeps the answer honest: a pin or unpin on a message this client
     * already holds updates that model in place, and a pin on one it does not
     * drops this cache — see `onMessageChanged`.
     */
    loadPinnedPreview(channelId: number, force?: boolean): Promise<void>;
    private fetchPinnedPreview;
    /**
     * Drops a channel's cached pin, so the next open asks the server again.
     *
     * For the one case realtime cannot reconcile on its own: a message pinned
     * above the loaded window, which this client is not holding and therefore
     * cannot update in place.
     */
    invalidatePinnedPreview(channelId: number): void;
    /**
     * How many pinned messages the channel has.
     *
     * The larger of what the server last counted and what is pinned in the loaded
     * window, because neither sees everything: the count cannot know about a pin
     * made since it was taken, and the window cannot know about one above it. Both
     * being wrong in only one direction is what makes the maximum the honest
     * answer rather than a guess.
     *
     * Used to decide whether the pinned bar is worth a second control. With one
     * pin the bar already shows it and clicking jumps to it, so a panel listing
     * that same message would be a click to see what is on screen.
     */
    pinnedCount(channelId: number): number;
    /**
     * The message the pinned bar should show.
     *
     * Computed over the loaded window as well as the fetched preview, and the newest
     * `pinnedAt` wins. That way pinning something in view updates the bar at once,
     * with no refetch, and unpinning the message the bar was showing drops it.
     */
    latestPinned(channelId: number): Message | null;
    /** The pinned panel, the search pane and the thread panel share one slot. */
    togglePinned(): void;
    /** Search, in the drawer's overlay slot. See `showSearch`. */
    toggleSearch(): void;
    /**
     * The channel's thread list, in the drawer's overlay slot.
     *
     * Drawer-only for the same reason search is: the page has `chat.threads`
     * as a route, and routing from the drawer closes it.
     */
    showThreads: boolean;
    toggleThreads(): void;
    /** Chronological, by id. Ids are monotonic per channel. */
    private sortStream;
    /**
     * Inserts or replaces a message in its channel's stream. Used by both the send
     * path and realtime, so ordering and de-duplication live in one place.
     */
    upsertMessage(message: Message): void;
    private isThreadRoot;
    private insertInto;
    removeMessage(channelId: number, messageId: number): void;
    /**
     * The same, for the panel a thread renders in.
     *
     * Separate because thread streams live in their own map: passing a thread id
     * to `removeMessage` would look it up among the channels, and on a forum where
     * a channel happens to carry that id it would sweep the wrong conversation.
     */
    removeThreadMessage(threadId: number, messageId: number): void;
    /**
     * Sends a message. Returns the saved model.
     *
     * The composer is cleared before the request resolves so typing can continue
     * immediately; on failure the content is handed back so nothing is lost.
     */
    send(channelId: number, content: string, options?: {
        threadId?: number | null;
        replyToId?: number | null;
        createThread?: boolean;
    }): Promise<Message | null>;
    hasPendingSends(channelId: number): boolean;
    /**
     * Marks a channel read up to its newest loaded message.
     *
     * Fire-and-forget: a failed read receipt is not worth surfacing, and the next
     * call will carry a newer marker anyway.
     *
     * Deliberately *not* gated on `hasUnread()`. A message arriving in the channel
     * you are looking at is marked read without ever being badged — realtime's
     * `bumpChannel` calls straight through here for the active channel — so the
     * badge is already zero and that guard turned every one of those calls into a
     * no-op. The marker then stopped at whatever was newest when the channel was
     * opened. Nothing showed it while the marker only drove a badge that was
     * correct anyway; read receipts read the marker directly, which is how it
     * surfaced as "seen" never reaching the newest message.
     *
     * The comparison below is the honest guard: it stops exactly when the marker
     * is already at or past the newest loaded message, which is also the condition
     * the server uses to decide whether the move is worth broadcasting.
     */
    markRead(channelId: number): void;
    draftKey(channelId: number, threadId?: number | null): string;
    replyingTo(channelId: number, threadId?: number | null): Message | null;
    editing(channelId: number, threadId?: number | null): Message | null;
    /** Whether the staged reply was started as a branch rather than a reply. */
    branchingFrom(channelId: number, threadId?: number | null): boolean;
    /**
     * Replying and editing are mutually exclusive within a scope.
     *
     * `branch` says the reply was staged by "reply in thread" rather than by
     * "reply". Only the composer reads it, and only to decide whether the send
     * should open a thread — see ChatComposer.submit().
     */
    setReplyingTo(channelId: number, message: Message | null, threadId?: number | null, branch?: boolean): void;
    setEditing(channelId: number, message: Message | null, threadId?: number | null): void;
    clearContext(channelId: number, threadId?: number | null): void;
    draft(channelId: number, threadId?: number | null): string;
    setDraft(channelId: number, content: string, threadId?: number | null): void;
    clearDraft(channelId: number, threadId?: number | null): void;
    private draftTimer;
    /**
     * Debounced so a keystroke does not become a request. Drafts are server-side so
     * they follow the user across devices, but they are not worth a write per
     * character.
     */
    private persistDraft;
    /**
     * Restores saved composer drafts, at most once per session.
     *
     * Cached for the same reason the channel list is, and one more: this is the
     * only reader of a value the composer is continuously writing. Refetching on
     * every visit to the chat raced a save that had not yet landed, so a draft
     * typed just before navigating away could come back as its previous revision.
     */
    loadDrafts(): Promise<void>;
    private fetchDrafts;
    /** Live typists in a channel, excluding entries that have expired. */
    typistsIn(channelId: number): string[];
    noteTyping(channelId: number, userId: number, username: string, typing: boolean, expiresIn?: number): void;
    /**
     * Drops a typist outright — they have just said what they were typing.
     *
     * Waiting for the entry to expire leaves "X is typing…" sitting under the very
     * message X sent, for as long as the window that was still open when it
     * arrived.
     */
    clearTyping(channelId: number, userId: number): void;
    private typingSentAt;
    /**
     * Announces typing, throttled to at most once every 3s. Without the throttle
     * this would be one request per keystroke.
     */
    announceTyping(channelId: number): void;
    toggleSelecting(on?: boolean): void;
    toggleSelected(messageId: number): void;
    /**
     * Renders the current selection as a transcript. Server-side so quoting rules
     * match archiving exactly.
     */
    transcript(format?: "markup" | "plain"): Promise<string>;
}
export {};
