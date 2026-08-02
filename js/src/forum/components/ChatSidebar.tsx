import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Avatar from "flarum/common/components/Avatar";
import Button from "flarum/common/components/Button";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import type ChatState from "../state/ChatState";
import { SidebarSkeleton } from "./Skeletons";
import ChannelFormModal from "./ChannelFormModal";
import { channelIcon } from "../utils/channelIcon";

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
          {this.quickLink(
            "chat.threads",
            "fas fa-comments",
            "ramon-chat.forum.sidebar.my_threads",
          )}
          {this.quickLink(
            "chat.bookmarks",
            "fas fa-bookmark",
            "ramon-chat.forum.sidebar.bookmarks",
          )}
          {this.quickLink(
            "chat.search",
            "fas fa-magnifying-glass",
            "ramon-chat.forum.sidebar.search",
          )}

          {/* Moderators only, and the count comes from the server rather than
              from the list — the link has to be able to say there is work waiting
              before anyone opens the queue. */}
          {app.forum.attribute<boolean>("canModerateChat")
            ? this.quickLink(
                "chat.flags",
                "fas fa-flag",
                "ramon-chat.forum.sidebar.flags",
                Number(app.forum.attribute<number>("chatOpenFlagsCount") ?? 0),
              )
            : null}
        </div>

        <div className="ChatSidebar-scroll">
          {state.channelsLoading && !state.channelsLoaded ? (
            SidebarSkeleton()
          ) : (
            <>
              {this.section(
                "ramon-chat.forum.sidebar.channels",
                state.categoryChannels(),
                {
                  // A magnifying glass, not a pencil: the action is "browse
                  // channels", and a pencil promises editing.
                  icon: "fas fa-magnifying-glass",
                  title: app.translator.trans(
                    "ramon-chat.forum.sidebar.browse_channels",
                    {},
                    true,
                  ),
                  action: () => m.route.set(app.route("chat.browse")),
                },
              )}

              {this.section(
                "ramon-chat.forum.sidebar.direct_messages",
                state.directChannels(),
                null,
              )}

              {/* An empty sidebar has to offer a way out, or a fresh install is a
                  dead end: no channels to open and no visible way to make one. */}
              {state.channelsLoaded && state.channels.length === 0 ? (
                <div className="ChatSidebar-empty">
                  <div className="ChatSidebar-empty-icon" aria-hidden="true">
                    <i className="fas fa-comments" />
                  </div>

                  <p>
                    {app.translator.trans(
                      "ramon-chat.forum.sidebar.no_channels",
                    )}
                  </p>

                  {/* The actions are their own element rather than loose
                      children of the empty state: `Button--block` only sets a
                      width, so two of them are separated by whatever margin the
                      theme's button happens to carry — which on Avocado is
                      none, and the two buttons touched. A flex column with an
                      explicit gap owns the spacing here instead of inheriting
                      it. */}
                  <div className="ChatSidebar-empty-actions">
                    <Button
                      className="Button Button--primary Button--block"
                      icon="fas fa-compass"
                      onclick={() => m.route.set(app.route("chat.browse"))}
                    >
                      {app.translator.trans(
                        "ramon-chat.forum.sidebar.browse_channels",
                      )}
                    </Button>

                    {app.forum.attribute<boolean>("canCreateChatChannel") ? (
                      <Button
                        className="Button Button--block"
                        icon="fas fa-plus"
                        onclick={() => this.createChannel()}
                      >
                        {app.translator.trans(
                          "ramon-chat.forum.sidebar.new_channel",
                        )}
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

  /**
   * One of the views above the channel list.
   *
   * Written once rather than three times, which is what makes the active state
   * affordable: three hand-rolled buttons had no way to say which view was open,
   * so the sidebar looked identical whether you were reading a channel, your
   * threads or your bookmarks.
   */
  protected quickLink(
    routeName: string,
    icon: string,
    key: string,
    badge?: number,
  ): Mithril.Children {
    // Read from the router rather than from a stored value: the route can also
    // change from browser back/forward, which no click handler observes.
    //
    // `?? ""` because `m.route.get()` is typed as a string and is not one until
    // the router has resolved a route. The sidebar also draws inside the drawer,
    // which mounts on its own root over whatever page is open, so it can render
    // before that — and an exception here takes down the entire Mithril tree,
    // not just this button.
    const current = m.route.get() ?? "";
    const active = current.split("?")[0] === app.route(routeName);

    return (
      <button
        type="button"
        className={classList("ChatSidebar-quickLink", {
          "ChatSidebar-quickLink--active": active,
        })}
        aria-current={active ? "page" : undefined}
        onclick={() => m.route.set(app.route(routeName))}
      >
        <i
          className={`ChatSidebar-quickLink-icon ${icon}`}
          aria-hidden="true"
        />
        <span>{app.translator.trans(key)}</span>

        {/* Only when there is something to report. A badge reading zero is noise
            that trains people to stop looking at it. */}
        {badge ? (
          <span className="ChatSidebar-quickLink-badge">
            {badge > 99 ? "99+" : badge}
          </span>
        ) : null}
      </button>
    );
  }

  protected section(
    labelKey: string,
    channels: Channel[],
    action: { icon: string; title: string; action: () => void } | null,
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
        className={classList("ChatChannelRow", {
          "ChatChannelRow--active": active,
          "ChatChannelRow--muted": channel.isMuted(),
        })}
        onclick={() => onSelect?.(channel)}
      >
        {channel.isDirect() ? (
          this.avatars(channel)
        ) : (
          <span className="ChatChannelRow-icon">{channelIcon(channel)}</span>
        )}

        <span className="ChatChannelRow-name">{channel.displayName()}</span>

        {/* Private channels are marked where they are used, not only in their
            settings — otherwise a member has no way to tell that what they say
            here is not visible to the rest of the forum. */}
        {channel.isPrivate() ? (
          <i
            className="ChatChannelRow-lock fas fa-lock"
            title={app.translator.trans(
              "ramon-chat.forum.new_channel.private",
              {},
              true,
            )}
            aria-hidden="true"
          />
        ) : null}

        {/* A count only for mentions; ambient unreads get a plain dot-less badge. */}
        {mentions > 0 ? (
          <span className="ChatChannelRow-badge ChatChannelRow-badge--mention">
            {mentions > 99 ? "99+" : mentions}
          </span>
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
      .filter(
        (user): user is NonNullable<typeof user> =>
          Boolean(user) && user!.id() !== actorId,
      )
      .slice(0, 2);

    if (others.length === 0) {
      return (
        <span className="ChatChannelRow-icon">
          <i className="fas fa-envelope" aria-hidden="true" />
        </span>
      );
    }

    return (
      <span className="ChatChannelRow-avatars">
        {others.map((user) => (
          <Avatar user={user} className="Avatar" />
        ))}
      </span>
    );
  }

  protected createChannel(): void {
    app.modal.show(ChannelFormModal, {
      onSaved: (channel: Channel) => {
        this.attrs.state.setActiveChannel(Number(channel.id()));
        m.route.set(app.route("chat.channel", { id: channel.id() }));
      },
    });
  }
}
