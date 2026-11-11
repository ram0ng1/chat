import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import type { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Alert from 'flarum/common/components/Alert';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Switch from 'flarum/common/components/Switch';
import Stream from 'flarum/common/utils/Stream';
import classList from 'flarum/common/utils/classList';
import withAttr from 'flarum/common/utils/withAttr';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import chatState from '../state/chat';
import afterModalClosed from '../utils/afterModalClosed';
import EmojiPicker from './EmojiPicker';
import ScheduleFields, { ScheduleDraft } from './ScheduleFields';
import { resolveEmoji } from '../utils/emoji';
import { humanDuration } from '../utils/duration';
import { forumMaxMessageLength } from '../utils/messageLimit';

/**
 * Opções de tamanho de mensagem e os limites que a API aplica a um valor próprio.
 * Sincronizado à mão com `maxMessageLength` do ChannelResource; o servidor limita
 * de qualquer forma.
 */
const LENGTH_STEPS = [500, 1000, 2000, 3000, 4000, 8000, 16000];
const MIN_LENGTH = 100;
const MAX_LENGTH = 50000;

/** Marcador da opção "personalizado"; nunca é gravado. */
const CUSTOM_LENGTH = 'custom';

export interface ChannelFormModalAttrs extends IFormModalAttrs {
  /** Sem canal, cria; com canal, edita. */
  channel?: Channel;
  /** Recebe o canal salvo. */
  onSaved?: (channel: Channel) => void;
}

/**
 * Cria ou edita um canal de categoria; os campos são os mesmos nos dois casos.
 *
 * Estende FormModal, não Modal: `Modal.wrapper()` devolve um fragment sem form, e
 * o onsubmit nunca dispararia.
 */
export default class ChannelFormModal extends FormModal<ChannelFormModalAttrs> {
  private name!: Stream<string>;
  private description!: Stream<string>;
  private emoji!: Stream<string>;
  private uploadingImage = false;

  /**
   * Ação imediata em andamento, ou null. `loading` (do core) desabilita o form
   * inteiro; isto decide qual botão mostra o spinner.
   */
  private pending: 'status' | 'archive' | 'unarchive' | 'delete' | null = null;

  /**
   * Imagem escolhida ao criar, guardada até o canal existir: a rota de upload
   * precisa do id do canal.
   */
  private pendingImage: File | null = null;

  /**
   * URL de `URL.createObjectURL` da prévia de {@link pendingImage}. Precisa ser
   * revogada, senão o blob vive até o documento fechar.
   */
  private pendingImageUrl: string | null = null;

  /**
   * Qual dos dois campos de ícone está em uso. O canal tem um só (`channelIcon`
   * prefere a imagem); {@link onsubmit} limpa o outro campo.
   */
  private useImage!: Stream<boolean>;
  private tagId!: Stream<string>;
  private threading!: Stream<boolean>;
  private slowMode!: Stream<string>;
  private schedule!: ScheduleDraft;

  /** String, como `slowMode`, porque alimenta um <select>. "" segue o fórum. */
  private maxMessageLength!: Stream<string>;

  /** Se o campo numérico próprio aparece no lugar das opções. */
  private customLength!: Stream<boolean>;
  private autoJoin!: Stream<boolean>;
  private allowChannelWide!: Stream<boolean>;
  private autoJoinOnReply!: Stream<boolean>;
  private isPrivate!: Stream<boolean>;
  private postPermission!: Stream<string>;
  private postDiscussions!: Stream<boolean>;

  oninit(vnode: Mithril.Vnode<ChannelFormModalAttrs>): void {
    super.oninit(vnode);

    const channel = this.attrs.channel;

    this.name = Stream(channel?.name() ?? '');
    this.description = Stream(channel?.description() ?? '');
    this.emoji = Stream(channel?.emoji() ?? '');

    // Começa no que o canal já usa; se há imagem salva, é ela que aparece.
    this.useImage = Stream(Boolean(channel?.imageUrl()));

    this.tagId = Stream(channel?.tagId() ? String(channel.tagId()) : '');

    // String por causa do <select>; convertido só no envio.
    this.slowMode = Stream(String(channel?.slowModeSeconds() ?? 0));
    this.schedule = new ScheduleDraft(channel?.schedule() ?? null, channel?.scheduleEventTitle() ?? null);

    // "" e não "0": "seguir o fórum" vira null, e 0 seria limite de zero caracteres.
    this.maxMessageLength = Stream(channel?.maxMessageLength() ? String(channel.maxMessageLength()) : '');

    // Valor fora da lista abre no campo próprio, sem arredondar para uma opção.
    const saved = channel?.maxMessageLength() ?? 0;
    this.customLength = Stream(saved > 0 && !LENGTH_STEPS.includes(saved));

    this.threading = Stream(channel ? Boolean(channel.threadingEnabled()) : Boolean(app.forum.attribute('ramon-chat.threadingDefault')));
    this.autoJoin = Stream(channel ? Boolean(channel.autoJoin()) : false);
    this.allowChannelWide = Stream(channel ? channel.allowChannelWideMentions() !== false : true);
    this.autoJoinOnReply = Stream(channel ? Boolean(channel.autoJoinOnReply()) : false);

    // Público por padrão: privado precisa ser escolha explícita.
    this.isPrivate = Stream(channel ? Boolean(channel.isPrivate()) : false);
    this.postPermission = Stream(channel?.postPermission() ?? 'all');
    this.postDiscussions = Stream(channel ? Boolean(channel.postDiscussions()) : false);
  }

  /**
   * Libera a object URL da imagem pendente; sem isso o blob sobreviveria ao
   * componente.
   */
  onremove(vnode: Mithril.VnodeDOM<ChannelFormModalAttrs, this>): void {
    super.onremove(vnode);
    this.discardPendingImage();
  }

  protected isEditing(): boolean {
    return Boolean(this.attrs.channel);
  }

  className(): string {
    return 'ChatModal ChannelFormModal Modal--large';
  }

  title(): Mithril.Children {
    return app.translator.trans(this.isEditing() ? 'ramon-chat.forum.edit_channel.title' : 'ramon-chat.forum.browse.new_channel');
  }

  /**
   * Troca o cabeçalho + corpo do core por cabeçalho / corpo / rodapé. As duas
   * colunas, os grupos e o botão fixo dependem deste wrapper.
   */
  protected inner(): Mithril.Children {
    return (
      <>
        {this.header()}

        {!!this.alertAttrs && (
          <div className="Modal-alert">
            <Alert {...this.alertAttrs} />
          </div>
        )}

        {this.content()}
        {this.footer()}
      </>
    );
  }

  /** Ícone, título e uma linha de orientação. O ícone reflete a escolha atual. */
  protected header(): Mithril.Children {
    // Segue o switch, não o valor salvo: mostra o que o save vai produzir.
    const image = this.useImage() ? this.iconImageUrl() : null;
    const emoji = this.useImage() ? null : resolveEmoji(this.emoji());
    const name = this.name().trim();

    return (
      <div className="Modal-header ChannelFormModal-header">
        <div className="ChannelFormModal-headerIcon" aria-hidden="true">
          {image ? <img src={image} alt="" /> : emoji ? <span>{emoji}</span> : <i className="fas fa-hashtag" />}
        </div>

        <div className="ChannelFormModal-headerText">
          <h3 className="App-titleControl App-titleControl--text">{this.title()}</h3>
          <p className="ChannelFormModal-headerSubtitle">
            {name || app.translator.trans(this.isEditing() ? 'ramon-chat.forum.edit_channel.subtitle' : 'ramon-chat.forum.new_channel.subtitle')}
          </p>
        </div>
      </div>
    );
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body ChannelFormModal-body">
        {/* Grid plano: identidade e ciclo de vida ocupam as duas colunas. */}
        <div className="Form ChannelFormModal-grid">
          {this.identitySection()}
          {this.accessSection()}
          {this.behaviourSection()}
          {this.scheduleSection()}
          {this.lifecycle()}
        </div>
      </div>
    );
  }

  /** Nome, descrição e ícone do canal. */
  protected identitySection(): Mithril.Children {
    return this.section(
      'ramon-chat.forum.new_channel.section_identity',
      [
        // Ícone e nome na mesma linha; só cabe porque a seção ocupa as duas colunas.
        <div className="Form-group ChannelFormModal-nameRow">
          <div>
            <label>{app.translator.trans('ramon-chat.forum.new_channel.name')}</label>
            <input
              className="FormControl"
              type="text"
              maxlength={100}
              bidi={this.name}
              placeholder={app.translator.trans('ramon-chat.forum.new_channel.name_placeholder', {}, true)}
              disabled={this.loading}
            />
          </div>

          {this.iconField()}
        </div>,

        <div className="Form-group">
          <label>{app.translator.trans('ramon-chat.forum.new_channel.description')}</label>
          <textarea className="FormControl" rows={2} maxlength={1000} bidi={this.description} disabled={this.loading} />
        </div>,
      ],
      'ChannelFormModal-section--wide'
    );
  }

  /**
   * Ícone do canal: emoji ou imagem. Um só espaço, com o campo escolhido no switch.
   */
  protected iconField(): Mithril.Children {
    const picture = this.useImage();

    return (
      <div className="ChannelFormModal-iconField">
        <div className="ChannelFormModal-iconField-head">
          {/* O label nomeia o campo visível. */}
          <label>{app.translator.trans(picture ? 'ramon-chat.forum.new_channel.image' : 'ramon-chat.forum.new_channel.emoji')}</label>

          {/* Um botão com os dois lados: clicar em qualquer ponto alterna.
              `aria-checked` com "true"/"false" explícito: o Mithril renderiza
              booleano como string vazia, que o leitor de tela não entende. */}
          <button
            type="button"
            className="ChannelFormModal-iconKind"
            role="switch"
            aria-checked={picture ? 'true' : 'false'}
            aria-label={app.translator.trans('ramon-chat.forum.new_channel.icon_kind', {}, true)}
            title={app.translator.trans(
              picture ? 'ramon-chat.forum.new_channel.icon_kind_to_emoji' : 'ramon-chat.forum.new_channel.icon_kind_to_image',
              {},
              true
            )}
            disabled={this.loading || this.uploadingImage}
            onclick={() => this.chooseIconKind(!picture)}
          >
            {this.iconKindSide(false, 'far fa-face-smile')}
            {this.iconKindSide(true, 'fas fa-image')}
          </button>
        </div>

        {/* Mesmo wrapper e linha de ajuda nos dois lados, para a altura não mudar
            ao alternar. */}
        <div className="ChannelFormModal-iconField-body">
          {picture ? (
            this.image()
          ) : (
            <>
              <EmojiPicker value={this.emoji()} onchange={(value: string | null) => this.emoji(value ?? '')} disabled={this.loading} />

              <div className="helpText">{app.translator.trans('ramon-chat.forum.new_channel.emoji_help')}</div>
            </>
          )}
        </div>
      </div>
    );
  }

  /** Metade do switch. `span`, porque o botão é o controle inteiro. */
  protected iconKindSide(picture: boolean, icon: string): Mithril.Children {
    return (
      <span
        className={classList('ChannelFormModal-iconKind-option', {
          'ChannelFormModal-iconKind-option--active': this.useImage() === picture,
        })}
        aria-hidden="true"
      >
        <i className={icon} />
      </span>
    );
  }

  /**
   * Alterna entre os campos de ícone. Desligar a imagem descarta o arquivo
   * pendente; imagem já no servidor só sai no envio ({@link onsubmit}), para
   * a mudança poder ser cancelada.
   */
  protected chooseIconKind(picture: boolean): void {
    this.useImage(picture);

    if (!picture) this.discardPendingImage();
  }

  /** Quem vê, quem escreve e o que o canal herda. */
  protected accessSection(): Mithril.Children {
    return this.section('ramon-chat.forum.new_channel.section_access', [this.visibility(), this.posting(), this.tagOptions()]);
  }

  /** Comportamento do canal. */
  protected behaviourSection(): Mithril.Children {
    const tag = this.selectedTag();
    const curates = !!app.forum.attribute<boolean>('canCurateChatChannels');

    return this.section('ramon-chat.forum.new_channel.section_behaviour', [
      this.slowModeOptions(),
      this.messageLengthOptions(),

      <div className="Form-group ChannelFormModal-toggles">
        {this.toggle(this.threading, 'ramon-chat.forum.info.threading', app.translator.trans('ramon-chat.forum.new_channel.threading_help'))}

        {this.toggle(
          this.allowChannelWide,
          'ramon-chat.forum.settings.channel_wide_mentions',
          app.translator.trans('ramon-chat.forum.new_channel.channel_wide_mentions_help')
        )}

        {/* Só com categoria escolhida. Os switches de público só aparecem para
            quem pode mudá-los: cada um pode puxar membros, e o ChannelResource
            recusa os demais. */}
        {tag && curates
          ? this.toggle(
              this.autoJoinOnReply,
              'ramon-chat.forum.info.auto_join_on_reply',
              app.translator.trans('ramon-chat.forum.info.auto_join_on_reply_help_bound', { category: tag.name() })
            )
          : null}

        {/* Só com categoria: anuncia discussões dela. Limpar a categoria o
            desliga (ver `chooseTag`). */}
        {tag && curates
          ? this.toggle(
              this.postDiscussions,
              'ramon-chat.forum.info.post_discussions',
              app.translator.trans('ramon-chat.forum.info.post_discussions_help_bound', { category: tag.name() })
            )
          : null}

        {/* Auto-join pode adicionar todas as contas do fórum. */}
        {curates ? this.toggle(this.autoJoin, 'ramon-chat.forum.info.auto_join', app.translator.trans('ramon-chat.forum.info.auto_join_help')) : null}
      </div>,
    ]);
  }

  /**
   * Horário de funcionamento: fora dele, só a moderação escreve. Ocupa as duas
   * colunas, como a identidade, porque os campos mudam com o modo.
   */
  protected scheduleSection(): Mithril.Children {
    return this.section(
      'ramon-chat.forum.schedule.section',
      [<ScheduleFields draft={this.schedule} disabled={this.loading} unavailable={!!this.attrs.channel?.scheduleUnavailable()} />],
      'ChannelFormModal-section--wide'
    );
  }

  /** Grupo de campos com título; some quando não há nada a mostrar. */
  protected section(titleKey: string, children: Mithril.Children[], className?: string): Mithril.Children {
    const content = children.filter(Boolean);

    if (content.length === 0) return null;

    return (
      <section className={classList('ChannelFormModal-section', className)}>
        <h4 className="ChannelFormModal-sectionTitle">{app.translator.trans(titleKey)}</h4>
        {content}
      </section>
    );
  }

  /**
   * Linha de switch: controle, label e ajuda. `Switch` em vez de checkbox para a
   * ajuda ficar sob o label sem recuo fixo.
   */
  protected toggle(
    stream: Stream<boolean>,
    labelKey: string,
    // Children, não chave: duas linhas interpolam o nome da categoria.
    help?: Mithril.Children
  ): Mithril.Children {
    return (
      <div className="ChannelFormModal-toggle">
        <Switch state={stream()} onchange={(checked: boolean) => stream(checked)} disabled={this.loading}>
          {app.translator.trans(labelKey)}
        </Switch>

        {help ? <div className="helpText">{help}</div> : null}
      </div>
    );
  }

  /** Linha de envio, fixa abaixo do corpo rolável. */
  protected footer(): Mithril.Children {
    return (
      <div className="Modal-footer ChannelFormModal-footer">
        <Button className="Button Button--link" type="button" disabled={this.loading} onclick={() => this.hide()}>
          {app.translator.trans('ramon-chat.forum.new_channel.cancel')}
        </Button>

        <Button
          className="Button Button--primary"
          type="submit"
          loading={this.loading && this.pending === null}
          disabled={this.loading || this.name().trim() === ''}
        >
          {app.translator.trans(this.isEditing() ? 'ramon-chat.forum.edit_channel.submit' : 'ramon-chat.forum.new_channel.submit')}
        </Button>
      </div>
    );
  }

  /**
   * Imagem do canal no lugar do emoji. Na edição o upload é imediato; na criação
   * o arquivo fica em {@link pendingImage} até o canal existir
   * ({@link attachPendingImage}). O label vem de {@link iconField}.
   */
  protected image(): Mithril.Children {
    const url = this.iconImageUrl();

    return (
      <div className="ChatChannelForm-image">
        <div className="ChatChannelForm-imageRow">
          <div className="ChatChannelForm-imagePreview">{url ? <img src={url} alt="" /> : <i className="fas fa-hashtag" aria-hidden="true" />}</div>

          {this.uploadingImage ? (
            <LoadingIndicator display="inline" size="small" />
          ) : (
            <>
              <label className="Button">
                {app.translator.trans('ramon-chat.forum.new_channel.image_upload')}
                <input type="file" accept="image/*" style={{ display: 'none' }} onchange={(e: Event) => this.chooseImage(e)} />
              </label>

              {url ? (
                <Button className="Button" onclick={() => this.removeImage()}>
                  {app.translator.trans('ramon-chat.forum.new_channel.image_remove')}
                </Button>
              ) : null}
            </>
          )}
        </div>

        <div className="helpText">{app.translator.trans('ramon-chat.forum.new_channel.image_help')}</div>
      </div>
    );
  }

  /** Imagem do canal: pendente na criação, salva na edição. */
  protected iconImageUrl(): string | null {
    return this.attrs.channel ? this.attrs.channel.imageUrl() : this.pendingImageUrl;
  }

  /**
   * Envia o arquivo direto se o canal existe; senão guarda em {@link pendingImage}.
   */
  protected chooseImage(e: Event): void {
    if (this.isEditing()) {
      void this.uploadImage(e);
      return;
    }

    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];

    // Zera para escolher o mesmo arquivo de novo ainda disparar change.
    input.value = '';

    if (!file) return;

    this.discardPendingImage();
    this.pendingImage = file;
    this.pendingImageUrl = URL.createObjectURL(file);
    m.redraw();
  }

  /** Remove a imagem: DELETE se salva, descarte local se pendente. */
  protected removeImage(): void {
    if (this.isEditing()) {
      void this.clearImage();
      return;
    }

    this.discardPendingImage();
    m.redraw();
  }

  private discardPendingImage(): void {
    if (this.pendingImageUrl) URL.revokeObjectURL(this.pendingImageUrl);

    this.pendingImage = null;
    this.pendingImageUrl = null;
  }

  /**
   * Envia a imagem pendente ao canal recém-criado. Uma falha aqui não desfaz o
   * canal; o usuário é avisado e pode enviar pelas configurações.
   */
  private async attachPendingImage(channel: Channel): Promise<void> {
    const file = this.pendingImage;

    if (!file) return;

    const body = new FormData();
    body.append('image', file);

    try {
      const response = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat/channels/${channel.id()}/image`,
        serialize: (raw: any) => raw,
        body,
      });

      channel.pushAttributes({ imageUrl: response?.data?.imageUrl ?? null });
    } catch (err: any) {
      app.alerts.show(
        { type: 'error' },
        err?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.new_channel.image_failed_after_create')
      );
    } finally {
      this.discardPendingImage();
    }
  }

  protected async uploadImage(e: Event): Promise<void> {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    const channel = this.attrs.channel;

    if (!file || !channel) return;

    this.uploadingImage = true;
    m.redraw();

    const body = new FormData();
    body.append('image', file);

    try {
      const response = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat/channels/${channel.id()}/image`,
        // O serializer padrão transformaria o FormData em "[object FormData]".
        serialize: (raw: any) => raw,
        body,
      });

      channel.pushAttributes({ imageUrl: response?.data?.imageUrl ?? null });
    } catch (err: any) {
      app.alerts.show({ type: 'error' }, err?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.new_channel.image_failed'));
    } finally {
      this.uploadingImage = false;
      // Zera para escolher o mesmo arquivo de novo ainda disparar change.
      input.value = '';
      m.redraw();
    }
  }

  protected async clearImage(): Promise<void> {
    const channel = this.attrs.channel;

    if (!channel) return;

    this.uploadingImage = true;
    m.redraw();

    try {
      await app.request({
        method: 'DELETE',
        url: `${app.forum.attribute('apiUrl')}/chat/channels/${channel.id()}/image`,
      });

      channel.pushAttributes({ imageUrl: null });
    } catch {
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.new_channel.image_failed'));
    } finally {
      this.uploadingImage = false;
      m.redraw();
    }
  }

  protected visibility(): Mithril.Children {
    return (
      <div className="Form-group">
        <label>{app.translator.trans('ramon-chat.forum.new_channel.visibility')}</label>

        {/* Dois cards lado a lado, para as duas descrições serem lidas juntas. */}
        <div className="ChannelFormModal-choices">
          {this.choice(false, 'fas fa-globe', 'ramon-chat.forum.new_channel.public', 'ramon-chat.forum.new_channel.public_help')}
          {this.choice(true, 'fas fa-lock', 'ramon-chat.forum.new_channel.private', 'ramon-chat.forum.new_channel.private_help')}
        </div>
      </div>
    );
  }

  /** Card de visibilidade. Por baixo é um radio, com a semântica de grupo. */
  protected choice(value: boolean, icon: string, labelKey: string, helpKey: string): Mithril.Children {
    const selected = this.isPrivate() === value;

    return (
      <label
        className={classList('ChannelFormModal-choice', {
          'ChannelFormModal-choice--selected': selected,
        })}
      >
        <input type="radio" name="ramon-chat-visibility" checked={selected} onchange={() => this.isPrivate(value)} disabled={this.loading} />

        <span className="ChannelFormModal-choice-title">
          <i className={icon} aria-hidden="true" />
          {app.translator.trans(labelKey)}
        </span>

        <span className="ChannelFormModal-choice-help">{app.translator.trans(helpKey)}</span>
      </label>
    );
  }

  /**
   * Quem pode escrever, independente de quem pode ver.
   */
  protected posting(): Mithril.Children {
    return (
      <div className="Form-group">
        <label>{app.translator.trans('ramon-chat.forum.new_channel.post_permission')}</label>

        <select className="FormControl" value={this.postPermission()} onchange={withAttr('value', this.postPermission)} disabled={this.loading}>
          <option value="all">{app.translator.trans('ramon-chat.forum.new_channel.post_all', {}, true)}</option>
          <option value="moderators">{app.translator.trans('ramon-chat.forum.new_channel.post_moderators', {}, true)}</option>
        </select>

        {/* Descreve a opção selecionada. */}
        <div className="helpText">
          {app.translator.trans(
            this.postPermission() === 'moderators'
              ? 'ramon-chat.forum.new_channel.post_moderators_help'
              : 'ramon-chat.forum.new_channel.post_all_help'
          )}
        </div>
      </div>
    );
  }

  /**
   * Fechar e arquivar, só em canal existente. Agem na hora, não no envio: são
   * mudanças de estado, não configurações.
   */
  protected lifecycle(): Mithril.Children {
    const channel = this.attrs.channel;

    if (!channel) return null;

    const closed = channel.status() === 'closed';

    // Pelo status, não `archivedAt` (ver ChannelInfoModal::moderation()).
    const archived = channel.isArchived();
    const items: Mithril.Children[] = [];

    if (channel.canClose() && !archived) {
      items.push(
        <Button
          className="Button"
          icon={closed ? 'fas fa-lock-open' : 'fas fa-lock'}
          loading={this.pending === 'status'}
          disabled={this.loading}
          onclick={() => this.setStatus(closed ? 'open' : 'closed')}
        >
          {app.translator.trans(closed ? 'ramon-chat.forum.info.reopen_channel' : 'ramon-chat.forum.info.close_channel')}
        </Button>
      );
    }

    if (channel.canArchive() && !archived) {
      items.push(
        <Button
          className="Button"
          icon="fas fa-box-archive"
          loading={this.pending === 'archive'}
          disabled={this.loading}
          onclick={() => this.archive()}
        >
          {app.translator.trans('ramon-chat.forum.info.archive_channel')}
        </Button>
      );
    }

    if (archived && channel.canUnarchive()) {
      items.push(
        <Button
          className="Button"
          icon="fas fa-box-open"
          loading={this.pending === 'unarchive'}
          disabled={this.loading}
          onclick={() => this.unarchive()}
        >
          {app.translator.trans('ramon-chat.forum.info.unarchive_channel')}
        </Button>
      );
    }

    // Excluir também aparece aqui, além do painel de informações.
    const canDelete = channel.canDelete();

    if (items.length === 0 && !canDelete) return null;

    return (
      <div className="ChannelFormModal-lifecycle ChannelFormModal-section ChannelFormModal-section--wide">
        <label className="ChannelFormModal-sectionTitle">{app.translator.trans('ramon-chat.forum.edit_channel.lifecycle')}</label>

        {items.length > 0 ? <div className="ChannelFormModal-lifecycleActions">{items}</div> : null}

        <div className="helpText">{app.translator.trans('ramon-chat.forum.edit_channel.lifecycle_help')}</div>

        {canDelete ? (
          <div className="ChannelFormModal-lifecycleDanger">
            <Button
              className="Button ChannelFormModal-deleteButton"
              icon="fas fa-trash"
              loading={this.pending === 'delete'}
              disabled={this.loading}
              onclick={() => this.destroy()}
            >
              {app.translator.trans('ramon-chat.forum.info.delete_channel')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  /**
   * Exclui o canal e fecha o diálogo. Não usa `act()`, que passa o canal salvo ao
   * `onSaved`; aqui o registro já não existe.
   */
  protected async destroy(): Promise<void> {
    const channel = this.attrs.channel;

    if (!channel) return;

    if (!confirm(app.translator.trans('ramon-chat.forum.info.delete_confirm', {}, true))) return;

    this.pending = 'delete';
    this.loading = true;
    m.redraw();

    try {
      await channel.delete();

      chatState.channels = chatState.channels.filter((c) => c.id() !== channel.id());

      // O canal ativo não pode ficar apontando para um registro excluído.
      if (chatState.activeChannelId === Number(channel.id())) {
        chatState.setActiveChannel(null);
      }

      this.hide();

      afterModalClosed(() => this.attrs.onSaved?.(channel));
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.edit_channel.failed'));
    } finally {
      this.pending = null;
      this.loading = false;
      m.redraw();
    }
  }

  protected async setStatus(status: 'open' | 'closed'): Promise<void> {
    await this.act('status', `/chat-channels/${this.attrs.channel!.id()}/status`, {
      status,
    });
  }

  protected async archive(): Promise<void> {
    if (!confirm(app.translator.trans('ramon-chat.forum.edit_channel.archive_confirm', {}, true))) return;

    await this.act('archive', `/chat-channels/${this.attrs.channel!.id()}/archive`, {});
  }

  protected async unarchive(): Promise<void> {
    await this.act('unarchive', `/chat-channels/${this.attrs.channel!.id()}/unarchive`, {});
  }

  /**
   * Executa uma mudança de estado imediata. `action` diz qual botão mostra o
   * spinner; `loading` bloqueia o resto do form.
   */
  protected async act(action: 'status' | 'archive' | 'unarchive', path: string, attributes: Record<string, unknown>): Promise<void> {
    this.pending = action;
    this.loading = true;
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}${path}`,
        body: { data: { attributes } },
      });

      if (payload?.data) app.store.pushPayload(payload);

      this.hide();

      // Como no save: quem chamou não pode competir com a animação de fechar.
      afterModalClosed(() => this.attrs.onSaved?.(this.attrs.channel!));
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.edit_channel.failed'));
    } finally {
      this.pending = null;
      this.loading = false;
      m.redraw();
    }
  }

  /**
   * Seletor de categoria, só com flarum/tags. O canal herda as permissões da tag.
   */
  protected tagOptions(): Mithril.Children {
    const tags = app.store.all('tags');

    if (!('flarum-tags' in (flarum.extensions ?? {})) || tags.length === 0) return null;

    const selected = this.selectedTag();

    return (
      <div className="Form-group">
        <label>{app.translator.trans('ramon-chat.forum.new_channel.category')}</label>

        {/* Sem `bidi`: num <select> ele lê `option.attrs.value` dos filhos e quebra
            com a lista aninhada do map. */}
        <select className="FormControl" value={this.tagId()} onchange={withAttr('value', (id: string) => this.chooseTag(id))} disabled={this.loading}>
          <option value="">{app.translator.trans('ramon-chat.forum.new_channel.no_category')}</option>
          {tags
            .filter((tag: any) => !tag.isChild?.())
            .map((tag: any) => (
              <option key={tag.id()} value={String(tag.id())}>
                {tag.name()}
              </option>
            ))}
        </select>

        {/* Cita a categoria pelo nome. */}
        <div className="helpText">
          {selected
            ? app.translator.trans('ramon-chat.forum.new_channel.category_help_bound', { category: selected.name() })
            : app.translator.trans('ramon-chat.forum.new_channel.category_help_none')}
        </div>
      </div>
    );
  }

  /**
   * Escolhe ou limpa a categoria. Limpar desliga "anunciar novas discussões", que
   * só aparece com categoria, para não enviar um valor invisível.
   */
  protected chooseTag(id: string): void {
    this.tagId(id);

    // Só para quem pode mudá-los; para os demais o ChannelResource recusaria.
    if (!id && app.forum.attribute<boolean>('canCurateChatChannels')) {
      this.postDiscussions(false);
      this.autoJoinOnReply(false);
    }
  }

  /** Categoria escolhida no seletor, se houver. */
  protected selectedTag(): any | null {
    if (!this.tagId()) return null;

    return app.store.all('tags').find((tag: any) => String(tag.id()) === this.tagId()) ?? null;
  }

  /**
   * Modo lento: espera entre mensagens de cada pessoa. Lista fixa, não campo livre.
   * A ajuda diz que moderadores são isentos, só quando há espera definida.
   */
  protected slowModeOptions(): Mithril.Children {
    const steps = [0, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600, 21600];
    const seconds = Number(this.slowMode()) || 0;

    return (
      <div className="Form-group">
        <label>{app.translator.trans('ramon-chat.forum.new_channel.slow_mode')}</label>

        <select className="FormControl" value={this.slowMode()} onchange={withAttr('value', this.slowMode)} disabled={this.loading}>
          {steps.map((seconds) => (
            <option key={seconds} value={String(seconds)}>
              {seconds === 0 ? app.translator.trans('ramon-chat.forum.new_channel.slow_mode_off', {}, true) : humanDuration(seconds)}
            </option>
          ))}
        </select>

        <p className="helpText">
          {seconds === 0
            ? app.translator.trans('ramon-chat.forum.new_channel.slow_mode_help_off')
            : app.translator.trans('ramon-chat.forum.new_channel.slow_mode_help_on', { duration: humanDuration(seconds) })}
        </p>
      </div>
    );
  }

  /**
   * Tamanho máximo da mensagem: opções prontas mais "personalizado". A primeira
   * opção segue a configuração do fórum.
   */
  protected messageLengthOptions(): Mithril.Children {
    const forumDefault = forumMaxMessageLength();
    const value = this.maxMessageLength();
    const custom = this.customLength();

    return (
      <div className="Form-group">
        <label>{app.translator.trans('ramon-chat.forum.new_channel.message_length')}</label>

        <select
          className="FormControl"
          value={custom ? CUSTOM_LENGTH : value}
          onchange={withAttr('value', (picked: string) => this.chooseMessageLength(picked))}
          disabled={this.loading}
        >
          <option value="">{app.translator.trans('ramon-chat.forum.new_channel.message_length_inherit', { count: forumDefault }, true)}</option>

          {LENGTH_STEPS.map((chars) => (
            <option key={chars} value={String(chars)}>
              {app.translator.trans('ramon-chat.forum.new_channel.message_length_chars', { count: chars }, true)}
            </option>
          ))}

          <option value={CUSTOM_LENGTH}>{app.translator.trans('ramon-chat.forum.new_channel.message_length_custom', {}, true)}</option>
        </select>

        {custom ? (
          <input
            className="FormControl ChannelFormModal-customLength"
            type="number"
            min={MIN_LENGTH}
            max={MAX_LENGTH}
            step={100}
            // Sem `bidi` nem limite por tecla ("5" viraria "100" antes do "00"); o
            // limite é aplicado no envio, como no servidor.
            value={value}
            placeholder={String(forumDefault)}
            disabled={this.loading}
            oninput={withAttr('value', this.maxMessageLength)}
            oncreate={(vnode: Mithril.VnodeDOM) => (vnode.dom as HTMLInputElement).focus()}
          />
        ) : null}

        <p className="helpText">{this.messageLengthHelp(forumDefault)}</p>
      </div>
    );
  }

  /** Frase sob o campo, sobre a opção selecionada. */
  protected messageLengthHelp(forumDefault: number): Mithril.Children {
    const raw = this.maxMessageLength().trim();

    if (raw === '') {
      return app.translator.trans(
        this.customLength() ? 'ramon-chat.forum.new_channel.message_length_help_empty' : 'ramon-chat.forum.new_channel.message_length_help_inherit',
        { count: forumDefault }
      );
    }

    const chars = Number(raw);

    // Mostra o valor que será gravado após o limite, não o digitado.
    if (chars < MIN_LENGTH || chars > MAX_LENGTH) {
      return app.translator.trans('ramon-chat.forum.new_channel.message_length_help_clamped', { count: this.clampedMessageLength() ?? forumDefault });
    }

    return app.translator.trans('ramon-chat.forum.new_channel.message_length_help_own', { count: chars });
  }

  /** Alterna entre opção pronta, padrão do fórum e campo próprio. */
  protected chooseMessageLength(picked: string): void {
    if (picked === CUSTOM_LENGTH) {
      this.customLength(true);

      // Começa com a opção que estava visível, em vez de vazio.
      if (this.maxMessageLength() === '') {
        this.maxMessageLength(String(forumMaxMessageLength()));
      }

      return;
    }

    this.customLength(false);
    this.maxMessageLength(picked);
  }

  /**
   * Valor próprio como será gravado: limitado ao intervalo da API, ou null se
   * vazio ou inválido.
   */
  protected clampedMessageLength(): number | null {
    const chars = Number(this.maxMessageLength().trim());

    if (!Number.isFinite(chars) || chars <= 0) return null;

    return Math.max(MIN_LENGTH, Math.min(MAX_LENGTH, Math.round(chars)));
  }

  /**
   * Ninguém aguarda este método; toda falha é tratada aqui para não virar
   * unhandled rejection.
   */
  onsubmit(e: SubmitEvent): void {
    e.preventDefault();

    if (this.loading) return;

    this.loading = true;

    const editing = this.isEditing();

    const attributes: Record<string, unknown> = {
      name: this.name().trim(),
      description: this.description().trim() || null,
      // null com imagem: o canal não guarda os dois. O stream mantém o valor para
      // o switch poder voltar.
      emoji: this.useImage() ? null : this.emoji().trim() || null,
      threadingEnabled: this.threading(),
      slowModeSeconds: Number(this.slowMode()) || 0,
      schedule: this.schedule.toPayload(),
      maxMessageLength: this.clampedMessageLength(),
      allowChannelWideMentions: this.allowChannelWide(),
      autoJoin: this.autoJoin(),
      autoJoinOnReply: this.autoJoinOnReply(),
      isPrivate: this.isPrivate(),
      postPermission: this.postPermission(),
      postDiscussions: this.postDiscussions(),
      tagId: this.tagId() ? Number(this.tagId()) : null,
    };

    // `type` só é gravável na criação; na edição seria rejeitado.
    if (!editing) {
      attributes.type = 'category';
    }

    const record = this.attrs.channel ?? app.store.createRecord<Channel>('chat-channels');

    record
      .save(attributes)
      .then(async (channel) => {
        // Antes da sidebar e do `onSaved`, que leem `imageUrl()`.
        if (!editing && this.useImage() && this.pendingImage) {
          await this.attachPendingImage(channel as Channel);
        }

        // O emoji foi salvo, então a imagem sai. Só aqui, para abandonar o form não
        // mudar nada.
        if (editing && !this.useImage() && (channel as Channel).imageUrl()) {
          await this.clearImage();
        }

        if (!editing && !chatState.channels.some((c) => c.id() === channel.id())) {
          // O servidor inscreve o criador; o canal já entra na sidebar.
          chatState.channels.unshift(channel as Channel);
        }

        this.hide();

        if (editing) {
          app.alerts.show({ type: 'success' }, app.translator.trans('ramon-chat.forum.edit_channel.saved'));
        }

        // Só depois de o modal sumir: o ModalManager.animateHide() do core libera a
        // trava `modalClosing` no `transitionend`. Mudar a rota no mesmo tick
        // interrompe a transição e nenhum modal fecha mais até recarregar.
        afterModalClosed(() => this.attrs.onSaved?.(channel as Channel));
      })
      .catch((error: any) => {
        this.loading = false;

        // FormModal.onerror mostra erros de validação no form; o resto vira alerta.
        if (error?.alert) {
          this.onerror(error);
        } else {
          app.alerts.show(
            { type: 'error' },
            error?.response?.errors?.[0]?.detail ??
              app.translator.trans(editing ? 'ramon-chat.forum.edit_channel.failed' : 'ramon-chat.forum.new_channel.failed')
          );

          m.redraw();
        }
      });
  }
}
