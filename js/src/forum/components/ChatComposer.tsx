import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import type User from "flarum/common/models/User";
import type ChatState from "../state/ChatState";
import ChatAutocomplete from "./ChatAutocomplete";
import type { Suggestion } from "./ChatAutocomplete";
import { searchEmoji } from "../utils/emoji";
import { messagePreview } from "../../common/utils/preview";
import { humanDuration } from "../utils/duration";
import { resolveMaxMessageLength } from "../utils/messageLimit";
import { sendsOnCtrlEnter } from "../utils/shortcuts";
import MessageTooLongModal from "./MessageTooLongModal";
import { authorName } from "../utils/bot";
import {
  stickersAvailable,
  stickerIcon,
  stickerLabel,
  openStickerPicker,
  close as closeStickerPicker,
  isStickerPickerOpen,
} from "../utils/stickers";
import {
  flamojiPickerButton,
  loadCustomEmoji,
  searchCustomEmoji,
  customEmojiImage,
} from "../utils/flamoji";

export interface ChatComposerAttrs extends ComponentAttrs {
  channel: Channel;
  state: ChatState;
  threadId?: number | null;
  /** Called after a successful send, so the stream can scroll to the bottom. */
  onSent?: () => void;
}

/**
 * The message input.
 *
 * Auto-grows to a cap, persists a draft, throttles typing announcements, and
 * submits on Enter with Shift+Enter for a newline — the conventions a chat user
 * arrives already expecting.
 */
/**
 * How long to wait after a keystroke before asking the server for matching users.
 * Emoji are matched locally and need no delay.
 */
const MENTION_DEBOUNCE = 180;

export default class ChatComposer extends Component<ChatComposerAttrs> {
  private textarea: HTMLTextAreaElement | null = null;
  private joining = false;
  private sending = false;

  /** Seconds left before this channel will accept another message from us. */
  private cooldown = 0;
  private cooldownTimer: number | null = null;

  /** Last `slowModeRemaining` seen, so a change can be told from a redraw. */
  private lastServerCooldown = -1;

  /** Last `slowModeSeconds` seen. -1 so the first sync always runs. */
  private lastSlowModeWindow = -1;
  private uploading = false;

  /** Id of the reply/edit target the cursor was last moved for. */
  private focusedContext: string | null = null;

  // ── Autocomplete ───────────────────────────────────────────────────────────
  private suggestions: Suggestion[] = [];
  private activeSuggestion = 0;
  /** The `@foo` / `:smi` fragment being completed, as [start, end) in the value. */
  private trigger: {
    type: "@" | ":";
    start: number;
    end: number;
    term: string;
  } | null = null;
  private mentionTimer: number | null = null;
  /** Discards a slower earlier user search whose results arrived out of order. */
  private mentionSequence = 0;

  onremove(): void {
    if (this.mentionTimer !== null) window.clearTimeout(this.mentionTimer);

    this.stopCooldown();
  }

  oninit(vnode: Mithril.Vnode<ChatComposerAttrs>): void {
    super.oninit(vnode);

    this.syncSlowMode();

    // Warm the custom-emoji set so the `:` list and the picker are complete on
    // first use. A no-op when Flamoji is not installed.
    loadCustomEmoji();
  }

  /**
   * Reconciles the local countdown with the channel's slow-mode rule.
   *
   * Both directions matter, and they are not symmetrical.
   *
   * Turning slow mode *on* is the server's word to adopt: `slowModeRemaining`
   * says how long this actor has left, and an already-open composer has to pick
   * it up or the new rule binds them only after they navigate away and back.
   * It is read on a *change* rather than every draw — it is a snapshot from the
   * last channel read, not a ticking value, so comparing it each time would
   * restart the countdown from the same stale number and it would never reach
   * zero.
   *
   * Turning slow mode *off* has to release whoever is mid-wait. The local
   * countdown exists only because of the window it was started from, so it
   * cannot outlive that window: with no window there is nothing left to wait
   * for, and a narrowed one caps what is left. This is the half that was
   * missing — a "never shorten" guard meant to protect the local countdown from
   * a stale snapshot also kept enforcing a rule that had just been withdrawn.
   *
   * Narrowing is capped rather than recomputed: the broadcast moves the window
   * immediately while the per-actor refetch is still in flight, so `remaining`
   * is briefly stale. Capping at the new window is never more permissive than
   * the server, and the next send resyncs it exactly.
   */
  protected syncSlowMode(): void {
    const channel = this.attrs.channel;
    const window = Number(channel.slowModeSeconds() ?? 0);
    const remaining = Number(channel.slowModeRemaining() ?? 0);

    if (
      window === this.lastSlowModeWindow &&
      remaining === this.lastServerCooldown
    ) {
      return;
    }

    const windowChanged = window !== this.lastSlowModeWindow;

    this.lastSlowModeWindow = window;
    this.lastServerCooldown = remaining;

    if (windowChanged && this.cooldown > window) {
      if (window <= 0) {
        this.stopCooldown();
      } else {
        this.startCooldown(window);
      }

      return;
    }

    // Capped at the window for the same reason it is capped above: while the
    // refetch is in flight `remaining` still describes the *old* rule, and a
    // window that was just narrowed must not be lengthened by a stale figure.
    // The server can never require longer than the window itself.
    const wait = Math.min(remaining, window);

    if (wait > this.cooldown) {
      this.startCooldown(wait);
    }
  }

