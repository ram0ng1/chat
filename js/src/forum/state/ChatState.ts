import app from "flarum/forum/app";

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

const PAGE_SIZE = 50;

/**
 * The collection size a paginated response reports, or null when it does not.
 *
 * `Store#find` hangs the raw document off the array it returns, which is the
 * only way to reach anything outside `data` — and json-api-server puts the count
 * of the whole collection in `meta.page.total`. Reading it turns a `limit: 1`
 * request into "the newest one, and how many there are", for the price of the
 * one it was already making.
 */
function readTotal(results: unknown): number | null {
  const total = (
    results as { payload?: { meta?: { page?: { total?: unknown } } } } | null
  )?.payload?.meta?.page?.total;

  return typeof total === "number" ? total : null;
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
  channels: Channel[] = [];
  channelsLoading = false;
  channelsLoaded = false;

  /**
   * The in-flight channel request, so concurrent callers share one round trip.
   *
   * The old guard returned `this.channels` the moment a request was already
   * running, which is not the same thing: on a cold start that array is still
   * empty, so whichever caller arrived second was handed `[]` and carried on as
   * though the chat had no channels. Mount and the full-screen page's boot both
   * fire on a direct visit to /chat, so the two raced on every such visit.
   */
  private channelsRequest: Promise<Channel[]> | null = null;

  /**
   * Whether drafts have been fetched this session.
   *
   * Drafts are written back by the composer as they change, so once fetched the
   * in-memory copy is the newer of the two — refetching would at best confirm
   * what is already held and at worst overwrite it with a slower round trip.
   */
  private draftsLoaded = false;
  private draftsRequest: Promise<void> | null = null;

  /** Loaded message windows, keyed by channel id. */
  streams: Record<number, ChannelStream> = {};

  /** Threads loaded per channel, keyed by channel id. */
  threads: Record<number, Thread[]> = {};

  /** Loaded message windows for opened threads, keyed by thread id. */
  threadStreams: Record<number, ChannelStream> = {};

  /**
   * The newest pinned message per channel, for the bar above the stream.
   *
   * Fetched separately because the pinned message is usually far above the loaded
   * window — that is the point of pinning it. `null` means "asked, nothing pinned",
   * which is distinct from `undefined`, "not asked yet".
   */
  pinnedPreviews: Record<number, Message | null> = {};

  /**
   * How many pinned messages each channel has, as the server last counted them.
   *
   * Read from the preview request's own pagination meta rather than asked for
   * separately. Kept apart from `pinnedPreviews` because it answers a different
   * question — that one is "what does the bar show", this one is "is there more
   * than that" — and because a channel can have a total without a preview when
   * the preview request failed.
   */
  private pinnedTotals: Record<number, number> = {};

  /** Composer drafts, keyed by `channelId` or `channelId:threadId`. */
  drafts: Record<string, string> = {};

  /** Typing indicators, keyed by channel id then user id. */
  typing: Record<number, Record<number, TypingEntry>> = {};

  /** Currently viewed channel and thread. */
  activeChannelId: number | null = null;
  activeThreadId: number | null = null;

  /**
   * Whether the pinned-messages panel is open.
   *
   * It shares the right-hand slot with the thread panel, so opening one closes the
   * other — see togglePinned() / setActiveThread().
   */
  showPinned = false;

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
  showSearch = false;

  /**
   * Drawer open/collapsed state.
   *
   * Persisted, so the drawer survives a reload and closes only when the user
   * explicitly dismisses it. Mutate through the setters below rather than
   * assigning directly, or the change will not be remembered.
   */
  drawerOpen = false;
  drawerCollapsed = false;

  /**
   * The drawer was closed to go full screen, not dismissed.
   *
   * Both leave `drawerOpen` false, and the difference matters on the way back: a
   * drawer the user closed with the X should stay closed, while one that only
   * stepped aside for the full-screen page should come back when the page is left.
   * Without this the two were indistinguishable, so returning to the forum always
   * looked like the chat had been closed.
   */
  drawerSuspended = false;

  /** Selection mode, for quote/copy/move. */
  selecting = false;
  selected: Set<number> = new Set();

  /**
   * Message being replied to or edited, keyed by composer scope — the same
   * `channelId` / `channelId:threadId` key the drafts use.
   *
   * Scoped rather than global because the channel and an open thread render two
   * composers over this one state object. Sharing a single `editing` field meant
   * starting an edit in the thread panel put the channel composer into edit mode
   * too, and sending from there would PATCH that message from the wrong scope.
   */
  private replyTargets: Record<string, Message> = {};
  private editTargets: Record<string, Message> = {};

  /**
   * Reply scopes whose next send should branch into a new thread.
   *
   * Replying and branching stage the same way — both put a target in
   * `replyTargets` — so without recording which one was asked for, the composer
   * had nothing to tell them apart and inferred it: any reply in a channel with
   * threading on became a thread, and a plain reply was unreachable.
   */
  private branchTargets: Record<string, true> = {};

  /** Attachments staged in the composer but not yet sent. */
  pendingUploads: Upload[] = [];

  /**
   * Optimistic rows awaiting their server response, keyed by a client-generated
   * token. They are rendered greyed out and replaced in place on success.
   */
  private pending: Map<string, { channelId: number; token: string }> =
    new Map();
  private pendingSeq = 0;

  // ───────────────────────────────────────────────────────────────────────────
  // Drawer persistence
  // ───────────────────────────────────────────────────────────────────────────

  /**
   * Keyed by user so a shared browser cannot restore one account's open channel
   * into another's session.
   */
  private storageKey(): string | null {
    const id = app.session.user?.id();

    return id ? `ramon-chat.drawer.${id}` : null;
  }

  /**
   * Reads back the drawer state saved by a previous visit.
   *
   * Returns whether the drawer should be open, so the caller can decide whether
   * to pay for loading the channel list.
   */
  restoreDrawer(): boolean {
    const key = this.storageKey();

    if (!key) return false;

    try {
      const raw = localStorage.getItem(key);

      if (!raw) return false;

      const saved = JSON.parse(raw) as {
        open?: boolean;
        collapsed?: boolean;
        suspended?: boolean;
        channelId?: number | null;
      };

      this.drawerOpen = Boolean(saved.open);
      this.drawerCollapsed = Boolean(saved.collapsed);
      // Persisted so a reload while on the full-screen page does not lose the fact
      // that the drawer is owed a reopening.
      this.drawerSuspended = Boolean(saved.suspended);

      if (saved.channelId) {
        this.activeChannelId = Number(saved.channelId);
      }

      return this.drawerOpen;
    } catch {
      // Private browsing, a quota error, or hand-edited garbage. Losing the
      // restored position is not worth breaking startup over.
      return false;
    }
  }

  private persistDrawer(): void {
    const key = this.storageKey();

    if (!key) return;

    try {
      localStorage.setItem(
        key,
        JSON.stringify({
          open: this.drawerOpen,
          collapsed: this.drawerCollapsed,
          suspended: this.drawerSuspended,
          channelId: this.activeChannelId,
        }),
      );
    } catch {
      // Non-fatal, as above.
    }
  }

  setDrawerOpen(open: boolean): void {
    this.drawerOpen = open;

    // Opening or closing the drawer directly settles the question either way, so a
    // pending suspension is stale from here on. In particular this is what makes
    // the X final: closing by hand while suspended must not be undone later.
    this.drawerSuspended = false;

    this.persistDrawer();
  }

  /**
   * Closes the drawer because the full-screen page is taking over the view.
   *
   * Distinct from both `setDrawerOpen(false)`, which would clear a pending
   * suspension, and `suspendDrawer()`, which would create one: arriving at the page
   * by any route other than the drawer's own full-screen button must not earn a
   * reopening on the way out.
   */
  hideDrawerForPage(): void {
    this.drawerOpen = false;
    this.persistDrawer();
  }

  /**
   * Closes the drawer on the understanding that it is owed a reopening.
   *
   * Used when handing the conversation over to the full-screen page, which is the
   * one case where the drawer disappears without the user having dismissed it.
   */
  suspendDrawer(): void {
    this.drawerOpen = false;
    this.drawerSuspended = true;
    this.persistDrawer();
  }

  /**
   * Reopens a suspended drawer, and reports whether there was one.
   *
   * Consuming the flag here rather than at the call site keeps "restored at most
   * once" a property of the state instead of a rule each caller has to remember.
   */
  resumeDrawer(): boolean {
    if (!this.drawerSuspended) return false;

    this.drawerSuspended = false;
    this.drawerOpen = true;
    this.persistDrawer();

    return true;
  }

  setDrawerCollapsed(collapsed: boolean): void {
    this.drawerCollapsed = collapsed;
    this.persistDrawer();
  }

  /**
   * Records which channel is being viewed, so a reload reopens the same one.
   */
  setActiveChannel(channelId: number | null): void {
    this.activeChannelId = channelId;
    this.persistDrawer();
  }

  // ───────────────────────────────────────────────────────────────────────────
  // Boot payload
  // ───────────────────────────────────────────────────────────────────────────

  /** Whether the boot payload has already been read; it is only ever good once. */
  private bootHydrated = false;

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
  hydrateFromBoot(): void {
    if (this.bootHydrated) return;

    this.bootHydrated = true;

    const boot = (app as unknown as { data?: Record<string, any> }).data
      ?.ramonChat;

    if (!boot) return;

    try {
      this.hydrateChannels(boot.channels);
      this.hydrateDrafts(boot.drafts);

      const channelId = Number(boot.channelId ?? 0);

      if (channelId > 0) {
        this.hydrateStream(this.stream(channelId), boot.messages, channelId);
        this.hydratePinned(channelId, boot.pinned);
      }

      const threadId = Number(boot.threadId ?? 0);

      if (threadId > 0) {
        this.hydrateStream(this.threadStream(threadId), boot.threadMessages);
      }
    } catch {
      // A preload that broke the chat would be worse than the flash it removes.
      // Whatever was not hydrated is simply fetched the way it always was.
    }
  }

  /**
   * Pushes a JSON:API document into the store and returns its primary models.
   *
   * `null` for a document that is absent or malformed, which the callers treat as
   * "the server did not answer this one". That is distinct from an empty `data`,
   * which is a real answer — an account with no channels, a conversation with
   * nothing pinned — and must be honoured rather than refetched.
   */
  private pushDocument<T>(document: unknown): T[] | null {
    if (!document || typeof document !== "object") return null;

    const data = (document as { data?: unknown }).data;

    if (!Array.isArray(data)) return null;

    const pushed = app.store.pushPayload(document as never) as unknown;

    return (Array.isArray(pushed) ? pushed : [pushed]).filter(Boolean) as T[];
  }

  private hydrateChannels(document: unknown): void {
    if (this.channelsLoaded) return;

    const channels = this.pushDocument<Channel>(document);

    if (channels === null) return;

    this.channels = channels;
    this.channelsLoaded = true;
  }

  private hydrateDrafts(document: unknown): void {
    if (this.draftsLoaded) return;

    const rows = (document as { data?: unknown })?.data;

    if (!Array.isArray(rows)) return;

    for (const row of rows) {
      const { channelId, threadId, content } = row?.attributes ?? {};

      if (channelId && content) {
        this.drafts[
          this.draftKey(Number(channelId), threadId ? Number(threadId) : null)
        ] = content;
      }
    }

    this.draftsLoaded = true;
  }

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
  private hydrateStream(
    stream: ChannelStream,
    document: unknown,
    channelId?: number,
  ): void {
    if (stream.loadedInitial || stream.loading) return;

    const page = this.pushDocument<Message>(document);

    if (page === null) return;

    if (channelId !== undefined) {
      // Frozen here for the same reason loadChannel() freezes it: the divider
      // marks where reading stopped last time, and must not follow the marker
      // down the stream as markRead() catches it up.
      const channel = this.channel(channelId);
      const lastRead = channel?.lastReadMessageId() ?? 0;

      stream.dividerAfterId =
        lastRead > 0 && (channel?.unreadCount() ?? 0) > 0 ? lastRead : null;
    }

    if (page.length < PAGE_SIZE) stream.hasMore = false;

    stream.messages = page.slice().reverse();
    this.sortStream(stream);
    stream.loadedInitial = true;
  }

  private hydratePinned(channelId: number, document: unknown): void {
    if (channelId in this.pinnedPreviews) return;

    const pinned = this.pushDocument<Message>(document);

    if (pinned === null) return;

    this.pinnedPreviews[channelId] = pinned[0] ?? null;

    // The preloaded document is the same one the request would have returned, so
    // it carries the same count. Read straight off it rather than through
    // `readTotal`, which expects what the store hands back.
    const total = (document as { meta?: { page?: { total?: unknown } } })?.meta
      ?.page?.total;

    this.pinnedTotals[channelId] = typeof total === "number" ? total : 0;
  }

  // ───────────────────────────────────────────────────────────────────────────
  // Channels
  // ───────────────────────────────────────────────────────────────────────────

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
   */
  async loadChannels(force = false): Promise<Channel[]> {
    // Join a request already in flight rather than issuing a second one.
    if (this.channelsRequest) return this.channelsRequest;

    if (this.channelsLoaded && !force) return this.channels;

    this.channelsLoading = true;

    this.channelsRequest = (async () => {
      try {
        const results = (await app.store.find("chat-channels", {
          filter: { following: true },
          sort: "-lastMessageAt",
          page: { limit: 50 },
        })) as unknown as Channel[];

        this.channels = Array.isArray(results) ? results : [];
        this.channelsLoaded = true;

        return this.channels;
      } finally {
        this.channelsLoading = false;
        this.channelsRequest = null;
        m.redraw();
      }
    })();

    return this.channelsRequest;
  }

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
  seedEmptyChannel(channelId: number): void {
    const stream = this.stream(channelId);

    if (!stream.loadedInitial && !stream.loading) {
      stream.messages = [];
      stream.hasMore = false;
      stream.dividerAfterId = null;
      stream.loadedInitial = true;
    }

    if (!(channelId in this.pinnedPreviews)) {
      this.pinnedPreviews[channelId] = null;
    }
  }

  /**
   * Puts a channel at the top of the sidebar, if it is not already listed.
   *
   * The list is sorted by last activity and a conversation just started is the
   * most recent thing there is, so the front is where it belongs — and where the
   * server would put it on the next fetch anyway.
   */
  rememberChannel(channel: Channel): void {
    if (this.channels.some((existing) => existing.id() === channel.id()))
      return;

    this.channels.unshift(channel);
  }

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
  prefetchChannel(channelId: number): void {
    this.loadChannel(channelId).catch(() => {});
    this.loadPinnedPreview(channelId).catch(() => {});
  }

  invalidateChannels(): void {
    this.channelsLoaded = false;
  }

  channel(id: number | null): Channel | null {
    if (id === null) return null;

    return (
      (app.store.getById("chat-channels", String(id)) as Channel | undefined) ??
      null
    );
  }

  /** Category channels, for the sidebar's "Channels" section. */
  categoryChannels(): Channel[] {
    return this.channels.filter((c) => c.isCategory());
  }

  /** Direct and group channels, for the "Direct Messages" section. */
  directChannels(): Channel[] {
    return this.channels.filter((c) => c.isDirect());
  }

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
  unreadSummary(): { channels: number; messages: number; mentions: number } {
    if (this.channelsLoaded && this.channels.length > 0) {
      let channels = 0;
      let messages = 0;
      let mentions = 0;

      for (const channel of this.channels) {
        // Muted channels carry no badge, so they must not feed the header count
        // either — otherwise the number says there is something to read in a
        // channel the user deliberately silenced.
        if (channel.isMuted()) continue;

        if (channel.hasUnread()) {
          channels++;
          messages += channel.unreadCount() ?? 0;
        }

        if (channel.hasUnreadMentions())
          mentions += channel.unreadMentionsCount() ?? 0;
      }

      return { channels, messages, mentions };
    }

    // Before the channel list is loaded — which is the usual state when the chat
    // has not been opened yet — the serialised counters are all there is. Realtime
    // keeps them moving; see bumpUnreadCounters().
    const user = app.session.user;

    return {
      channels: Number(user?.attribute<number>("chatUnreadChannelsCount") ?? 0),
      messages: Number(user?.attribute<number>("chatUnreadMessagesCount") ?? 0),
      mentions: Number(user?.attribute<number>("chatUnreadMentionsCount") ?? 0),
    };
  }

  /**
   * Moves the actor's own serialised counters.
   *
   * They are a snapshot taken when the page was rendered, and the header badge and
   * the nav dot fall back to them whenever the channel list has not been loaded —
   * which is exactly the situation the badge exists for. Without this the dot only
   * ever appeared after a reload.
   */
  bumpUnreadCounters(
    messages: number,
    mentions: number,
    newChannel: boolean,
  ): void {
    const user = app.session.user;

    if (!user) return;

    const at = (key: string) => Number(user.attribute<number>(key) ?? 0);

    user.pushAttributes({
      chatUnreadMessagesCount: Math.max(
        0,
        at("chatUnreadMessagesCount") + messages,
      ),
      chatUnreadMentionsCount: Math.max(
        0,
        at("chatUnreadMentionsCount") + mentions,
      ),
      chatUnreadChannelsCount: Math.max(
        0,
        at("chatUnreadChannelsCount") + (newChannel ? 1 : 0),
      ),
    });
  }

  // ───────────────────────────────────────────────────────────────────────────
  // Message stream
  // ───────────────────────────────────────────────────────────────────────────

  stream(channelId: number): ChannelStream {
    if (!this.streams[channelId]) {
      this.streams[channelId] = {
        messages: [],
        hasMore: true,
        loading: false,
        loadedInitial: false,
        dividerAfterId: null,
      };
    }

    return this.streams[channelId];
  }

  /**
   * Loads the newest page and freezes the unread divider at the read marker as
   * it stood on entry.
   */
  async loadChannel(channelId: number): Promise<void> {
    const stream = this.stream(channelId);

    if (stream.loadedInitial || stream.loading) return;

    const channel = this.channel(channelId);
    const lastRead = channel?.lastReadMessageId() ?? 0;

    // Only show a divider when there is actually something unread below it.
    stream.dividerAfterId =
      lastRead > 0 && (channel?.unreadCount() ?? 0) > 0 ? lastRead : null;

    await this.fetchPage(channelId);

    stream.loadedInitial = true;
  }

  /**
   * Fetches one page older than what is loaded. The API sorts newest-first, so a
   * page is reversed before being prepended.
   */
  async fetchPage(channelId: number): Promise<void> {
    await this.fetchInto(this.stream(channelId), { channel: channelId });
  }

  /**
   * Pages one stream backwards from a base filter.
   *
   * Shared by the channel and thread streams: both are windows over the same
   * endpoint differing only in which filter selects them, and paging that drifts
   * between the two would show a thread a different history than the channel.
   */
  private async fetchInto(
    stream: ChannelStream,
    filter: Record<string, unknown>,
  ): Promise<void> {
    if (stream.loading || !stream.hasMore) return;

    stream.loading = true;

    try {
      const oldest = stream.messages[0];

      const results = (await app.store.find("chat-messages", {
        filter: {
          ...filter,
          ...(oldest ? { lessThan: Number(oldest.id()) } : {}),
        },
        sort: "-id",
        page: { limit: PAGE_SIZE },
      })) as unknown as Message[];

      const page = (Array.isArray(results) ? results : []).slice().reverse();

      if (page.length < PAGE_SIZE) stream.hasMore = false;

      // Merge rather than concat: a realtime push may already have inserted one
      // of these, and a duplicate row is worse than a missing one.
      const known = new Set(stream.messages.map((msg) => msg.id()));
      const fresh = page.filter((msg) => !known.has(msg.id()));

      stream.messages = [...fresh, ...stream.messages];
      this.sortStream(stream);
    } finally {
      stream.loading = false;
      m.redraw();
    }
  }

  // ───────────────────────────────────────────────────────────────────────────
  // Threads
  // ───────────────────────────────────────────────────────────────────────────

  /**
   * A thread's own message window, keyed by thread id.
   *
   * Kept apart from `streams` rather than filtered out of the channel window: the
   * channel shows a thread only as an indicator under its root message, so the
   * replies are never in that window to begin with.
   */
  threadStream(threadId: number): ChannelStream {
    if (!this.threadStreams[threadId]) {
      this.threadStreams[threadId] = {
        messages: [],
        hasMore: true,
        loading: false,
        loadedInitial: false,
        // Threads carry no read marker of their own, so no divider.
        dividerAfterId: null,
      };
    }

    return this.threadStreams[threadId];
  }

  async loadThread(threadId: number): Promise<void> {
    const stream = this.threadStream(threadId);

    if (stream.loadedInitial || stream.loading) return;

    await this.fetchThreadPage(threadId);

    stream.loadedInitial = true;
  }

  async fetchThreadPage(threadId: number): Promise<void> {
    await this.fetchInto(this.threadStream(threadId), { thread: threadId });
  }

  /**
   * Loads the thread record itself, for the panel's title and reply count.
   */
  async findThread(threadId: number): Promise<Thread | null> {
    const known = app.store.getById<Thread>("chat-threads", String(threadId));

    if (known) return known;

    try {
      return (
        ((await app.store.find(
          "chat-threads",
          String(threadId),
        )) as unknown as Thread) ?? null
      );
    } catch {
      return null;
    }
  }

  closeThread(): void {
    this.activeThreadId = null;
  }

  /**
   * Leaves whatever is covering the conversation in the drawer.
   *
   * Called when the drawer changes channel: a search or a pin list belongs to
   * the channel it was opened from, and carrying it across to the next one shows
   * one channel's results over another's conversation.
   */
  closeOverlays(): void {
    this.showPinned = false;
    this.showSearch = false;
    this.activeThreadId = null;
  }

  // ───────────────────────────────────────────────────────────────────────────
  // Pinned
  // ───────────────────────────────────────────────────────────────────────────

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
  async loadPinnedPreview(channelId: number, force = false): Promise<void> {
    if (!force && channelId in this.pinnedPreviews) return;

    try {
      const results = (await app.store.find("chat-messages", {
        filter: {
          channel: channelId,
          pinned: true,
          includeThreadReplies: true,
        },
        sort: "-pinnedAt",
        page: { limit: 1 },
      })) as unknown as Message[];

      this.pinnedPreviews[channelId] =
        (Array.isArray(results) ? results[0] : null) ?? null;

      // How many there are in total, which is what decides whether the bar
      // offers a way into the full list. It comes back in this same response —
      // json-api-server puts a count beside every paginated collection — so
      // knowing it costs nothing beyond the request already being made.
      this.pinnedTotals[channelId] = readTotal(results) ?? 0;
    } catch {
      // A missing bar is not worth an error; the pin itself still shows on the row.
      this.pinnedPreviews[channelId] = null;
      this.pinnedTotals[channelId] = 0;
    } finally {
      m.redraw();
    }
  }

  /**
   * Drops a channel's cached pin, so the next open asks the server again.
   *
   * For the one case realtime cannot reconcile on its own: a message pinned
   * above the loaded window, which this client is not holding and therefore
   * cannot update in place.
   */
  invalidatePinnedPreview(channelId: number): void {
    delete this.pinnedPreviews[channelId];
    delete this.pinnedTotals[channelId];
  }

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
  pinnedCount(channelId: number): number {
    const known = new Set<string>();

    const preview = this.pinnedPreviews[channelId];

    if (preview?.isPinned() && preview.id()) known.add(String(preview.id()));

    for (const message of this.streams[channelId]?.messages ?? []) {
      if (message.isPinned() && !message.isDeleted() && message.id()) {
        known.add(String(message.id()));
      }
    }

    return Math.max(this.pinnedTotals[channelId] ?? 0, known.size);
  }

  /**
   * The message the pinned bar should show.
   *
   * Computed over the loaded window as well as the fetched preview, and the newest
   * `pinnedAt` wins. That way pinning something in view updates the bar at once,
   * with no refetch, and unpinning the message the bar was showing drops it.
   */
  latestPinned(channelId: number): Message | null {
    const candidates: Message[] = [];

    const preview = this.pinnedPreviews[channelId];

    if (preview && preview.isPinned()) candidates.push(preview);

    for (const message of this.streams[channelId]?.messages ?? []) {
      if (message.isPinned() && !message.isDeleted()) candidates.push(message);
    }

    if (candidates.length === 0) return null;

    return candidates.reduce((newest, message) =>
      (message.pinnedAt()?.getTime() ?? 0) > (newest.pinnedAt()?.getTime() ?? 0)
        ? message
        : newest,
    );
  }

  /** The pinned panel, the search pane and the thread panel share one slot. */
  togglePinned(): void {
    this.showPinned = !this.showPinned;

    if (this.showPinned) {
      this.activeThreadId = null;
      this.showSearch = false;
    }
  }

  /** Search, in the drawer's overlay slot. See `showSearch`. */
  toggleSearch(): void {
    this.showSearch = !this.showSearch;

    if (this.showSearch) {
      this.activeThreadId = null;
      this.showPinned = false;
    }
  }

  /** Chronological, by id. Ids are monotonic per channel. */
  private sortStream(stream: ChannelStream): void {
    stream.messages.sort((a, b) => Number(a.id()) - Number(b.id()));
  }

  /**
   * Inserts or replaces a message in its channel's stream. Used by both the send
   * path and realtime, so ordering and de-duplication live in one place.
   */
  upsertMessage(message: Message): void {
    const channelId = message.channelId();

    if (!channelId) return;

    const threadId = message.threadId();

    // A thread reply belongs to the open thread panel. Routed here rather than at
    // each call site so realtime and the send path cannot disagree about it.
    if (threadId) {
      this.insertInto(this.threadStreams[threadId], message, true);
    }

    // A thread *reply* must not be appended to the channel window: the API's
    // channel filter keeps thread roots and drops their replies, so appending one
    // would inline in realtime what a reload shows only as an indicator. Updating
    // a row already in the window stays safe — that is the root gaining a thread.
    //
    // Nothing is created for a channel that was never opened; its badge is enough
    // until the user looks at it.
    this.insertInto(
      this.streams[channelId],
      message,
      !threadId || this.isThreadRoot(message),
    );
  }

  private isThreadRoot(message: Message): boolean {
    // `false` here means the relationship is not loaded, and a message with no
    // thread on it cannot be a root. That used to be the answer for every
    // realtime push; the thread broadcast now links the root as it lands, so
    // this is only reached for replies and for roots nobody has scrolled to.
    const thread = message.thread();

    if (!thread) return false;

    return thread.originalMessageId() === Number(message.id());
  }

  private insertInto(
    stream: ChannelStream | undefined,
    message: Message,
    appendIfNew: boolean,
  ): void {
    if (!stream) return;

    const index = stream.messages.findIndex((msg) => msg.id() === message.id());

    if (index >= 0) {
      stream.messages[index] = message;

      return;
    }

    if (!appendIfNew) return;

    stream.messages.push(message);
    this.sortStream(stream);
  }

  removeMessage(channelId: number, messageId: number): void {
    const stream = this.streams[channelId];

    if (!stream) return;

    stream.messages = stream.messages.filter(
      (msg) => Number(msg.id()) !== messageId,
    );
  }

  /**
   * The same, for the panel a thread renders in.
   *
   * Separate because thread streams live in their own map: passing a thread id
   * to `removeMessage` would look it up among the channels, and on a forum where
   * a channel happens to carry that id it would sweep the wrong conversation.
   */
  removeThreadMessage(threadId: number, messageId: number): void {
    const stream = this.threadStreams[threadId];

    if (!stream) return;

    stream.messages = stream.messages.filter(
      (msg) => Number(msg.id()) !== messageId,
    );
  }

  // ───────────────────────────────────────────────────────────────────────────
  // Sending
  // ───────────────────────────────────────────────────────────────────────────

  /**
   * Sends a message. Returns the saved model.
   *
   * The composer is cleared before the request resolves so typing can continue
   * immediately; on failure the content is handed back so nothing is lost.
   */
  async send(
    channelId: number,
    content: string,
    options: {
      threadId?: number | null;
      replyToId?: number | null;
      createThread?: boolean;
    } = {},
  ): Promise<Message | null> {
    const trimmed = content.trim();
    const uploadIds = this.pendingUploads.map((u) => Number(u.id()));

    if (!trimmed && uploadIds.length === 0) return null;

    const token = `pending-${++this.pendingSeq}`;
    this.pending.set(token, { channelId, token });

    try {
      const payload = await app.request<{ data: any; included?: any[] }>({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-messages`,
        body: {
          data: {
            type: "chat-messages",
            attributes: {
              channelId,
              content: trimmed,
              threadId: options.threadId ?? null,
              replyToId: options.replyToId ?? null,
              createThread: options.createThread ?? false,
              uploadIds,
            },
          },
        },
      });

      const message = app.store.pushPayload<Message>(payload as any);

      this.upsertMessage(message);
      this.pendingUploads = [];
      this.clearContext(channelId, options.threadId ?? null);

      // The sender is implicitly caught up.
      const channel = this.channel(channelId);

      if (channel) {
        channel.pushAttributes({
          unreadCount: 0,
          lastReadMessageId: Number(message.id()),
        });
      }

      this.clearDraft(channelId, options.threadId ?? null);

      return message;
    } finally {
      this.pending.delete(token);
      m.redraw();
    }
  }

  hasPendingSends(channelId: number): boolean {
    for (const entry of this.pending.values()) {
      if (entry.channelId === channelId) return true;
    }

    return false;
  }

  // ───────────────────────────────────────────────────────────────────────────
  // Read state
  // ───────────────────────────────────────────────────────────────────────────

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
  markRead(channelId: number): void {
    const channel = this.channel(channelId);

    if (!channel) return;

    const stream = this.streams[channelId];
    const newest = stream?.messages[stream.messages.length - 1];
    const upTo = newest ? Number(newest.id()) : channel.lastMessageId();

    if (!upTo) return;
    if ((channel.lastReadMessageId() ?? 0) >= upTo) return;

    channel.pushAttributes({
      unreadCount: 0,
      unreadMentionsCount: 0,
      lastReadMessageId: upTo,
    });

    app
      .request({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-channels/${channelId}/read`,
        body: { data: { attributes: { lastReadMessageId: upTo } } },
      })
      .catch(() => {
        // Swallowed deliberately — see above.
      });
  }

  // ───────────────────────────────────────────────────────────────────────────
  // Drafts
  // ───────────────────────────────────────────────────────────────────────────

  draftKey(channelId: number, threadId: number | null = null): string {
    return threadId ? `${channelId}:${threadId}` : String(channelId);
  }

  // ───────────────────────────────────────────────────────────────────────────
  // Composer context (reply / edit), per scope
  // ───────────────────────────────────────────────────────────────────────────

  replyingTo(
    channelId: number,
    threadId: number | null = null,
  ): Message | null {
    return this.replyTargets[this.draftKey(channelId, threadId)] ?? null;
  }

  editing(channelId: number, threadId: number | null = null): Message | null {
    return this.editTargets[this.draftKey(channelId, threadId)] ?? null;
  }

  /** Whether the staged reply was started as a branch rather than a reply. */
  branchingFrom(channelId: number, threadId: number | null = null): boolean {
    return this.branchTargets[this.draftKey(channelId, threadId)] === true;
  }

  /**
   * Replying and editing are mutually exclusive within a scope.
   *
   * `branch` says the reply was staged by "reply in thread" rather than by
   * "reply". Only the composer reads it, and only to decide whether the send
   * should open a thread — see ChatComposer.submit().
   */
  setReplyingTo(
    channelId: number,
    message: Message | null,
    threadId: number | null = null,
    branch = false,
  ): void {
    const key = this.draftKey(channelId, threadId);

    delete this.editTargets[key];
    delete this.branchTargets[key];

    if (message) {
      this.replyTargets[key] = message;

      if (branch) this.branchTargets[key] = true;
    } else {
      delete this.replyTargets[key];
    }
  }

  setEditing(
    channelId: number,
    message: Message | null,
    threadId: number | null = null,
  ): void {
    const key = this.draftKey(channelId, threadId);

    delete this.replyTargets[key];
    delete this.branchTargets[key];

    if (message) {
      this.editTargets[key] = message;
    } else {
      delete this.editTargets[key];
    }
  }

  clearContext(channelId: number, threadId: number | null = null): void {
    const key = this.draftKey(channelId, threadId);

    delete this.replyTargets[key];
    delete this.editTargets[key];
    delete this.branchTargets[key];
  }

  draft(channelId: number, threadId: number | null = null): string {
    return this.drafts[this.draftKey(channelId, threadId)] ?? "";
  }

  setDraft(
    channelId: number,
    content: string,
    threadId: number | null = null,
  ): void {
    const key = this.draftKey(channelId, threadId);

    if (content.trim() === "") {
      delete this.drafts[key];
    } else {
      this.drafts[key] = content;
    }

    this.persistDraft(channelId, threadId, content);
  }

  clearDraft(channelId: number, threadId: number | null = null): void {
    delete this.drafts[this.draftKey(channelId, threadId)];
    this.persistDraft(channelId, threadId, "");
  }

  private draftTimer: number | null = null;

  /**
   * Debounced so a keystroke does not become a request. Drafts are server-side so
   * they follow the user across devices, but they are not worth a write per
   * character.
   */
  private persistDraft(
    channelId: number,
    threadId: number | null,
    content: string,
  ): void {
    if (this.draftTimer !== null) window.clearTimeout(this.draftTimer);

    this.draftTimer = window.setTimeout(() => {
      app
        .request({
          method: "POST",
          url: `${app.forum.attribute("apiUrl")}/chat/drafts`,
          body: { data: { attributes: { channelId, threadId, content } } },
        })
        .catch(() => {});
    }, 1200);
  }

  /**
   * Restores saved composer drafts, at most once per session.
   *
   * Cached for the same reason the channel list is, and one more: this is the
   * only reader of a value the composer is continuously writing. Refetching on
   * every visit to the chat raced a save that had not yet landed, so a draft
   * typed just before navigating away could come back as its previous revision.
   */
  async loadDrafts(): Promise<void> {
    // Drafts belong to an account, and the endpoint is authenticated. Guarded here
    // rather than at each of the three call sites: a guest asking for them gets a
    // 401 whose rejection surfaces as an error alert over a chat that is otherwise
    // working, and a fourth call site would reintroduce it.
    if (!app.session.user) return;

    if (this.draftsRequest) return this.draftsRequest;
    if (this.draftsLoaded) return;

    this.draftsRequest = this.fetchDrafts().finally(() => {
      this.draftsRequest = null;
    });

    return this.draftsRequest;
  }

  private async fetchDrafts(): Promise<void> {
    try {
      const payload = await app.request<{ data: any[] }>({
        method: "GET",
        url: `${app.forum.attribute("apiUrl")}/chat/drafts`,
      });

      for (const row of payload.data ?? []) {
        const { channelId, threadId, content } = row.attributes ?? {};

        if (channelId && content) {
          this.drafts[
            this.draftKey(Number(channelId), threadId ? Number(threadId) : null)
          ] = content;
        }
      }

      this.draftsLoaded = true;
    } catch {
      // Drafts are a convenience; failing to restore them must not block the UI.
      // Deliberately not marked loaded, so the next visit retries.
    }
  }

  // ───────────────────────────────────────────────────────────────────────────
  // Typing
  // ───────────────────────────────────────────────────────────────────────────

  /** Live typists in a channel, excluding entries that have expired. */
  typistsIn(channelId: number): string[] {
    const entries = this.typing[channelId];

    if (!entries) return [];

    const now = Date.now();
    const names: string[] = [];

    for (const [userId, entry] of Object.entries(entries)) {
      if (entry.expiresAt <= now) {
        window.clearTimeout(entry.timer);
        delete entries[Number(userId)];
        continue;
      }

      names.push(entry.username);
    }

    return names;
  }

  noteTyping(
    channelId: number,
    userId: number,
    username: string,
    typing: boolean,
    expiresIn = 6,
  ): void {
    if (!this.typing[channelId]) this.typing[channelId] = {};

    // Whatever happens next replaces this entry, so its pending redraw is stale.
    // Left running, a stopped typist's old timer would fire against a fresh
    // entry — and a stream of keystrokes would pile up one timer per event.
    const previous = this.typing[channelId][userId];

    if (previous) window.clearTimeout(previous.timer);

    if (!typing) {
      delete this.typing[channelId][userId];

      return;
    }

    // A shade past the deadline, so the redraw reads an entry that has already
    // expired rather than one expiring on the same millisecond.
    const timer = window.setTimeout(() => m.redraw(), expiresIn * 1000 + 100);

    this.typing[channelId][userId] = {
      username,
      expiresAt: Date.now() + expiresIn * 1000,
      timer,
    };
  }

  /**
   * Drops a typist outright — they have just said what they were typing.
   *
   * Waiting for the entry to expire leaves "X is typing…" sitting under the very
   * message X sent, for as long as the window that was still open when it
   * arrived.
   */
  clearTyping(channelId: number, userId: number): void {
    this.noteTyping(channelId, userId, "", false);
  }

  private typingSentAt = 0;

  /**
   * Announces typing, throttled to at most once every 3s. Without the throttle
   * this would be one request per keystroke.
   */
  announceTyping(channelId: number): void {
    const now = Date.now();

    if (now - this.typingSentAt < 3000) return;

    this.typingSentAt = now;

    app
      .request({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat/typing`,
        body: { data: { attributes: { channelId, typing: true } } },
      })
      .catch(() => {});
  }

  // ───────────────────────────────────────────────────────────────────────────
  // Selection
  // ───────────────────────────────────────────────────────────────────────────

  toggleSelecting(on?: boolean): void {
    this.selecting = on ?? !this.selecting;

    if (!this.selecting) this.selected.clear();
  }

  toggleSelected(messageId: number): void {
    if (this.selected.has(messageId)) {
      this.selected.delete(messageId);
    } else {
      this.selected.add(messageId);
    }
  }

  /**
   * Renders the current selection as a transcript. Server-side so quoting rules
   * match archiving exactly.
   */
  async transcript(format: "markup" | "plain" = "markup"): Promise<string> {
    if (this.selected.size === 0) return "";

    const payload = await app.request<{
      data: { attributes: { content: string } };
    }>({
      method: "POST",
      url: `${app.forum.attribute("apiUrl")}/chat/transcript`,
      body: {
        data: { attributes: { messageIds: Array.from(this.selected), format } },
      },
    });

    return payload.data?.attributes?.content ?? "";
  }
}
