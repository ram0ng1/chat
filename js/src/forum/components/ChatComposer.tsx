import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import username from 'flarum/common/helpers/username';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import type User from 'flarum/common/models/User';
import type ChatState from '../state/ChatState';
import ChatAutocomplete from './ChatAutocomplete';
import type { Suggestion } from './ChatAutocomplete';
import { searchEmoji } from '../utils/emoji';

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
  private sending = false;
  private uploading = false;

  // ── Autocomplete ───────────────────────────────────────────────────────────
  private suggestions: Suggestion[] = [];
  private activeSuggestion = 0;
  /** The `@foo` / `:smi` fragment being completed, as [start, end) in the value. */
  private trigger: { type: '@' | ':'; start: number; end: number; term: string } | null = null;
  private mentionTimer: number | null = null;
  /** Discards a slower earlier user search whose results arrived out of order. */
  private mentionSequence = 0;

  onremove(): void {
    if (this.mentionTimer !== null) window.clearTimeout(this.mentionTimer);
  }

  oncreate(vnode: Mithril.VnodeDOM<ChatComposerAttrs>): void {
    super.oncreate(vnode);

    this.textarea = vnode.dom.querySelector('.ChatComposer-input');
    this.resize();
  }

  view(): Mithril.Children {
    const { channel, state, threadId } = this.attrs;

    if (!channel.canPostMessage()) {
      return this.frozen(channel);
    }

    const value = state.draft(Number(channel.id()), threadId ?? null);
    const max = Number(app.forum.attribute('ramon-chat.maxMessageLength') ?? 3000);
    const remaining = max - value.length;

    return (
      <div className="ChatComposer">
        {this.contextBar()}
        {this.pendingAttachments()}

        <ChatAutocomplete
          suggestions={this.suggestions}
          activeIndex={this.activeSuggestion}
          onSelect={(suggestion: Suggestion) => this.applySuggestion(suggestion)}
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
            {app.forum.attribute('ramon-chat.allowUploads') ? (
              <>
                <input
                  type="file"
                  className="ChatComposer-fileInput"
                  style={{ display: 'none' }}
                  multiple
                  onchange={(e: Event) => this.onFilesPicked(e)}
                />
                <Button
                  className="ChatComposer-tool"
                  icon="fas fa-paperclip"
                  title={app.translator.trans('ramon-chat.forum.composer.attach')}
                  disabled={this.uploading || this.sending}
                  onclick={() => this.pickFiles()}
                />
              </>
            ) : null}
          </div>

          <button
            type="button"
            className="ChatComposer-send"
            title={app.translator.trans('ramon-chat.forum.composer.send', {}, true)}
            disabled={this.sending || (!value.trim() && state.pendingUploads.length === 0)}
            onclick={() => this.submit()}
          >
            {this.sending ? <LoadingIndicator display="inline" size="small" /> : <i className="fas fa-paper-plane" />}
          </button>
        </div>

        {/* Only surfaced near the limit — a permanent counter is noise. */}
        {remaining <= 200 ? (
          <div className={classList('ChatComposer-counter', { 'ChatComposer-counter--warning': remaining <= 20 })}>
            {remaining}
          </div>
        ) : null}
      </div>
    );
  }

  protected frozen(channel: Channel): Mithril.Children {
    const key = channel.isArchived()
      ? 'ramon-chat.forum.channel.archived'
      : channel.isClosed()
        ? 'ramon-chat.forum.channel.closed'
        : 'ramon-chat.forum.composer.placeholder_closed';

    return (
      <div className="ChatChannel-frozen">
        <i className="fas fa-lock" aria-hidden="true" />
        <span>{app.translator.trans(key)}</span>
      </div>
    );
  }

  /**
   * This composer's own reply/edit target.
   *
   * The channel and an open thread each render a composer over the same state, so
   * the context is looked up by scope — a thread edit must not put the channel
   * composer into edit mode.
   */
  protected replyingTo() {
    return this.attrs.state.replyingTo(Number(this.attrs.channel.id()), this.attrs.threadId ?? null);
  }

  protected editing() {
    return this.attrs.state.editing(Number(this.attrs.channel.id()), this.attrs.threadId ?? null);
  }

  protected placeholder(channel: Channel): string {
    if (this.attrs.threadId) {
      return app.translator.trans('ramon-chat.forum.composer.placeholder_thread', {}, true);
    }

    return app.translator.trans('ramon-chat.forum.composer.placeholder', {
      channel: channel.displayName(),
    }, true);
  }

  /** Reply / edit context strip above the input. */
  protected contextBar(): Mithril.Children {
    const editingTarget = this.editing();
    const target = editingTarget ?? this.replyingTo();

    if (!target) return null;

    const editing = Boolean(editingTarget);

    return (
      <div className="ChatComposer-context">
        <i className={editing ? 'fas fa-pencil' : 'fas fa-reply'} aria-hidden="true" />
        <span className="ChatComposer-context-label">
          {editing
            ? app.translator.trans('ramon-chat.forum.composer.editing')
            : app.translator.trans('ramon-chat.forum.message.replying_to', {
                username: username(target.user()),
              })}
        </span>
        <span className="ChatComposer-context-preview">{target.content() ?? ''}</span>
        <Button
          className="ChatComposer-tool"
          icon="fas fa-times"
          title={app.translator.trans('ramon-chat.forum.composer.cancel_edit')}
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
        {this.uploading ? <LoadingIndicator display="inline" size="small" /> : null}
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
    const type = symbol as '@' | ':';

    // A bare `:` matches nearly the whole emoji map, and a bare `@` every user;
    // neither is a useful list, so wait for something to filter on. `@` opens at
    // once because @here/@all are worth offering immediately.
    if (type === ':' && term.length < 2) {
      this.closeAutocomplete();

      return;
    }

    this.trigger = {
      type,
      start: caret - term.length - 1,
      end: caret,
      term,
    };

    if (type === ':') {
      this.suggestions = searchEmoji(term, 12).map((entry) => ({
        key: 'emoji-' + entry.name,
        insert: ':' + entry.name + ':',
        label: ':' + entry.name + ':',
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

    if (app.forum.attribute<boolean>('canMentionChatChannelWide')) {
      for (const name of ['here', 'all']) {
        if (name.startsWith(term.toLowerCase())) {
          channelWide.push({
            key: 'wide-' + name,
            insert: '@' + name,
            label: '@' + name,
            icon: 'fas fa-bullhorn',
            hint: app.translator.trans(`ramon-chat.forum.composer.mention_${name}`, {}, true),
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
        .find<User[]>('users', { filter: { q: term }, page: { limit: 6 } })
        .then((results) => {
          // A stale response must not replace a newer one, and must not reopen a
          // list the user has already dismissed or typed past.
          if (mine !== this.mentionSequence || this.trigger?.type !== '@') return;

          this.suggestions = [
            ...channelWide,
            ...(Array.isArray(results) ? results : []).map((user) => ({
              key: 'user-' + user.id(),
              insert: '@' + (user.slug() ?? user.username()),
              label: user.displayName(),
              hint: '@' + user.username(),
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
    const value = el.value.slice(0, start) + suggestion.insert + ' ' + el.value.slice(end);
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

      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();

        const step = e.key === 'ArrowDown' ? 1 : -1;
        this.activeSuggestion = (this.activeSuggestion + step + count) % count;

        return;
      }

      if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        this.applySuggestion(this.suggestions[this.activeSuggestion]);

        return;
      }

      if (e.key === 'Escape') {
        e.preventDefault();
        this.closeAutocomplete();

        return;
      }
    }

    // Enter sends; Shift+Enter inserts a newline. On mobile the on-screen
    // keyboard's Enter should insert a newline instead, which is why this checks
    // for a real keyboard event rather than assuming.
    if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
      e.preventDefault();
      this.submit();

      return;
    }

    if (e.key === 'Escape') {
      this.cancelContext();
    }

    // Arrow-up on an empty composer edits your last message — a chat convention.
    if (e.key === 'ArrowUp') {
      const { channel, state } = this.attrs;
      const draft = state.draft(Number(channel.id()), this.attrs.threadId ?? null);

      if (draft === '' && !this.editing()) {
        // The thread panel's own window when this composer is scoped to a thread,
        // so arrow-up cannot reach back into the channel from inside a thread.
        const window = this.attrs.threadId
          ? state.threadStream(this.attrs.threadId).messages
          : state.streams[Number(channel.id())]?.messages ?? [];

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

    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
  }

  protected startEditing(message: any): void {
    const { channel, state, threadId } = this.attrs;

    state.setEditing(Number(channel.id()), message, threadId ?? null);
    state.setDraft(Number(channel.id()), message.content() ?? '', threadId ?? null);

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
          method: 'PATCH',
          url: `${app.forum.attribute('apiUrl')}/chat-messages/${editing.id()}`,
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

      onSent?.();
    } catch (e: any) {
      // Hand the text back rather than losing it, and surface why.
      state.setDraft(channelId, content, threadId ?? null);

      const detail = e?.response?.errors?.[0]?.detail;

      app.alerts.show(
        { type: 'error' },
        detail ?? (app.translator.trans('ramon-chat.forum.composer.send_failed', {}, true))
      );
    } finally {
      this.sending = false;
      m.redraw();
    }
  }

  // ── Uploads ────────────────────────────────────────────────────────────────

  protected pickFiles(): void {
    (this.element.querySelector('.ChatComposer-fileInput') as HTMLInputElement | null)?.click();
  }

  protected async onFilesPicked(e: Event): Promise<void> {
    const input = e.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);

    // Reset immediately so picking the same file twice still fires a change.
    input.value = '';

    if (files.length === 0) return;

    this.uploading = true;
    m.redraw();

    for (const file of files) {
      try {
        const body = new FormData();
        body.append('file', file);

        const payload = await app.request<any>({
          method: 'POST',
          url: `${app.forum.attribute('apiUrl')}/chat/uploads`,
          body,
          serialize: (raw: any) => raw,
        });

        const upload = app.store.pushPayload<any>(payload);
        this.attrs.state.pendingUploads.push(upload);
      } catch (err: any) {
        const detail = err?.response?.errors?.[0]?.detail;

        app.alerts.show(
          { type: 'error' },
          detail ?? (app.translator.trans('ramon-chat.forum.composer.upload_failed', {}, true))
        );
      }
    }

    this.uploading = false;
    m.redraw();
  }

  protected removeUpload(id: number): void {
    const { state } = this.attrs;

    state.pendingUploads = state.pendingUploads.filter((u) => Number(u.id()) !== id);

    // Discard it server-side too, so a cancelled attachment does not wait for the
    // retention sweep.
    app
      .request({
        method: 'DELETE',
        url: `${app.forum.attribute('apiUrl')}/chat-uploads/${id}`,
      })
      .catch(() => {});
  }
}