  oncreate(vnode: Mithril.VnodeDOM<ChatComposerAttrs>): void {
    super.oncreate(vnode);

    this.textarea = vnode.dom.querySelector(".ChatComposer-input");
    this.resize();
  }

  onupdate(vnode: Mithril.VnodeDOM<ChatComposerAttrs>): void {
    super.onupdate(vnode);

    this.syncSlowMode();
    this.focusOnNewContext();
  }

  /**
   * Puts the cursor in the input when a reply or edit is staged.
   *
   * Handled here rather than at each call site because a reply can be started from
   * three places — the channel's message row, the thread panel, and arrow-up — and
   * only the composer knows where its own textarea is. Clicking Reply and then
   * having to click the box before typing is a small thing that happens on every
   * single reply.
   *
   * Fires only on a *change* of target: `onupdate` runs on every redraw, including
   * one per incoming message, and focusing on each of those would seize the cursor
   * from someone reading, or scroll a phone's keyboard open unprompted.
   */
  protected focusOnNewContext(): void {
    const target = this.editing() ?? this.replyingTo();
    const id = target ? String(target.id()) : null;

    if (id === this.focusedContext) return;

    this.focusedContext = id;

    if (!id || !this.textarea) return;

    this.textarea.focus();

    // Caret at the end. An edit pre-fills the box with the existing text, and
    // landing at position zero means typing inserts before it.
    const end = this.textarea.value.length;
    this.textarea.setSelectionRange(end, end);
  }

