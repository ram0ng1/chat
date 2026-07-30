import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Avatar from 'flarum/common/components/Avatar';
import Button from 'flarum/common/components/Button';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import { displayEmoji } from '../utils/emoji';

import type Channel from '../../common/models/Channel';
import type ChatState from '../state/ChatState';
import ChannelFormModal from './ChannelFormModal';

export interface ChatSidebarAttrs extends ComponentAttrs {
  state: ChatState;
  onSelect?: (channel: Channel) => void;
}

/**
 * Channel navigation: My Threads, Search, then the Channels and Direct Messages
 * sections — the same order and grouping as Discourse's chat sidebar.
 */
export default class ChatSidebar extends Component<ChatSidebarAttrs> {
  view(): Mithril.Children {
    const { state } = this.attrs;

    return (
      <div className="ChatSidebar">
        <div className="ChatSidebar-top">
          <button
            type="button"
            className="ChatSidebar-quickLink"
            onclick={() => m.route.set(app.route('chat.threads'))}
          >
            <i className="ChatSidebar-quickLink-icon fas fa-comments" aria-hidden="true" />
            <span>{app.translator.trans('ramon-chat.forum.sidebar.my_threads')}</span>
          </button>

          <button
            type="button"
            className="ChatSidebar-quickLink"
            onclick={() => m.route.set(app.route('chat.search'))}
          >
            <i className="ChatSidebar-quickLink-icon fas fa-magnifying-glass" aria-hidden="true" />
            <span>{app.translator.trans('ramon-chat.forum.sidebar.search')}</span>
          </button>
        </div>

        <div className="ChatSidebar-scroll">
          {state.channelsLoading && !state.channelsLoaded ? (
            <LoadingIndicator />
          ) : (
            <>
              {this.section(
                'ramon-chat.forum.sidebar.channels',
                state.categoryChannels(),
                {
                  icon: 'fas fa-pencil',
                  title: app.translator.trans('ramon-chat.forum.sidebar.browse_channels', {}, true),
                  action: () => m.route.set(app.route('chat.browse')),
                }
              )}

              {this.section(
                'ramon-chat.forum.sidebar.direct_messages',
                state.directChannels(),
                app.forum.attribute<boolean>('canStartChatDirect')
                  ? {
                      icon: 'fas fa-plus',
                      title: app.translator.trans('ramon-chat.forum.sidebar.new_direct_message', {}, true),
                      action: () => this.startDirect(),
                    }
                  : null
              )}

              {/* An empty sidebar has to offer a way out, or a fresh install is a
                  dead end: no channels to open and no visible way to make one. */}
              {state.channelsLoaded && state.channels.length === 0 ? (
                <div className="ChatSidebar-empty">
                  <p>{app.translator.trans('ramon-chat.forum.sidebar.no_channels')}</p>

                  <Button className="Button Button--primary Button--block" icon="fas fa-compass" onclick={() => m.route.set(app.route('chat.browse'))}>
                    {app.translator.trans('ramon-chat.forum.sidebar.browse_channels')}
                  </Button>

                  {app.forum.attribute<boolean>('canCreateChatChannel') ? (
                    <Button className="Button Button--block" icon="fas fa-plus" onclick={() => this.createChannel()}>
                      {app.translator.trans('ramon-chat.forum.sidebar.new_channel')}
                    </Button>
                  ) : null}
                </div>
              ) : null}
            </>
          )}
        </div>
      </div>
    );
  }

  protected section(
    labelKey: string,
    channels: Channel[],
    action: { icon: string; title: string; action: () => void } | null
  ): Mithril.Children {
    // An empty section with no affordance is pure noise; hide it.
    if (channels.length === 0 && !action) return null;

    return (
      <div className="ChatSidebar-section">
        <div className="ChatSidebar-sectionHeader">
          <span>{app.translator.trans(labelKey)}</span>
          {action ? (
            <button
              type="button"
              className="ChatSidebar-sectionHeader-action"
              title={action.title}
              aria-label={action.title}
              onclick={action.action}
            >
              <i className={action.icon} aria-hidden="true" />
            </button>
          ) : null}
        </div>

        {channels.map((channel) => this.row(channel))}
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
        })}
        onclick={() => onSelect?.(channel)}
      >
        {channel.isDirect() ? this.avatars(channel) : (
          <span className="ChatChannelRow-icon">
            {channel.emoji() ? displayEmoji(channel.emoji()) : <i className="fas fa-hashtag" aria-hidden="true" />}
          </span>
        )}

        <span className="ChatChannelRow-name">{channel.displayName()}</span>

        {/* A count only for mentions; ambient unreads get a plain dot-less badge. */}
        {mentions > 0 ? (
          <span className="ChatChannelRow-badge ChatChannelRow-badge--mention">{mentions > 99 ? '99+' : mentions}</span>
        ) : unread ? (
          <span className="ChatChannelRow-badge">{channel.unreadCount()}</span>
        ) : null}
      </button>
    );
  }

  /** Stacked avatars of the other participants, for a direct channel. */
  protected avatars(channel: Channel): Mithril.Children {
    const actorId = app.session.user?.id();

    // `hasMany` yields `false` when the relationship was not included.
    const participants = channel.participants() || [];

    const others = participants
      .filter((user): user is NonNullable<typeof user> => Boolean(user) && user!.id() !== actorId)
      .slice(0, 2);

    if (others.length === 0) {
      return (
        <span className="ChatChannelRow-icon">
          <i className="fas fa-envelope" aria-hidden="true" />
        </span>
      );
    }

    return <span className="ChatChannelRow-avatars">{others.map((user) => <Avatar user={user} className="Avatar" />)}</span>;
  }

  protected createChannel(): void {
    app.modal.show(ChannelFormModal, {
      onSaved: (channel: Channel) => {
        this.attrs.state.setActiveChannel(Number(channel.id()));
        m.route.set(app.route('chat.channel', { id: channel.id() }));
      },
    });
  }

  protected startDirect(): void {
    // NewDirectMessageModal is phase 3. The affordance is hidden until then
    // rather than shown as a no-op — see ChatSidebar-sectionHeader-action above,
    // which is gated on canStartChatDirect.
  }
}
