import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import type Upload from '../../common/models/Upload';
import type Channel from '../../common/models/Channel';
import type User from 'flarum/common/models/User';
import type ChatState from '../state/ChatState';
import ChatAutocomplete from './ChatAutocomplete';
import type { Suggestion } from './ChatAutocomplete';
import { searchEmoji } from '../utils/emoji';
import { messagePreview } from '../../common/utils/preview';
import { humanDuration } from '../utils/duration';
import { resolveMaxMessageLength } from '../utils/messageLimit';
import { sendsOnCtrlEnter } from '../utils/shortcuts';
import { authorName } from '../utils/bot';
import { acceptInvitation, adoptChannelPayload, declineInvitation, invitationErrorText } from '../utils/invitations';
import { stickersAvailable, stickerIcon, stickerLabel, openStickerPicker, close as closeStickerPicker, isStickerPickerOpen } from '../utils/stickers';
import { flamojiPickerButton, loadCustomEmoji, searchCustomEmoji, customEmojiImage } from '../utils/flamoji';
import iconLabel from '../utils/iconLabel';
import { loadMessageTooLongModal } from '../utils/lazy';
import { closedNotice, watchSchedule } from '../utils/schedule';

export interface ChatComposerAttrs extends ComponentAttrs {
  channel: Channel;
  state: ChatState;
  threadId?: number | null;
  /** Chamado após enviar, para o stream rolar até o fim. */
  onSent?: () => void;
}

/**
 * Espera após a tecla antes de buscar usuários no servidor. Emojis são buscados
 * localmente, sem espera.
 */
const MENTION_DEBOUNCE = 180;

/**
 * Campo de mensagem: cresce até um limite, guarda rascunho, limita os avisos de
 * digitação e envia com Enter (Shift+Enter quebra linha).
 */
export default class ChatComposer extends Component<ChatComposerAttrs> {
  private textarea: HTMLTextAreaElement | null = null;
  private joining = false;
  private unarchiving = false;
  private sending = false;

  /** Segundos até o canal aceitar outra mensagem deste usuário. */
  private cooldown = 0;
  private cooldownTimer: number | null = null;

  /** Último `slowModeRemaining` visto, para distinguir mudança de redraw. */
  private lastServerCooldown = -1;

  /** Último `slowModeSeconds` visto. -1 para a primeira sincronização sempre rodar. */
  private lastSlowModeWindow = -1;
  private uploading = false;

  /** Alvo de resposta/edição para o qual o cursor foi movido por último. */
  private focusedContext: string | null = null;

  // ── Autocomplete ──
  private suggestions: Suggestion[] = [];
  private activeSuggestion = 0;
  /** Trecho `@foo` / `:smi` sendo completado, como [início, fim) no valor. */
  private trigger: {
    type: '@' | ':';
    start: number;
    end: number;
    term: string;
  } | null = null;
  private mentionTimer: number | null = null;
  /** Descarta uma busca de usuários antiga que chegou fora de ordem. */
  private mentionSequence = 0;

  onremove(): void {
    if (this.mentionTimer !== null) window.clearTimeout(this.mentionTimer);

    this.stopCooldown();
  }

  oninit(vnode: Mithril.Vnode<ChatComposerAttrs>): void {
    super.oninit(vnode);

    this.syncSlowMode();

    // Pré-carrega os emojis customizados. Não faz nada sem o Flamoji.
    loadCustomEmoji();
  }