  view(): Mithril.Children {
    const { channel, state, threadId } = this.attrs;

    if (!channel.canPostMessage()) {
      return this.frozen(channel);
    }

    // Slow mode is a "not now", so it reads like the other "not now" states
    // rather than as a disabled button with a number in it. A greyed-out send
    // still invites clicking, and a textarea that accepts typing it will not
    // deliver is worse than no textarea: the draft survives in state either way,
    // so nothing is lost by standing the box down until the wait is over.
    if (this.cooldown > 0) {
      return this.slowed();
    }

    const value = state.draft(Number(channel.id()), threadId ?? null);
    const max = resolveMaxMessageLength(channel);
    const remaining = max - value.length;

    return (
      <div className="ChatComposer">
        {this.contextBar()}
        {this.pendingAttachments()}

        <ChatAutocomplete
          suggestions={this.suggestions}
          activeIndex={this.activeSuggestion}
          onSelect={(suggestion: Suggestion) =>
            this.applySuggestion(suggestion)
          }
          onHover={(index: number) => {
            this.activeSuggestion = index;
          }}
        />

        <div className="ChatComposer-shell">
          <textarea
            className="ChatComposer-input"
            placeholder={this.placeholder(channel)}
            value={value}
            rows={1}
            // Deliberately no `maxlength`: the browser enforces it by silently
            // dropping the tail of a paste, so a long quote arrives cut with no
            // sign that anything went missing. Over-length text is allowed into
            // the box, the counter goes negative, and `submit()` explains.
            oninput={(e: Event) => this.onInput(e)}
            onkeydown={(e: KeyboardEvent) => this.onKeyDown(e)}
            disabled={this.sending}
            aria-label={this.placeholder(channel)}
          />

          {/* Controls on their own row under the text, not in a column beside
              it. Beside it they took a strip the full height of the composer —
              so a long message wrapped early and left a tall empty band down
              the right-hand side, and the text never used the box it was
              typed into. Below, the textarea spans the whole width. */}
          <div className="ChatComposer-bar">
            {/* Only surfaced near the limit — a permanent counter is noise.
                Past it the number goes negative and takes the error colour:
                that is the state in which the send button refuses, so it has
                to look different from "nearly there" rather than just
                smaller. */}
            {remaining <= 200 ? (
              <div
                className={classList("ChatComposer-counter", {
                  "ChatComposer-counter--warning":
                    remaining <= 20 && remaining >= 0,
                  "ChatComposer-counter--over": remaining < 0,
                })}
                aria-live="polite"
              >
                {remaining}
              </div>
            ) : null}

            <div className="ChatComposer-tools">
              {/* Both have to hold: the forum-wide setting, and this actor's
                permission. Checking only the setting drew a paperclip for people
                the upload endpoint would refuse. */}
              {app.forum.attribute("ramon-chat.allowUploads") &&
              app.forum.attribute("canUploadChatFiles") ? (
                <>
                  <input
                    type="file"
                    className="ChatComposer-fileInput"
                    style={{ display: "none" }}
                    multiple
                    onchange={(e: Event) => this.onFilesPicked(e)}
                  />
                  <Button
                    className="ChatComposer-tool"
                    icon="fas fa-paperclip"
                    title={app.translator.trans(
                      "ramon-chat.forum.composer.attach",
                      {},
                      true,
                    )}
                    disabled={this.uploading || this.sending}
                    onclick={() => this.pickFiles()}
                  />
                </>
              ) : null}

              {/* Only when ramon/stickers is actually installed. The component is
                resolved from Flarum's export registry at runtime, so this is a
                button that appears rather than a dependency that must be met. */}
              {stickersAvailable() ? (
                // A plain button, not `Button` with an `icon` attr: that attr takes a
                // Font Awesome class name, and this icon is an inline SVG so it
                // matches the one on the discussion composer exactly.
                <button
                  type="button"
                  className="ChatComposer-tool"
                  title={stickerLabel()}
                  aria-label={stickerLabel()}
                  disabled={this.sending}
                  onclick={(e: Event) => this.toggleStickers(e)}
                >
                  {stickerIcon()}
                </button>
              ) : null}

              {/* Flamoji's own picker button, hosted here. It draws nothing
                  when that extension is absent, so this is a tool that appears
                  rather than a dependency that must be met. Deliberately not
                  gated on a chat permission: it only types into the box, and
                  what may be sent is already decided by the send button. */}
              {flamojiPickerButton(
                (text: string) => this.insertAtCursor(text),
                this.sending,
              )}
            </div>

            <button
              type="button"
              className="ChatComposer-send"
              // Names the keystroke, since which one sends is a forum setting and
              // the button is the only place a member would find out.
              title={this.sendHint()}
              disabled={
                this.sending ||
                (!value.trim() && state.pendingUploads.length === 0)
              }
              onclick={() => this.submit()}
            >
              {this.sending ? (
                <LoadingIndicator display="inline" size="small" />
              ) : (
                <i className="fas fa-paper-plane" />
              )}
            </button>
          </div>
        </div>
      </div>
    );
  }

  /**
   * Why you cannot type here.
   *
   * The reasons are checked most-specific first, because they are not equivalent:
   * "this channel is closed" told someone in a perfectly open announcement channel
   * the wrong thing entirely, and left them with no idea that reading was all that
   * was ever on offer.
   */
  /**
   * What stands in for the composer during a slow-mode wait.
   *
   * Shaped like `frozen()` because it means the same thing to the reader — the
   * channel is not taking a message from you right now — and an hourglass rather
   * than a padlock because nothing is locked and nothing went wrong: the wait
   * ends on its own, and the count says when.
   */
  protected slowed(): Mithril.Children {
    return (
      <div className="ChatChannel-frozen ChatChannel-frozen--slow">
        <i className="fas fa-hourglass-half" aria-hidden="true" />
        <span>
          {app.translator.trans("ramon-chat.forum.composer.slow_mode_wait", {
            duration: humanDuration(this.cooldown),
          })}
        </span>
      </div>
    );
  }

  protected frozen(channel: Channel): Mithril.Children {
    // Not a member. Checked before the read-only reasons because it is the only one
    // the reader can do something about, and telling them the channel is closed when
    // it is merely unjoined would be the same mistake as the announcement case.
    if (
      !channel.isArchived() &&
      !channel.isClosed() &&
      channel.postPermission() !== "moderators" &&
      !channel.isFollowing() &&
      channel.canJoin()
    ) {
      return (
        <div className="ChatChannel-frozen ChatChannel-frozen--join">
          <span>
            {app.translator.trans("ramon-chat.forum.channel.join_to_post")}
          </span>

          <Button
            className="Button Button--primary"
            icon="fas fa-right-to-bracket"
            loading={this.joining}
            onclick={() => this.join(channel)}
          >
            {app.translator.trans("ramon-chat.forum.channel.join")}
          </Button>
        </div>
      );
    }

    const key = channel.isArchived()
      ? "ramon-chat.forum.channel.archived"
      : channel.isClosed()
        ? "ramon-chat.forum.channel.closed"
        : channel.postPermission() === "moderators"
          ? "ramon-chat.forum.channel.moderators_only"
          : "ramon-chat.forum.composer.placeholder_closed";

    // A megaphone, not a padlock: an announcement channel is not locked, it is
    // read-only by design, and the lock icon reads as something having gone wrong.
    const icon = key.endsWith("moderators_only")
      ? "fas fa-bullhorn"
      : "fas fa-lock";

    return (
      <div className="ChatChannel-frozen">
        <i className={icon} aria-hidden="true" />
        <span>{app.translator.trans(key)}</span>
      </div>
    );
  }

