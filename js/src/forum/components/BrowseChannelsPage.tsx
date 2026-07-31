import app from "flarum/forum/app";
import Page from "flarum/common/components/Page";
import type { IPageAttrs } from "flarum/common/components/Page";
import Button from "flarum/common/components/Button";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import chatState from "../state/chat";
import ChannelFormModal from "./ChannelFormModal";
import { BrowseSkeleton } from "./Skeletons";
import { channelIcon } from "../utils/channelIcon";
import { mobileTitleControl } from "../utils/toolbar";

type BrowseFilter = "all" | "open" | "closed" | "archived" | "mine";

/**
 * Discourse's "Browse channels" page: every channel the actor can see, with join
 * controls and — for those who may — a way to create one.
 *
 * This is also the only place a channel can be created, so it is reachable from
 * the sidebar even when the actor has joined nothing.
 */
export default class BrowseChannelsPage<
  CustomAttrs extends IPageAttrs = IPageAttrs,
> extends Page<CustomAttrs> {
  private channels: Channel[] = [];
  private loading = true;
  private filter: BrowseFilter = "all";
  private query = "";
  private searchTimer: number | null = null;

  oninit(vnode: Mithril.Vnode<CustomAttrs>): void {
    super.oninit(vnode);

    app.setTitle(
      app.translator.trans("ramon-chat.forum.browse.title", {}, true),
    );

    const routeFilter = m.route.param("filter") as BrowseFilter | undefined;

    if (
      routeFilter &&
      ["all", "open", "closed", "archived", "mine"].includes(routeFilter)
    ) {
      this.filter = routeFilter;
    }

    this.load();
  }

  view(): Mithril.Children {
    return (
      <div className="ChatBrowse">
        {mobileTitleControl(
          app.translator.trans("ramon-chat.forum.browse.title", {}, true),
        )}

        {/* The back link sits above the title rather than beside it: it is
            navigation, not a peer of the page's primary action, and putting the
            three on one row made the title collide with the buttons as soon as the
            viewport narrowed. */}
        <Button
          className="Button Button--link ChatBrowse-back"
          icon="fas fa-arrow-left"
          onclick={() => m.route.set(app.route("chat.index"))}
        >
          {app.translator.trans("ramon-chat.forum.nav.chat")}
        </Button>

        <div className="ChatBrowse-header">
          <h1 className="ChatBrowse-title">
            {app.translator.trans("ramon-chat.forum.browse.title")}
          </h1>

          {app.forum.attribute<boolean>("canCreateChatChannel") ? (
            <Button
              className="Button Button--primary"
              icon="fas fa-plus"
              onclick={() => this.create()}
            >
              {app.translator.trans("ramon-chat.forum.browse.new_channel")}
            </Button>
          ) : null}
        </div>

        <div className="ChatBrowse-toolbar">
          <div className="ChatBrowse-filters">
            {(
              ["all", "open", "closed", "archived", "mine"] as BrowseFilter[]
            ).map((f) => (
              <button
                key={f}
                type="button"
                className={classList("ChatBrowse-filter", {
                  "ChatBrowse-filter--active": this.filter === f,
                })}
                onclick={() => this.setFilter(f)}
              >
                {app.translator.trans(`ramon-chat.forum.browse.filter_${f}`)}
              </button>
            ))}
          </div>

          <div className="ChatBrowse-search">
            <i
              className="ChatBrowse-search-icon fas fa-magnifying-glass"
              aria-hidden="true"
            />
            <input
              className="ChatBrowse-search-input"
              type="search"
              placeholder={app.translator.trans(
                "ramon-chat.forum.browse.search_placeholder",
                {},
                true,
              )}
              value={this.query}
              oninput={(e: Event) =>
                this.onSearch((e.target as HTMLInputElement).value)
              }
            />
          </div>
        </div>

        {this.loading ? (
          BrowseSkeleton()
        ) : this.channels.length === 0 ? (
          <div className="ChatBrowse-empty">
            {app.translator.trans("ramon-chat.forum.browse.empty")}
          </div>
        ) : (
          <div className="ChatBrowse-list">
            {this.channels.map((channel) => this.card(channel))}
          </div>
        )}
      </div>
    );
  }

  protected card(channel: Channel): Mithril.Children {
    return (
      <div className="ChatBrowseCard" key={channel.id()}>
        <div className="ChatBrowseCard-icon">{channelIcon(channel)}</div>

        <div className="ChatBrowseCard-body">
          <div className="ChatBrowseCard-name">
            <span>{channel.displayName()}</span>

            {channel.isPrivate() ? (
              <span className="ChatBrowseCard-status">
                <i
                  className="ChatBrowseCard-lock fas fa-lock"
                  aria-hidden="true"
                />{" "}
                {app.translator.trans("ramon-chat.forum.new_channel.private")}
              </span>
            ) : null}
            {channel.isOpen() ? null : (
              <span className="ChatBrowseCard-status">
                {app.translator.trans(
                  channel.isArchived()
                    ? "ramon-chat.forum.browse.filter_archived"
                    : "ramon-chat.forum.browse.filter_closed",
                )}
              </span>
            )}
          </div>

          {channel.description() ? (
            <div className="ChatBrowseCard-description">
              {channel.description()}
            </div>
          ) : null}

          <div className="ChatBrowseCard-meta">
            <span>
              {app.translator.trans("ramon-chat.forum.channel.members", {
                count: channel.userCount() ?? 0,
              })}
            </span>
            {/* Was a hardcoded "msg" — the one untranslated string on the page. */}
            <span>
              {app.translator.trans("ramon-chat.forum.browse.messages", {
                count: channel.messagesCount() ?? 0,
              })}
            </span>
            {channel.threadingEnabled() ? (
              <span>
                {app.translator.trans(
                  "ramon-chat.forum.channel.threading_enabled",
                )}
              </span>
            ) : null}
          </div>
        </div>

        <div className="ChatBrowseCard-action">
          {channel.canEdit() && channel.isCategory() ? (
            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-pen-to-square"
              title={app.translator.trans(
                "ramon-chat.forum.channel.edit",
                {},
                true,
              )}
              onclick={() => this.edit(channel)}
            />
          ) : null}

          {channel.isFollowing() ? (
            <Button className="Button" onclick={() => this.open(channel)}>
              {app.translator.trans("ramon-chat.forum.nav.chat")}
            </Button>
          ) : channel.canJoin() ? (
            <Button
              className="Button Button--primary"
              onclick={() => this.join(channel)}
            >
              {app.translator.trans("ramon-chat.forum.channel.join")}
            </Button>
          ) : null}
        </div>
      </div>
    );
  }

  // ── Behaviour ──────────────────────────────────────────────────────────────

  protected setFilter(filter: BrowseFilter): void {
    this.filter = filter;
    this.load();
  }

  /** Debounced so typing does not issue a request per keystroke. */
  protected onSearch(value: string): void {
    this.query = value;

    if (this.searchTimer !== null) window.clearTimeout(this.searchTimer);

    this.searchTimer = window.setTimeout(() => this.load(), 350);
  }

  protected async load(): Promise<void> {
    this.loading = true;
    m.redraw();

    const filter: Record<string, unknown> = {};

    // 'all' deliberately sends no status filter: closed and archived channels are
    // browsable, they are simply frozen.
    if (
      this.filter === "open" ||
      this.filter === "closed" ||
      this.filter === "archived"
    ) {
      filter.status = this.filter;
    }

    if (this.filter === "mine") {
      filter.following = true;
    }

    if (this.query.trim() !== "") {
      filter.q = this.query.trim();
    }

    try {
      const results = (await app.store.find("chat-channels", {
        filter,
        sort: "-lastMessageAt",
        page: { limit: 50 },
      })) as unknown as Channel[];

      // Direct channels are not browsable — they are private by construction.
      this.channels = (Array.isArray(results) ? results : []).filter((c) =>
        c.isCategory(),
      );
    } catch (e) {
      this.channels = [];
      app.alerts.show(
        { type: "error" },
        app.translator.trans("ramon-chat.forum.browse.empty"),
      );
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  protected create(): void {
    app.modal.show(ChannelFormModal, {
      onSaved: (channel: Channel) => this.open(channel),
    });
  }

  protected edit(channel: Channel): void {
    // Reload after saving: a renamed or re-categorised channel may no longer
    // match the active filter or search.
    app.modal.show(ChannelFormModal, { channel, onSaved: () => this.load() });
  }

  protected async join(channel: Channel): Promise<void> {
    try {
      await app.request({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-channels/${channel.id()}/join`,
      });

      channel.pushAttributes({
        isFollowing: true,
        userCount: (channel.userCount() ?? 0) + 1,
      });

      if (!chatState.channels.some((c) => c.id() === channel.id())) {
        chatState.channels.unshift(channel);
      }

      m.redraw();
    } catch (e: any) {
      const detail = e?.response?.errors?.[0]?.detail;
      app.alerts.show(
        { type: "error" },
        detail ?? app.translator.trans("ramon-chat.forum.channel.join"),
      );
    }
  }

  protected open(channel: Channel): void {
    chatState.setActiveChannel(Number(channel.id()));
    m.route.set(app.route("chat.channel", { id: channel.id() }));
  }
}
