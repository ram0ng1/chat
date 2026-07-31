import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import username from "flarum/common/helpers/username";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import type User from "flarum/common/models/User";
import type ChatState from "../state/ChatState";
import ChatAutocomplete from "./ChatAutocomplete";
import type { Suggestion } from "./ChatAutocomplete";
import { searchEmoji } from "../utils/emoji";
import { messagePreview } from "../utils/preview";
import { humanDuration } from "../utils/duration";
import { authorName } from "../utils/bot";
import {
  stickersAvailable,
  stickerIcon,
  stickerLabel,
  openStickerPicker,
  close as closeStickerPicker,
  isStickerPickerOpen,
} from "../utils/stickers";

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

    this.startCooldown(Number(this.attrs.channel.slowModeRemaining() ?? 0));
  }

  oncreate(vnode: Mithril.VnodeDOM<ChatComposerAttrs>): void {
    super.oncreate(vnode);

    this.textarea = vnode.dom.querySelector(".ChatComposer-input");
    this.resize();
  }

  onupdate(vnode: Mithril.VnodeDOM<ChatComposerAttrs>): void {
    super.onupdate(vnode);

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
    const max = Number(
      app.forum.attribute("ramon-chat.maxMessageLength") ?? 3000,
    );
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
            maxlength={max}
            oninput={(e: Event) => this.onInput(e)}
            onkeydown={(e: KeyboardEvent) => this.onKeyDown(e)}
            disabled={this.sending}
            aria-label={this.placeholder(channel)}
          />

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
          </div>

          <button
            type="button"
            className="ChatComposer-send"
            title={app.translator.trans(
              "ramon-chat.forum.composer.send",
              {},
              true,
            )}
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

        {/* Only surfaced near the limit — a permanent counter is noise. */}
        {remaining <= 200 ? (
          <div
            className={classList("ChatComposer-counter", {
              "ChatComposer-counter--warning": remaining <= 20,
            })}
          >
            {remaining}
          </div>
        ) : null}
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

  /** Reply / edit context strip above the input. */
  protected contextBar(): Mithril.Children {
    const editingTarget = this.editing();
    const target = editingTarget ?? this.replyingTo();

    if (!target) return null;

    const editing = Boolean(editingTarget);

    return (
      <div className="ChatComposer-context">
        <i
          className={editing ? "fas fa-pencil" : "fas fa-reply"}
          aria-hidden="true"
        />
        <span className="ChatComposer-context-label">
          {editing
            ? app.translator.trans("ramon-chat.forum.composer.editing")
            : app.translator.trans("ramon-chat.forum.message.replying_to", {
                username: authorName(target),
              })}
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
      this.suggestions = searchEmoji(term, 12).map((entry) => ({
        key: "emoji-" + entry.name,
        insert: ":" + entry.name + ":",
        label: ":" + entry.name + ":",
        emoji: entry.unicode,
      }));

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

      if (e.key === "Enter" || e.key === "Tab") {
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

    // Enter sends; Shift+Enter inserts a newline. On mobile the on-screen
    // keyboard's Enter should insert a newline instead, which is why this checks
    // for a real keyboard event rather than assuming.
    if (
      e.key === "Enter" &&
      !e.shiftKey &&
      !e.ctrlKey &&
      !e.metaKey &&
      !e.altKey
    ) {
      e.preventDefault();
      this.submit();

      return;
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
          // Only from the channel composer, and only when the reply target can
          // actually be branched — `createThread` inside a thread would ask for a
          // nested one, which the policy refuses.
          createThread:
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
      m.redraw();
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