  /**
   * Alinha a contagem local com o modo lento do canal.
   *
   * Ligar: adota `slowModeRemaining`, mas só quando muda; é um retrato da última
   * leitura, e reler a cada draw reiniciaria a contagem sem nunca chegar a zero.
   *
   * Desligar ou reduzir: libera ou limita a espera ao novo intervalo. Limita em vez
   * de recalcular porque o broadcast chega antes do refetch; nunca fica mais
   * permissivo que o servidor, e o próximo envio ressincroniza.
   */
  protected syncSlowMode(): void {
    const channel = this.attrs.channel;
    const window = Number(channel.slowModeSeconds() ?? 0);
    const remaining = Number(channel.slowModeRemaining() ?? 0);

    if (window === this.lastSlowModeWindow && remaining === this.lastServerCooldown) {
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

    // Limitado ao intervalo: durante o refetch `remaining` ainda é da regra antiga.
    const wait = Math.min(remaining, window);

    if (wait > this.cooldown) {
      this.startCooldown(wait);
    }
  }

  oncreate(vnode: Mithril.VnodeDOM<ChatComposerAttrs>): void {
    super.oncreate(vnode);

    this.textarea = vnode.dom.querySelector('.ChatComposer-input');
    this.resize();
  }

  onupdate(vnode: Mithril.VnodeDOM<ChatComposerAttrs>): void {
    super.onupdate(vnode);

    this.syncSlowMode();
    this.focusOnNewContext();
  }

  /**
   * Foca o campo quando uma resposta ou edição é preparada. Só quando o alvo muda:
   * o `onupdate` roda a cada redraw, e focar sempre roubaria o cursor ou abriria o
   * teclado do celular.
   */
  protected focusOnNewContext(): void {
    const target = this.editing() ?? this.replyingTo();
    const id = target ? String(target.id()) : null;

    if (id === this.focusedContext) return;

    this.focusedContext = id;

    if (!id || !this.textarea) return;

    this.textarea.focus();

    // Cursor no fim: a edição preenche o campo com o texto existente.
    const end = this.textarea.value.length;
    this.textarea.setSelectionRange(end, end);
  }

  view(): Mithril.Children {
    const { channel, state, threadId } = this.attrs;

    // Reabre ou fecha sozinho quando o horário vira.
    watchSchedule(channel);

    if (!channel.canPostMessage()) {
      return this.frozen(channel);
    }

    // No modo lento o campo dá lugar a um aviso; o rascunho fica no state.
    if (this.cooldown > 0) {
      return this.slowed();
    }

    const value = state.draft(Number(channel.id()), threadId ?? null);
    const max = resolveMaxMessageLength(channel);
    const remaining = max - value.length;

    return (
      <div className="ChatComposer">
        {this.contextBar()}
        {this.offHoursHint(channel)}
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
            // Sem `maxlength`: o navegador cortaria o fim do texto colado sem avisar.
            // O contador fica negativo e `submit()` explica.
            oninput={(e: Event) => this.onInput(e)}
            onkeydown={(e: KeyboardEvent) => this.onKeyDown(e)}
            disabled={this.sending}
            aria-label={this.placeholder(channel)}
          />

          {/* Controles numa linha abaixo do texto, para o textarea usar toda a largura. */}
          <div className="ChatComposer-bar">
            {/* Só perto do limite. Passou, fica negativo e na cor de erro. */}
            {remaining <= 200 ? (
              <div
                className={classList('ChatComposer-counter', {
                  'ChatComposer-counter--warning': remaining <= 20 && remaining >= 0,
                  'ChatComposer-counter--over': remaining < 0,
                })}
                aria-live="polite"
              >
                {remaining}
              </div>
            ) : null}

            <div className="ChatComposer-tools">
              {/* Exige a configuração do fórum e a permissão do usuário. */}
              {app.forum.attribute('ramon-chat.allowUploads') && app.forum.attribute('canUploadChatFiles') ? (
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
                    {...iconLabel(app.translator.trans('ramon-chat.forum.composer.attach', {}, true))}
                    disabled={this.uploading || this.sending}
                    onclick={() => this.pickFiles()}
                  />
                </>
              ) : null}

              {/* Só com o ramon/stickers instalado; detectado em runtime. */}
              {stickersAvailable() ? (
                // `button` simples: o ícone é SVG inline, e o attr `icon` do `Button`
                // só aceita classe do Font Awesome.
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

              {/* Botão do Flamoji; não desenha nada sem a extensão. Sem permissão própria:
                  só insere texto no campo. */}
              {flamojiPickerButton((text: string) => this.insertAtCursor(text), this.sending)}
            </div>

            <button
              type="button"
              className="ChatComposer-send"
              // Diz qual tecla envia, que depende de configuração do fórum.
              title={this.sendHint()}
              disabled={this.sending || (!value.trim() && this.pendingUploads().length === 0)}
              onclick={() => this.submit()}
            >
              {this.sending ? <LoadingIndicator display="inline" size="small" /> : <i className="fas fa-paper-plane" />}
            </button>
          </div>
        </div>
      </div>
    );
  }

  /**
   * Substitui o composer durante a espera do modo lento. Ampulheta, não cadeado:
   * nada está bloqueado, a espera acaba sozinha.
   */
  protected slowed(): Mithril.Children {
    return (
      <div className="ChatChannel-frozen ChatChannel-frozen--slow">
        <i className="fas fa-hourglass-half" aria-hidden="true" />
        <span>
          {app.translator.trans('ramon-chat.forum.composer.slow_mode_wait', {
            duration: humanDuration(this.cooldown),
          })}
        </span>
      </div>
    );
  }

  /**
   * Por que não dá para escrever aqui. Motivos do mais específico ao mais geral,
   * para um canal de avisos aberto não aparecer como "fechado".
   */
  protected frozen(channel: Channel): Mithril.Children {
    // Convidado e ainda fora: a barra serve para responder ao convite.
    if (!channel.isFollowing() && channel.isInvited() && channel.isOpen()) {
      const inviter = channel.invitedByName();

      return (
        <div className="ChatChannel-frozen ChatChannel-frozen--join ChatChannel-frozen--invited">
          <i className="fas fa-envelope-open" aria-hidden="true" />
          <span>
            {inviter
              ? app.translator.trans('ramon-chat.forum.channel.invited_by', {
                  username: inviter,
                })
              : app.translator.trans('ramon-chat.forum.channel.invited')}
          </span>

          <span className="ChatChannel-frozen-actions">
            <Button className="Button" disabled={this.joining} onclick={() => this.answerInvitation(channel, false)}>
              {app.translator.trans('ramon-chat.forum.channel.decline_invite')}
            </Button>
            <Button
              className="Button Button--primary"
              icon="fas fa-check"
              loading={this.joining}
              onclick={() => this.answerInvitation(channel, true)}
            >
              {app.translator.trans('ramon-chat.forum.channel.accept_invite')}
            </Button>
          </span>
        </div>
      );
    }

    // Não membro: antes dos motivos de só leitura, por ser o único que o leitor resolve.
    if (!channel.isArchived() && !channel.isClosed() && channel.postPermission() !== 'moderators' && !channel.isFollowing() && channel.canJoin()) {
      return (
        <div className="ChatChannel-frozen ChatChannel-frozen--join">
          <span>{app.translator.trans('ramon-chat.forum.channel.join_to_post')}</span>

          <Button className="Button Button--primary" icon="fas fa-right-to-bracket" loading={this.joining} onclick={() => this.join(channel)}>
            {app.translator.trans('ramon-chat.forum.channel.join')}
          </Button>
        </div>
      );
    }

    if (channel.isArchived()) return this.archivedNotice(channel);

    // Fora do horário, depois dos motivos permanentes: diz quando abre.
    if (!channel.isClosed() && channel.scheduleOpen() === false) {
      return (
        <div className="ChatChannel-frozen ChatChannel-frozen--schedule">
          <i className="fas fa-clock" aria-hidden="true" />
          <span>{closedNotice(channel)}</span>
        </div>
      );
    }

    const key = channel.isClosed()
      ? 'ramon-chat.forum.channel.closed'
      : channel.postPermission() === 'moderators'
        ? 'ramon-chat.forum.channel.moderators_only'
        : 'ramon-chat.forum.composer.placeholder_closed';

    // Megafone, não cadeado: canal de avisos é só leitura por natureza.
    const icon = key.endsWith('moderators_only') ? 'fas fa-bullhorn' : 'fas fa-lock';

    return (
      <div className="ChatChannel-frozen">
        <i className={icon} aria-hidden="true" />
        <span>{app.translator.trans(key)}</span>
      </div>
    );
  }

  /**
   * Quem modera escreve fora do horário; a linha lembra que os membros não podem.
   */
  protected offHoursHint(channel: Channel): Mithril.Children {
    if (channel.scheduleOpen() !== false) return null;

    return (
      <div className="ChatComposer-offHours">
        <i className="fas fa-clock" aria-hidden="true" />
        <span>{app.translator.trans('ramon-chat.forum.schedule.moderators_only_now')}</span>
      </div>
    );
  }

  /**
   * Aviso de arquivado: onde ficou a transcrição e, para quem pode, como desarquivar.
   */
  protected archivedNotice(channel: Channel): Mithril.Children {
    const discussionId = channel.archivedDiscussionId();

    return (
      <div className="ChatChannel-frozen ChatChannel-frozen--archived">
        <i className="fas fa-box-archive" aria-hidden="true" />
        <span>
          {app.translator.trans('ramon-chat.forum.channel.archived')}{' '}
          {discussionId ? (
            <Link href={app.route('discussion', { id: String(discussionId) })}>{app.translator.trans('ramon-chat.forum.channel.archived_link')}</Link>
          ) : null}
        </span>

        {channel.canUnarchive() ? (
          <span className="ChatChannel-frozen-actions">
            <Button className="Button" icon="fas fa-box-open" loading={this.unarchiving} onclick={() => this.unarchive(channel)}>
              {app.translator.trans('ramon-chat.forum.channel.unarchive')}
            </Button>
          </span>
        ) : null}
      </div>
    );
  }

  /**
   * Desarquiva o canal. Ele volta fechado, então o composer só aparece ao reabrir.
   */
  protected async unarchive(channel: Channel): Promise<void> {
    if (this.unarchiving) return;

    this.unarchiving = true;
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-channels/${channel.id()}/unarchive`,
        body: { data: { attributes: {} } },
      });

      if (payload?.data) app.store.pushPayload(payload);
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.info.save_failed'));
    } finally {
      this.unarchiving = false;
      m.redraw();
    }
  }

  /**
   * Entra no canal para o composer aparecer. A resposta traz o canal, e
   * `canPostMessage` vem do servidor.
   */
  protected async join(channel: Channel): Promise<void> {
    this.joining = true;
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-channels/${channel.id()}/join`,
        body: { data: { attributes: {} } },
      });

      if (!adoptChannelPayload(payload)) {
        channel.pushAttributes({
          isFollowing: true,
          canPostMessage: channel.isOpen(),
        });
      }

      this.attrs.state.rememberChannel(channel);
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.channel.join_failed'));
    } finally {
      this.joining = false;
      m.redraw();
    }
  }