  /**
   * Joins the channel so the composer can appear.
   *
   * Refetches the channel rather than assuming success: `canPostMessage` is the
   * server's answer, and a join that succeeded for a channel that has since been
   * closed should still leave the composer hidden.
   */
  protected async join(channel: Channel): Promise<void> {
    this.joining = true;
    m.redraw();

    try {
      await app.request({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-channels/${channel.id()}/join`,
        body: { data: { attributes: {} } },
      });

      const fresh = await app.store.find("chat-channels", String(channel.id()));

      channel.pushAttributes({
        isFollowing: true,
        canPostMessage: (fresh as any)?.canPostMessage?.() ?? true,
      });
    } catch (e: any) {
      app.alerts.show(
        { type: "error" },
        e?.response?.errors?.[0]?.detail ??
          app.translator.trans("ramon-chat.forum.channel.join_failed"),
      );
    } finally {
      this.joining = false;
      m.redraw();
    }
  }

  /**
   * This composer's own reply/edit target.
   *
   * The channel and an open thread each render a composer over the same state, so
   * the context is looked up by scope — a thread edit must not put the channel
   * composer into edit mode.
   */
  protected replyingTo() {
    return this.attrs.state.replyingTo(
      Number(this.attrs.channel.id()),
      this.attrs.threadId ?? null,
    );
  }

  /** Whether the staged reply was started as "reply in thread". */
  protected branching(): boolean {
    return this.attrs.state.branchingFrom(
      Number(this.attrs.channel.id()),
      this.attrs.threadId ?? null,
    );
  }

  protected editing() {
    return this.attrs.state.editing(
      Number(this.attrs.channel.id()),
      this.attrs.threadId ?? null,
    );
  }

  protected placeholder(channel: Channel): string {
    if (this.attrs.threadId) {
      return app.translator.trans(
        "ramon-chat.forum.composer.placeholder_thread",
        {},
        true,
      );
    }

    return app.translator.trans(
      "ramon-chat.forum.composer.placeholder",
      {
        channel: channel.displayName(),
      },
      true,
    );
  }

  /** The send button's tooltip, naming the keystroke that actually sends. */
  protected sendHint(): string {
    return app.translator.trans(
      sendsOnCtrlEnter()
        ? "ramon-chat.forum.composer.send_ctrl_enter"
        : "ramon-chat.forum.composer.send_enter",
      {},
      true,
    );
  }

  /** Reply / edit context strip above the input. */
  protected contextBar(): Mithril.Children {
    const editingTarget = this.editing();
    const target = editingTarget ?? this.replyingTo();

    if (!target) return null;

    const editing = Boolean(editingTarget);

    // A branch and a reply stage identically, and now send differently — so the
    // strip has to say which one is about to happen. Same icon the thread action
    // uses, so the two read as the same feature.
    const branching = !editing && this.branching();

    return (
      <div className="ChatComposer-context">
        <i
          className={classList({
            "fas fa-pencil": editing,
            "fas fa-comments": branching,
            "fas fa-reply": !editing && !branching,
          })}
          aria-hidden="true"
        />
        <span className="ChatComposer-context-label">
          {editing
            ? app.translator.trans("ramon-chat.forum.composer.editing")
            : app.translator.trans(
                branching
                  ? "ramon-chat.forum.message.starting_thread"
                  : "ramon-chat.forum.message.replying_to",
                { username: authorName(target) },
              )}
        </span>
        <span className="ChatComposer-context-preview">
          {messagePreview(target, 80)}
        </span>
        <Button
          className="ChatComposer-tool"
          icon="fas fa-times"
          title={app.translator.trans(
            "ramon-chat.forum.composer.cancel_edit",
            {},
            true,
          )}
          onclick={() => this.cancelContext()}
        />
      </div>
    );
  }

  protected pendingAttachments(): Mithril.Children {
    const { state } = this.attrs;

    if (state.pendingUploads.length === 0 && !this.uploading) return null;

    return (
      <div className="ChatComposer-pending">
        {state.pendingUploads.map((upload) => (
          <div key={upload.id()} className="ChatUploads-file">
            <i className="fas fa-paperclip" aria-hidden="true" />
            <span className="ChatUploads-file-name">{upload.fileName()}</span>
            <Button
              className="ChatComposer-tool"
              icon="fas fa-times"
              onclick={() => this.removeUpload(Number(upload.id()))}
            />
          </div>
        ))}
        {this.uploading ? (
          <LoadingIndicator display="inline" size="small" />
        ) : null}
      </div>
    );
  }

  // ── Input handling ─────────────────────────────────────────────────────────

  protected onInput(e: Event): void {
    const target = e.target as HTMLTextAreaElement;
    const { channel, state, threadId } = this.attrs;

    state.setDraft(Number(channel.id()), target.value, threadId ?? null);
    state.announceTyping(Number(channel.id()));

    this.detectTrigger(target);
    this.resize();
  }

  // ── Autocomplete ───────────────────────────────────────────────────────────

  /**
   * Looks for an `@name` or `:emoji` fragment immediately before the caret.
   *
   * Anchored on a word boundary so an email address or a `http://` does not open
   * the list, and closed as soon as the fragment stops matching — the trigger has
   * to disappear on its own rather than needing an explicit dismissal.
   */
  protected detectTrigger(el: HTMLTextAreaElement): void {
    const caret = el.selectionStart ?? 0;
    const before = el.value.slice(0, caret);

    const match = /(^|[\s(])([@:])([\p{L}\p{N}_+-]*)$/u.exec(before);

    if (!match) {
      this.closeAutocomplete();

      return;
    }

    const [, , symbol, term] = match;
    const type = symbol as "@" | ":";

    // A bare `:` matches nearly the whole emoji map, and a bare `@` every user;
    // neither is a useful list, so wait for something to filter on. `@` opens at
    // once because @here/@all are worth offering immediately.
    if (type === ":" && term.length < 2) {
      this.closeAutocomplete();

      return;
    }

    this.trigger = {
      type,
      start: caret - term.length - 1,
      end: caret,
      term,
    };

    if (type === ":") {
      // Custom emoji first, and on their own budget rather than competing for
      // the twelve slots. There are few of them, they are forum-specific, and a
      // member who typed `:kap` almost certainly means the one their forum
      // added — not `kappa`'s nearest Unicode neighbour.
      const custom: Suggestion[] = searchCustomEmoji(term, 4).map((entry) => ({
        key: "flamoji-" + entry.name,
        insert: entry.insert,
        label: ":" + entry.name + ":",
        emoji: customEmojiImage(entry, "ChatAutocomplete-flamoji"),
        hint: entry.title !== entry.name ? entry.title : null,
      }));

      const unicode: Suggestion[] = searchEmoji(term, 12).map((entry) => ({
        key: "emoji-" + entry.name,
        insert: ":" + entry.name + ":",
        label: ":" + entry.name + ":",
        emoji: entry.unicode,
      }));

      // A custom emoji may share a name with a Unicode one, and the formatter
      // gives the custom one precedence — so offering both would let someone
      // pick a glyph they will not get.
      const taken = new Set(custom.map((entry) => entry.label));

      this.suggestions = [
        ...custom,
        ...unicode.filter((entry) => !taken.has(entry.label)),
      ];

      this.activeSuggestion = 0;

      if (this.suggestions.length === 0) this.closeAutocomplete();

      return;
    }

    this.suggestUsers(term);
  }

  protected suggestUsers(term: string): void {
    const channelWide: Suggestion[] = [];

    if (app.forum.attribute<boolean>("canMentionChatChannelWide")) {
      for (const name of ["here", "all"]) {
        if (name.startsWith(term.toLowerCase())) {
          channelWide.push({
            key: "wide-" + name,
            insert: "@" + name,
            label: "@" + name,
            icon: "fas fa-bullhorn",
            hint: app.translator.trans(
              `ramon-chat.forum.composer.mention_${name}`,
              {},
              true,
            ),
          });
        }
      }
    }

    this.suggestions = channelWide;
    this.activeSuggestion = 0;

    if (this.mentionTimer !== null) window.clearTimeout(this.mentionTimer);

    if (term.length < 1) return;

    const mine = ++this.mentionSequence;

    this.mentionTimer = window.setTimeout(() => {
      app.store
        .find<User[]>("users", {
          // Scoped to the channel: mentioning someone who is not in it notifies
          // them about a conversation they cannot open, and in a private channel
          // the unscoped list named people who cannot see it exists.
          filter: { q: term, chatChannel: Number(this.attrs.channel.id()) },
          page: { limit: 6 },
        })
        .then((results) => {
          // A stale response must not replace a newer one, and must not reopen a
          // list the user has already dismissed or typed past.
          if (mine !== this.mentionSequence || this.trigger?.type !== "@")
            return;

          this.suggestions = [
            ...channelWide,
            ...(Array.isArray(results) ? results : []).map((user) => ({
              key: "user-" + user.id(),
              insert: "@" + (user.slug() ?? user.username()),
              label: user.displayName(),
              hint: "@" + user.username(),
              user,
            })),
          ];

          this.activeSuggestion = 0;
          m.redraw();
        })
        .catch(() => {
          // Keep whatever @here/@all we already have; a failed lookup is not
          // worth an error for something this incidental.
        });
    }, MENTION_DEBOUNCE);
  }

  protected applySuggestion(suggestion: Suggestion): void {
    const el = this.textarea;
    const { channel, state, threadId } = this.attrs;

    if (!el || !this.trigger) return;

    const { start, end } = this.trigger;
    const value =
      el.value.slice(0, start) + suggestion.insert + " " + el.value.slice(end);
    const caret = start + suggestion.insert.length + 1;

    state.setDraft(Number(channel.id()), value, threadId ?? null);
    this.closeAutocomplete();

    // The value is redrawn from the draft, so the caret has to be restored after
    // Mithril writes it back or it jumps to the end of the text.
    m.redraw.sync();
    el.focus();
    el.setSelectionRange(caret, caret);

    this.resize();
  }

  protected closeAutocomplete(): void {
    this.trigger = null;
    this.suggestions = [];
    this.activeSuggestion = 0;
  }

  /** Whether the list is open and should own the arrow keys, Enter and Escape. */
  protected autocompleteOpen(): boolean {
    return this.trigger !== null && this.suggestions.length > 0;
  }

  protected onKeyDown(e: KeyboardEvent): void {
    // The suggestion list owns these keys while it is open, and must be checked
    // before anything else: Enter here means "accept", not "send", and ArrowUp
    // means "previous suggestion", not "edit my last message".
    if (this.autocompleteOpen()) {
      const count = this.suggestions.length;

      if (e.key === "ArrowDown" || e.key === "ArrowUp") {
        e.preventDefault();

        const step = e.key === "ArrowDown" ? 1 : -1;
        this.activeSuggestion = (this.activeSuggestion + step + count) % count;

        return;
      }

      // Ctrl/Cmd+Enter is "send", never "accept": in Ctrl+Enter mode a plain
      // Enter still belongs to the list, but the send shortcut has to reach the
      // send path or the list becomes a trap for anyone who used it.
      if (e.key === "Enter" && !e.ctrlKey && !e.metaKey) {
        e.preventDefault();
        this.applySuggestion(this.suggestions[this.activeSuggestion]);

        return;
      }

      if (e.key === "Tab") {
        e.preventDefault();
        this.applySuggestion(this.suggestions[this.activeSuggestion]);

        return;
      }

      if (e.key === "Escape") {
        e.preventDefault();
        this.closeAutocomplete();

        return;
      }
    }

    // Which keystroke sends is the forum's choice. Default: Enter sends and
    // Shift+Enter breaks the line, the chat convention. With
    // `send_with_ctrl_enter` on, Enter breaks the line and only Ctrl/Cmd+Enter
    // sends — for forums whose members write long messages and lose them to a
    // stray Enter. Cmd is accepted alongside Ctrl because on macOS the modifier
    // for this shortcut is Cmd, and a mac user pressing Ctrl+Enter means the
    // same thing.
    if (e.key === "Enter" && !e.altKey) {
      const withModifier = e.ctrlKey || e.metaKey;

      if (sendsOnCtrlEnter() ? withModifier : !e.shiftKey && !withModifier) {
        e.preventDefault();
        this.submit();

        return;
      }
    }

    if (e.key === "Escape") {
      this.cancelContext();
    }

    // Arrow-up on an empty composer edits your last message — a chat convention.
    if (e.key === "ArrowUp") {
      const { channel, state } = this.attrs;
      const draft = state.draft(
        Number(channel.id()),
        this.attrs.threadId ?? null,
      );

      if (draft === "" && !this.editing()) {
        // The thread panel's own window when this composer is scoped to a thread,
        // so arrow-up cannot reach back into the channel from inside a thread.
        const window = this.attrs.threadId
          ? state.threadStream(this.attrs.threadId).messages
          : (state.streams[Number(channel.id())]?.messages ?? []);

        const mine = [...window].reverse().find((msg) => msg.canEdit());

        if (mine) {
          e.preventDefault();
          this.startEditing(mine);
        }
      }
    }
  }

  /** Grows the textarea with its content, up to the CSS max-height. */
  protected resize(): void {
    const el = this.textarea;

    if (!el) return;

    el.style.height = "auto";
    el.style.height = `${el.scrollHeight}px`;
  }

  protected startEditing(message: any): void {
    const { channel, state, threadId } = this.attrs;

    state.setEditing(Number(channel.id()), message, threadId ?? null);
    state.setDraft(
      Number(channel.id()),
      message.content() ?? "",
      threadId ?? null,
    );

    this.textarea?.focus();
    m.redraw();
  }

  protected cancelContext(): void {
    const { channel, state, threadId } = this.attrs;

    if (this.editing()) {
      state.clearDraft(Number(channel.id()), threadId ?? null);
    }

    state.clearContext(Number(channel.id()), threadId ?? null);
    m.redraw();
  }

  // ── Submit ─────────────────────────────────────────────────────────────────

  /**
   * Opens the sticker picker, or closes it if this button opened it.
   *
   * The chosen shortcode goes in at the caret like any other typed text, so it is
   * still editable and still part of the draft — the message is only rendered
   * into a sticker by the formatter, on send.
   */
  protected toggleStickers(e: Event): void {
    e.preventDefault();
    e.stopPropagation();

    if (isStickerPickerOpen()) {
      closeStickerPicker();

      return;
    }

    const trigger =
      (e.currentTarget as HTMLElement)?.closest(".ChatComposer-tool") ?? null;

    openStickerPicker(trigger as HTMLElement | null, (text: string) =>
      this.insertAtCursor(text),
    );
  }

  /**
   * Inserts text where the caret is, rather than appending.
   *
   * The composer keeps its value in the draft rather than in the DOM, so both
   * have to be updated: the textarea for the caret position the user can see, and
   * the draft for what is actually sent.
   */
  protected insertAtCursor(text: string): void {
    const { channel, state, threadId } = this.attrs;
    const channelId = Number(channel.id());
    const current = state.draft(channelId, threadId ?? null);

    if (!this.textarea) {
      state.setDraft(channelId, current + text, threadId ?? null);
      m.redraw();

      return;
    }

    const start = this.textarea.selectionStart ?? current.length;
    const end = this.textarea.selectionEnd ?? start;
    const next = current.slice(0, start) + text + current.slice(end);

    state.setDraft(channelId, next, threadId ?? null);

    m.redraw.sync();

    // Restored after the redraw, or the caret jumps to the end of the box.
    this.textarea.focus();
    this.textarea.setSelectionRange(start + text.length, start + text.length);

    this.resize();
  }

  /**
   * Starts (or refreshes) the slow-mode countdown.
   *
   * Driven by a one-second interval rather than computed on each redraw: nothing
   * else would cause a redraw while the user waits, so the number would sit
   * frozen at whatever it read when they last typed.
   *
   * The server is the authority. This only mirrors what it already told us, so a
   * reload — or a second tab — still shows the wait rather than offering a send
   * that is refused.
   */
  protected startCooldown(seconds: number): void {
    this.stopCooldown();

    if (seconds <= 0) return;

    this.cooldown = seconds;

    this.cooldownTimer = window.setInterval(() => {
      this.cooldown -= 1;

      if (this.cooldown <= 0) this.stopCooldown();

      m.redraw();
    }, 1000);
  }

  protected stopCooldown(): void {
    if (this.cooldownTimer !== null) {
      window.clearInterval(this.cooldownTimer);
      this.cooldownTimer = null;
    }

    this.cooldown = 0;
  }

  protected async submit(): Promise<void> {
    const { channel, state, threadId, onSent } = this.attrs;

    if (this.sending) return;

    const channelId = Number(channel.id());
    const content = state.draft(channelId, threadId ?? null);

    if (!content.trim() && state.pendingUploads.length === 0) return;

    // Refused here rather than by the server, and without touching the draft:
    // the text stays in the box so it can be shortened instead of retyped. The
    // server checks the same limit — this only saves the round trip and gives a
    // count the API error cannot (it says "too long", not "by how much").
    const max = resolveMaxMessageLength(channel);

    if (content.length > max) {
      app.modal.show(MessageTooLongModal, { length: content.length, max });

      return;
    }

    // Captured before the box is disabled. Disabling a focused element blurs it,
    // and re-enabling does not hand focus back — which is why sending a message
    // meant clicking the box again before the next one could be typed. Restoring
    // it unconditionally would be wrong: someone who tabbed away, or opened the
    // emoji picker, while the request was in flight should keep the cursor where
    // they put it. `contains` covers both ways in, the textarea for an Enter and
    // the send button for a click.
    const hadFocus = Boolean(
      this.element && this.element.contains(document.activeElement),
    );

    this.sending = true;

    // Clear before the request resolves so typing can continue immediately.
    state.clearDraft(channelId, threadId ?? null);
    this.resize();

    const editing = this.editing();
    const replyingTo = this.replyingTo();

    try {
      if (editing) {
        const payload = await app.request<any>({
          method: "PATCH",
          url: `${app.forum.attribute("apiUrl")}/chat-messages/${editing.id()}`,
          body: { data: { attributes: { content } } },
        });

        app.store.pushPayload(payload);
        state.setEditing(channelId, null, threadId ?? null);
      } else {
        await state.send(channelId, content, {
          threadId: threadId ?? null,
          replyToId: replyingTo ? Number(replyingTo.id()) : null,
          // Only when the reply was staged by "reply in thread". This used to be
          // inferred from the reply being branchable at all, which made every
          // ordinary reply in a threading-enabled channel open a thread and left
          // no way to simply reply.
          //
          // The rest still guards it: only from the channel composer, and only
          // when the target can actually be branched — `createThread` inside a
          // thread would ask for a nested one, which the policy refuses.
          createThread:
            state.branchingFrom(channelId, threadId ?? null) &&
            Boolean(replyingTo) &&
            Boolean(channel.threadingEnabled()) &&
            !threadId &&
            Boolean(replyingTo?.canCreateThread()),
        });
      }

      // The channel's own cooldown, restarted from what the send just consumed.
      // Editing an existing message does not: slow mode is about how often you
      // speak, not how often you correct yourself.
      //
      // `slowModeRemaining` is exemption-aware and so covers the load path, but
      // this one reads the channel's raw setting — without the same check, a
      // holder of `bypassSlowMode` sends once and is then locked out for the full
      // window by the interface alone, while the server would have taken the next
      // message happily.
      if (!editing && !app.forum.attribute("canBypassChatSlowMode")) {
        this.startCooldown(Number(channel.slowModeSeconds() ?? 0));
      }

      onSent?.();
    } catch (e: any) {
      // Hand the text back rather than losing it, and surface why.
      state.setDraft(channelId, content, threadId ?? null);

      const detail = e?.response?.errors?.[0]?.detail;

      app.alerts.show(
        { type: "error" },
        detail ??
          app.translator.trans(
            "ramon-chat.forum.composer.send_failed",
            {},
            true,
          ),
      );
    } finally {
      this.sending = false;

      // Sync, so the textarea is enabled again by the time focus is asked for —
      // calling focus() on a still-disabled element does nothing at all.
      m.redraw.sync();

      if (hadFocus) this.textarea?.focus();
    }
  }

  // ── Uploads ────────────────────────────────────────────────────────────────

  protected pickFiles(): void {
    (
      this.element.querySelector(
        ".ChatComposer-fileInput",
      ) as HTMLInputElement | null
    )?.click();
  }

  protected async onFilesPicked(e: Event): Promise<void> {
    const input = e.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);

    // Reset immediately so picking the same file twice still fires a change.
    input.value = "";

    if (files.length === 0) return;

    this.uploading = true;
    m.redraw();

    for (const file of files) {
      try {
        const body = new FormData();
        body.append("file", file);
        // Where this is going to be posted. The server uses it to put a file
        // meant for a private channel on the private disk from the start, rather
        // than on the public one and moving it on send. Sending still enforces
        // the rule — this is the hint, not the gate.
        body.append("channelId", String(this.attrs.channel.id()));

        const payload = await app.request<any>({
          method: "POST",
          url: `${app.forum.attribute("apiUrl")}/chat/uploads`,
          body,
          serialize: (raw: any) => raw,
        });

        const upload = app.store.pushPayload<any>(payload);
        this.attrs.state.pendingUploads.push(upload);
      } catch (err: any) {
        const detail = err?.response?.errors?.[0]?.detail;

        app.alerts.show(
          { type: "error" },
          detail ??
            app.translator.trans(
              "ramon-chat.forum.composer.upload_failed",
              {},
              true,
            ),
        );
      }
    }

    this.uploading = false;
    m.redraw();
  }

  protected removeUpload(id: number): void {
    const { state } = this.attrs;

    state.pendingUploads = state.pendingUploads.filter(
      (u) => Number(u.id()) !== id,
    );

    // Discard it server-side too, so a cancelled attachment does not wait for the
    // retention sweep.
    app
      .request({
        method: "DELETE",
        url: `${app.forum.attribute("apiUrl")}/chat-uploads/${id}`,
      })
      .catch(() => {});
  }
}
