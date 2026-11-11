import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Tooltip from 'flarum/common/components/Tooltip';
import classList from 'flarum/common/utils/classList';
import { realtimeLive } from '../realtime';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import type ChatState from '../state/ChatState';
import { SidebarSkeleton } from './Skeletons';
import { channelIcon } from '../utils/channelIcon';
import { loadChannelFormModal, loadNewDirectModal } from '../utils/lazy';
import { isOnline } from '../utils/presence';
import { closedNotice } from '../utils/schedule';

export interface ChatSidebarAttrs extends ComponentAttrs {
  state: ChatState;
  onSelect?: (channel: Channel) => void;
}

/** An icon button in a section header. */
interface SectionAction {
  icon: string;
  title: string;
  action: () => void;
}

/** O que a seção mostra quando não tem nenhum canal. */
interface SectionEmpty {
  text: string;
  cta: string;
  action: () => void;
}

/** Quantos canais não lidos pré-carregar em segundo plano por página. */
const WARM_LIMIT = 3;

/** Canais já pré-carregados, para remontar a sidebar não repetir. */
const warmed = new Set<number>();

function whenIdle(fn: () => void, fallbackMs: number): void {
  const idle = (
    window as Window & {
      requestIdleCallback?: (cb: () => void, o?: { timeout: number }) => number;
    }
  ).requestIdleCallback;

  if (idle) {
    window.setTimeout(() => idle(fn, { timeout: 2000 }), fallbackMs);
  } else {
    window.setTimeout(fn, fallbackMs);
  }
}

/** Navegação: Minhas threads, Busca, depois Canais e Mensagens diretas. */
export default class ChatSidebar extends Component<ChatSidebarAttrs> {
  /** Timer de intenção de hover pendente (ver handlers da linha do canal). */
  private prefetchTimer: number | null = null;

  oncreate(vnode: Mithril.VnodeDOM<ChatSidebarAttrs, this>): void {
    super.oncreate(vnode);

    this.warmUnread();
  }

  onupdate(vnode: Mithril.VnodeDOM<ChatSidebarAttrs, this>): void {
    super.onupdate(vnode);

    this.warmUnread();
  }

  onremove(vnode: Mithril.VnodeDOM<ChatSidebarAttrs, this>): void {
    super.onremove(vnode);

    this.cancelPrefetch();
  }

  /**
   * Pré-carrega os não lidos (menções primeiro) com o navegador ocioso. Só com o
   * websocket ativo, que mantém atualizado o stream carregado em segundo plano.
   */
  protected warmUnread(): void {
    const { state } = this.attrs;

    if (!state.channelsLoaded || !realtimeLive()) return;

    const candidates = [...state.categoryChannels(), ...state.directChannels()]
      .filter((channel) => channel.hasUnread() && Number(channel.id()) !== state.activeChannelId && !warmed.has(Number(channel.id())))
      .sort((a, b) => (b.unreadMentionsCount() ?? 0) - (a.unreadMentionsCount() ?? 0))
      .slice(0, Math.max(0, WARM_LIMIT - warmed.size));

    candidates.forEach((channel, index) => {
      const id = Number(channel.id());

      warmed.add(id);
      whenIdle(() => state.prefetchChannel(id), 400 * (index + 1));
    });
  }