  /**
   * Responde ao convite da barra. Aceitar devolve o canal com `canPostMessage`, e
   * o composer assume no próximo draw.
   */
  protected async answerInvitation(channel: Channel, accept: boolean): Promise<void> {
    if (!accept && !confirm(app.translator.trans('ramon-chat.forum.channel.decline_invite_confirm', {}, true))) {
      return;
    }

    this.joining = true;
    m.redraw();

    try {
      if (accept) {
        const joined = await acceptInvitation(Number(channel.id()));

        if (joined) this.attrs.state.rememberChannel(joined);
      } else {
        await declineInvitation(Number(channel.id()));
      }

      app.alerts.show(
        { type: 'success' },
        app.translator.trans(accept ? 'ramon-chat.forum.channel.invite_accepted' : 'ramon-chat.forum.channel.invite_declined')
      );
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, invitationErrorText(e, 'ramon-chat.forum.channel.invite_failed'));
    } finally {
      this.joining = false;
      m.redraw();
    }
  }

  /**
   * Alvo de resposta/edição deste composer. Canal e thread têm composers sobre o
   * mesmo state, então o contexto é buscado pelo escopo.
   */
  protected replyingTo() {
    return this.attrs.state.replyingTo(Number(this.attrs.channel.id()), this.attrs.threadId ?? null);
  }

  /** Se a resposta preparada veio de "responder em thread". */
  protected branching(): boolean {
    return this.attrs.state.branchingFrom(Number(this.attrs.channel.id()), this.attrs.threadId ?? null);
  }

  protected editing() {
    return this.attrs.state.editing(Number(this.attrs.channel.id()), this.attrs.threadId ?? null);
  }

  protected placeholder(channel: Channel): string {
    if (this.attrs.threadId) {
      return app.translator.trans('ramon-chat.forum.composer.placeholder_thread', {}, true);
    }

    return app.translator.trans(
      'ramon-chat.forum.composer.placeholder',
      {
        channel: channel.displayName(),
      },
      true
    );
  }

  /** Tooltip do botão de envio, com a tecla que envia. */
  protected sendHint(): string {
    return app.translator.trans(sendsOnCtrlEnter() ? 'ramon-chat.forum.composer.send_ctrl_enter' : 'ramon-chat.forum.composer.send_enter', {}, true);
  }

  /** Faixa de contexto de resposta/edição acima do campo. */
  protected contextBar(): Mithril.Children {
    const editingTarget = this.editing();
    const target = editingTarget ?? this.replyingTo();

    if (!target) return null;

    const editing = Boolean(editingTarget);

    // Ramificação e resposta são preparadas igual mas enviam diferente; a faixa
    // diz qual vai acontecer.
    const branching = !editing && this.branching();

    return (
      <div className="ChatComposer-context">
        <i
          className={classList({
            'fas fa-pencil': editing,
            'fas fa-comments': branching,
            'fas fa-reply': !editing && !branching,
          })}
          aria-hidden="true"
        />
        <span className="ChatComposer-context-label">
          {editing
            ? app.translator.trans('ramon-chat.forum.composer.editing')
            : app.translator.trans(branching ? 'ramon-chat.forum.message.starting_thread' : 'ramon-chat.forum.message.replying_to', {
                username: authorName(target),
              })}
        </span>
        <span className="ChatComposer-context-preview">{messagePreview(target, 80)}</span>
        <Button
          className="ChatComposer-tool"
          icon="fas fa-times"
          {...iconLabel(app.translator.trans('ramon-chat.forum.composer.cancel_edit', {}, true))}
          onclick={() => this.cancelContext()}
        />
      </div>
    );
  }

  protected pendingAttachments(): Mithril.Children {
    const pending = this.pendingUploads();

    if (pending.length === 0 && !this.uploading) return null;

    return (
      <div className="ChatComposer-pending">
        {pending.map((upload) => (
          <div key={upload.id()} className="ChatUploads-file">
            <i className="fas fa-paperclip" aria-hidden="true" />
            <span className="ChatUploads-file-name">{upload.fileName()}</span>
            <Button
              className="ChatComposer-tool"
              icon="fas fa-times"
              {...iconLabel(app.translator.trans('ramon-chat.forum.composer.remove_upload', {}, true))}
              onclick={() => this.removeUpload(Number(upload.id()))}
            />
          </div>
        ))}
        {this.uploading ? <LoadingIndicator display="inline" size="small" /> : null}
      </div>
    );
  }

  // ── Entrada ──

  protected onInput(e: Event): void {
    const target = e.target as HTMLTextAreaElement;
    const { channel, state, threadId } = this.attrs;

    state.setDraft(Number(channel.id()), target.value, threadId ?? null);
    state.announceTyping(Number(channel.id()));

    this.detectTrigger(target);
    this.resize();
  }

  // ── Autocomplete ──

  /**
   * Procura um trecho `@nome` ou `:emoji` logo antes do cursor. Exige início de
   * palavra, para e-mail ou `http://` não abrirem a lista.
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

    // `:` sozinho casaria com quase tudo; `@` abre logo por causa de @here/@all.
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
      // Emojis customizados primeiro, com cota própria fora dos doze slots.
      const custom: Suggestion[] = searchCustomEmoji(term, 4).map((entry) => ({
        key: 'flamoji-' + entry.name,
        insert: entry.insert,
        label: ':' + entry.name + ':',
        emoji: customEmojiImage(entry, 'ChatAutocomplete-flamoji'),
        hint: entry.title !== entry.name ? entry.title : null,
      }));

      const unicode: Suggestion[] = searchEmoji(term, 12).map((entry) => ({
        key: 'emoji-' + entry.name,
        insert: ':' + entry.name + ':',
        label: ':' + entry.name + ':',
        emoji: entry.unicode,
      }));

      // Com nome repetido, o formatter usa o customizado; o Unicode é omitido.
      const taken = new Set(custom.map((entry) => entry.label));

      this.suggestions = [...custom, ...unicode.filter((entry) => !taken.has(entry.label))];

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
        .find<User[]>('users', {
          // Restrito ao canal: em canal privado, a lista aberta exporia o canal a quem
          // não o vê.
          filter: { q: term, chatChannel: Number(this.attrs.channel.id()) },
          page: { limit: 6 },
        })
        .then((results) => {
          // Resposta velha não substitui a nova nem reabre uma lista já fechada.
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
          // Mantém @here/@all; falha na busca não merece erro.
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

    // O valor vem do rascunho; restaura o cursor depois que o Mithril reescreve.
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

  /** Se a lista está aberta e captura setas, Enter e Escape. */
  protected autocompleteOpen(): boolean {
    return this.trigger !== null && this.suggestions.length > 0;
  }

  protected onKeyDown(e: KeyboardEvent): void {
    // Com a lista aberta, Enter aceita e ArrowUp sobe na lista; checado antes de tudo.
    if (this.autocompleteOpen()) {
      const count = this.suggestions.length;

      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();

        const step = e.key === 'ArrowDown' ? 1 : -1;
        this.activeSuggestion = (this.activeSuggestion + step + count) % count;

        return;
      }

      // Ctrl/Cmd+Enter sempre envia, mesmo com a lista aberta.
      if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey) {
        e.preventDefault();
        this.applySuggestion(this.suggestions[this.activeSuggestion]);

        return;
      }

      if (e.key === 'Tab') {
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

    // Padrão: Enter envia, Shift+Enter quebra linha. Com `send_with_ctrl_enter`,
    // só Ctrl/Cmd+Enter envia (Cmd por causa do macOS).
    if (e.key === 'Enter' && !e.altKey) {
      const withModifier = e.ctrlKey || e.metaKey;

      if (sendsOnCtrlEnter() ? withModifier : !e.shiftKey && !withModifier) {
        e.preventDefault();
        this.submit();

        return;
      }
    }

    if (e.key === 'Escape') {
      this.cancelContext();
    }

    // Seta para cima com o campo vazio edita a última mensagem.
    if (e.key === 'ArrowUp') {
      const { channel, state } = this.attrs;
      const draft = state.draft(Number(channel.id()), this.attrs.threadId ?? null);

      if (draft === '' && !this.editing()) {
        // No escopo de thread, usa a janela da thread, não a do canal.
        const window = this.attrs.threadId ? state.threadStream(this.attrs.threadId).messages : (state.streams[Number(channel.id())]?.messages ?? []);

        const mine = [...window].reverse().find((msg) => msg.canEdit());

        if (mine) {
          e.preventDefault();
          this.startEditing(mine);
        }
      }
    }
  }

  /** Cresce o textarea com o conteúdo, até o max-height do CSS. */
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

  // ── Envio ──

  /**
   * Abre ou fecha o seletor de figurinhas. O shortcode entra no cursor como texto
   * comum; o formatter o converte no envio.
   */
  protected toggleStickers(e: Event): void {
    e.preventDefault();
    e.stopPropagation();

    if (isStickerPickerOpen()) {
      closeStickerPicker();

      return;
    }

    const trigger = (e.currentTarget as HTMLElement)?.closest('.ChatComposer-tool') ?? null;

    openStickerPicker(trigger as HTMLElement | null, (text: string) => this.insertAtCursor(text)).catch(() => {});
  }

  /**
   * Insere texto no cursor. Atualiza o textarea (posição do cursor) e o rascunho
   * (o que é enviado).
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

    // Restaurado após o redraw, senão o cursor vai para o fim.
    this.textarea.focus();
    this.textarea.setSelectionRange(start + text.length, start + text.length);

    this.resize();
  }

  /**
   * Inicia ou renova a contagem do modo lento, num intervalo de um segundo: nada
   * mais redesenharia durante a espera. Só espelha o que o servidor informou.
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

    if (!content.trim() && this.pendingUploads().length === 0) return;

    // Recusa aqui sem mexer no rascunho. O servidor checa o mesmo limite; aqui só
    // se poupa a ida e volta e se mostra por quanto passou.
    const max = resolveMaxMessageLength(channel);

    if (content.length > max) {
      app.modal.show(loadMessageTooLongModal, { length: content.length, max });

      return;
    }

    // Antes de desabilitar: desabilitar tira o foco e reabilitar não o devolve.
    // Só restaura se o foco estava no composer (textarea ou botão de envio).
    const hadFocus = Boolean(this.element && this.element.contains(document.activeElement));

    this.sending = true;

    // Limpa antes da resposta para a digitação continuar na hora.
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
          // Só com "responder em thread", no composer do canal e com alvo
          // ramificável: a policy recusa thread aninhada.
          createThread:
            state.branchingFrom(channelId, threadId ?? null) &&
            Boolean(replyingTo) &&
            Boolean(channel.threadingEnabled()) &&
            !threadId &&
            Boolean(replyingTo?.canCreateThread()),
        });
      }

      // Reinicia a espera após enviar, não após editar. Lê o valor cru do canal,
      // então checa as isenções (`bypassSlowMode` global e dono/moderadores do
      // canal) como o servidor faz.
      if (!editing && !app.forum.attribute('canBypassChatSlowMode') && !channel.bypassesSlowMode()) {
        this.startCooldown(Number(channel.slowModeSeconds() ?? 0));
      }

      onSent?.();
    } catch (e: any) {
      // Devolve o texto ao campo e mostra o motivo.
      state.setDraft(channelId, content, threadId ?? null);

      const detail = e?.response?.errors?.[0]?.detail;

      app.alerts.show({ type: 'error' }, detail ?? app.translator.trans('ramon-chat.forum.composer.send_failed', {}, true));
    } finally {
      this.sending = false;

      // Síncrono: focus() num elemento ainda desabilitado não faz nada.
      m.redraw.sync();

      if (hadFocus) this.textarea?.focus();
    }
  }

  // ── Uploads ──

  protected pendingUploads(): Upload[] {
    const { channel, state, threadId } = this.attrs;

    return state.pendingUploadsFor(Number(channel.id()), threadId ?? null);
  }

  protected pickFiles(): void {
    (this.element.querySelector('.ChatComposer-fileInput') as HTMLInputElement | null)?.click();
  }

  protected async onFilesPicked(e: Event): Promise<void> {
    const input = e.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);

    // Zera para escolher o mesmo arquivo de novo ainda disparar change.
    input.value = '';

    if (files.length === 0) return;

    // Fixado agora: se a pessoa trocar de canal durante o envio, o arquivo
    // continua na conversa para a qual foi escolhido.
    const channelId = Number(this.attrs.channel.id());
    const threadId = this.attrs.threadId ?? null;

    this.uploading = true;
    m.redraw();

    for (const file of files) {
      try {
        const body = new FormData();
        body.append('file', file);
        // Indica o destino para o servidor já gravar arquivo de canal privado no
        // disco privado. É só uma dica; o envio ainda aplica a regra.
        body.append('channelId', String(channelId));

        const payload = await app.request<any>({
          method: 'POST',
          url: `${app.forum.attribute('apiUrl')}/chat/uploads`,
          body,
          serialize: (raw: any) => raw,
        });

        const upload = app.store.pushPayload<any>(payload);
        this.attrs.state.addPendingUpload(channelId, threadId, upload);
      } catch (err: any) {
        const detail = err?.response?.errors?.[0]?.detail;

        app.alerts.show({ type: 'error' }, detail ?? app.translator.trans('ramon-chat.forum.composer.upload_failed', {}, true));
      }
    }

    this.uploading = false;
    m.redraw();
  }

  protected removeUpload(id: number): void {
    const { channel, state, threadId } = this.attrs;

    state.removePendingUpload(Number(channel.id()), threadId ?? null, id);

    // Descarta no servidor também, sem esperar a limpeza por retenção.
    app
      .request({
        method: 'DELETE',
        url: `${app.forum.attribute('apiUrl')}/chat-uploads/${id}`,
      })
      .catch(() => {});
  }
}