  view(): Mithril.Children {
    const { state } = this.attrs;

    return (
      <div className="ChatSidebar">
        <div className="ChatSidebar-top">
          {this.quickLink('chat.threads', 'fas fa-comments', 'ramon-chat.forum.sidebar.my_threads')}
          {this.quickLink('chat.bookmarks', 'fas fa-bookmark', 'ramon-chat.forum.sidebar.bookmarks')}
          {this.quickLink('chat.search', 'fas fa-magnifying-glass', 'ramon-chat.forum.sidebar.search')}

          {/* Só moderadores. A contagem vem do servidor, antes de abrir a fila. */}
          {app.forum.attribute<boolean>('canModerateChat')
            ? this.quickLink(
                'chat.flags',
                'fas fa-flag',
                'ramon-chat.forum.sidebar.flags',
                Number(app.forum.attribute<number>('chatOpenFlagsCount') ?? 0)
              )
            : null}
        </div>

        <div className="ChatSidebar-scroll">
          {state.channelsLoading && !state.channelsLoaded ? (
            SidebarSkeleton()
          ) : (
            <>
              {this.section('ramon-chat.forum.sidebar.channels', state.categoryChannels(), [
                {
                  // Lupa, não lápis: a ação é explorar, não editar.
                  icon: 'fas fa-magnifying-glass',
                  title: app.translator.trans('ramon-chat.forum.sidebar.browse_channels', {}, true),
                  action: () => m.route.set(app.route('chat.browse')),
                },

                // Mesma permissão do botão da página de explorar.
                ...(app.forum.attribute<boolean>('canCreateChatChannel')
                  ? [
                      {
                        icon: 'fas fa-plus',
                        title: app.translator.trans('ramon-chat.forum.sidebar.new_channel', {}, true),
                        action: () => this.createChannel(),
                      },
                    ]
                  : []),
              ])}

              {/* Mesma permissão do servidor para abrir conversas. Com a ação, a seção
                  aparece mesmo sem conversas, para a primeira ter por onde começar. */}
              {this.section(
                'ramon-chat.forum.sidebar.direct_messages',
                state.directChannels(),
                app.forum.attribute<boolean>('canStartChatDirect')
                  ? [
                      {
                        icon: 'fas fa-paper-plane',
                        title: app.translator.trans('ramon-chat.forum.sidebar.new_direct', {}, true),
                        action: () => this.startDirect(),
                      },
                    ]
                  : [],
                // Como no Canela: sem conversas, a seção diz isso e oferece a primeira.
                // Sidebar vazia de tudo já tem o próprio aviso, logo abaixo.
                app.forum.attribute<boolean>('canStartChatDirect') && state.channels.length > 0
                  ? {
                      text: app.translator.trans('ramon-chat.forum.sidebar.no_direct', {}, true),
                      cta: app.translator.trans('ramon-chat.forum.sidebar.new_direct', {}, true),
                      action: () => this.startDirect(),
                    }
                  : undefined
              )}

              {/* Sidebar vazia oferece saída, senão uma instalação nova fica sem caminho. */}
              {state.channelsLoaded && state.channels.length === 0 ? (
                <div className="ChatSidebar-empty">
                  <div className="ChatSidebar-empty-icon" aria-hidden="true">
                    <i className="fas fa-comments" />
                  </div>

                  <p>{app.translator.trans('ramon-chat.forum.sidebar.no_channels')}</p>

                  {/* Contêiner próprio com gap: `Button--block` não dá margem e no Avocado
                      os botões ficavam colados. */}
                  <div className="ChatSidebar-empty-actions">
                    <Button
                      className="Button Button--primary Button--block"
                      icon="fas fa-compass"
                      onclick={() => m.route.set(app.route('chat.browse'))}
                    >
                      {app.translator.trans('ramon-chat.forum.sidebar.browse_channels')}
                    </Button>

                    {app.forum.attribute<boolean>('canCreateChatChannel') ? (
                      <Button className="Button Button--block" icon="fas fa-plus" onclick={() => this.createChannel()}>
                        {app.translator.trans('ramon-chat.forum.sidebar.new_channel')}
                      </Button>
                    ) : null}
                  </div>
                </div>
              ) : null}
            </>
          )}
        </div>
      </div>
    );
  }

  /** Um dos atalhos acima da lista de canais, com estado ativo. */
  protected quickLink(routeName: string, icon: string, key: string, badge?: number): Mithril.Children {
    // Lido do router porque voltar/avançar do navegador também muda a rota.
    // `?? ''`: dentro do drawer a sidebar pode renderizar antes de o router
    // resolver uma rota, e `m.route.get()` ainda é undefined.
    const current = m.route.get() ?? '';
    const active = current.split('?')[0] === app.route(routeName);

    return (
      <button
        type="button"
        className={classList('ChatSidebar-quickLink', {
          'ChatSidebar-quickLink--active': active,
        })}
        aria-current={active ? 'page' : undefined}
        onclick={() => m.route.set(app.route(routeName))}
      >
        <i className={`ChatSidebar-quickLink-icon ${icon}`} aria-hidden="true" />
        <span>{app.translator.trans(key)}</span>

        {/* Sem badge zerado. */}
        {badge ? <span className="ChatSidebar-quickLink-badge">{badge > 99 ? '99+' : badge}</span> : null}
      </button>
    );
  }

  /** Grupo de canais com título e ações (explorar, criar). */
  protected section(labelKey: string, channels: Channel[], actions: SectionAction[], empty?: SectionEmpty): Mithril.Children {
    if (channels.length === 0 && actions.length === 0) return null;

    return (
      <div className="ChatSidebar-section">
        <div className="ChatSidebar-sectionHeader">
          <span>{app.translator.trans(labelKey)}</span>
          {actions.map((action) => (
            <Tooltip key={action.icon} text={action.title}>
              <button type="button" className="ChatSidebar-sectionHeader-action" aria-label={action.title} onclick={action.action}>
                <i className={action.icon} aria-hidden="true" />
              </button>
            </Tooltip>
          ))}
        </div>

        {channels.map((channel) => this.row(channel))}

        {channels.length === 0 && empty ? (
          <div className="ChatSidebar-sectionEmpty">
            <p>{empty.text}</p>
            <Button className="Button Button--block" icon="fas fa-paper-plane" onclick={empty.action}>
              {empty.cta}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  protected row(channel: Channel): Mithril.Children {
    const { state, onSelect } = this.attrs;

    const active = state.activeChannelId === Number(channel.id());
    const mentions = channel.unreadMentionsCount() ?? 0;
    const unread = channel.hasUnread();

    return (
      <button
        type="button"
        key={channel.id()}
        className={classList('ChatChannelRow', {
          'ChatChannelRow--active': active,
          'ChatChannelRow--muted': channel.isMuted(),
          'ChatChannelRow--online': channel.isDirect() && isOnline(channel.others()[0]),
        })}
        onclick={() => onSelect?.(channel)}
        // Intenção de hover: passar o ponteiro pela lista não pré-carrega cada canal.
        onpointerenter={() => this.schedulePrefetch(channel)}
        onpointerleave={() => this.cancelPrefetch()}
        // Ganha ~100ms antes do `click`; em touch é a única antecipação.
        onpointerdown={() => this.attrs.state.prefetchChannel(Number(channel.id()))}
        // Mesma antecipação para quem navega pelo teclado.
        onfocus={() => this.attrs.state.prefetchChannel(Number(channel.id()))}
      >
        {channelIcon(channel, 'ChatChannelRow-icon')}

        <span className="ChatChannelRow-name">{channel.displayName()}</span>

        {/* Marca o canal privado na lista para o membro saber que não é público. */}
        {channel.isPrivate() ? (
          <i
            className="ChatChannelRow-lock fas fa-lock"
            title={app.translator.trans('ramon-chat.forum.new_channel.private', {}, true)}
            aria-hidden="true"
          />
        ) : null}

        {/* Inspecionando: participação que o canal não vê. */}
        {channel.isHiddenMember() ? (
          <i
            className="ChatChannelRow-lock fas fa-user-secret"
            title={app.translator.trans('ramon-chat.forum.browse.inspecting', {}, true)}
            aria-hidden="true"
          />
        ) : null}

        {/* Fora do horário: o membro sabe antes de abrir que não vai poder escrever. */}
        {channel.scheduleOpen() === false && !channel.isClosed() ? (
          <i className="ChatChannelRow-lock fas fa-clock" title={closedNotice(channel)} aria-hidden="true" />
        ) : null}

        {/* Ícone de silenciado: só a linha esmaecida se confundiria com "inativo". */}
        {channel.isMuted() ? (
          <i
            className="ChatChannelRow-mute fas fa-bell-slash"
            title={app.translator.trans('ramon-chat.forum.channel.muted', {}, true)}
            aria-hidden="true"
          />
        ) : null}

        {/* Número só para menções; as demais não lidas ganham badge simples. */}
        {mentions > 0 ? (
          <span className="ChatChannelRow-badge ChatChannelRow-badge--mention">{mentions > 99 ? '99+' : mentions}</span>
        ) : unread ? (
          <span className="ChatChannelRow-badge">{channel.unreadCount()}</span>
        ) : null}
      </button>
    );
  }

  /**
   * Pré-carrega o canal quando o ponteiro para na linha. Substitui o timer
   * pendente da linha anterior.
   */
  protected schedulePrefetch(channel: Channel): void {
    this.cancelPrefetch();

    this.prefetchTimer = window.setTimeout(() => {
      this.prefetchTimer = null;
      this.attrs.state.prefetchChannel(Number(channel.id()));
    }, 120);
  }

  protected cancelPrefetch(): void {
    if (this.prefetchTimer === null) return;

    window.clearTimeout(this.prefetchTimer);
    this.prefetchTimer = null;
  }

  /** Escolhe com quem conversar; o modal abre a conversa. */
  protected startDirect(): void {
    app.modal.show(loadNewDirectModal);
  }

  protected createChannel(): void {
    app.modal.show(loadChannelFormModal, {
      onSaved: (channel: Channel) => {
        this.attrs.state.setActiveChannel(Number(channel.id()));
        m.route.set(app.route('chat.channel', { id: channel.id() }));
      },
    });
  }
}
