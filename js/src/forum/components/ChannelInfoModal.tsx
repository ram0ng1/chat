import app from "flarum/forum/app";
import Modal from "flarum/common/components/Modal";
import type { IInternalModalAttrs } from "flarum/common/components/Modal";
import Button from "flarum/common/components/Button";
import Avatar from "flarum/common/components/Avatar";
import Switch from "flarum/common/components/Switch";
import username from "flarum/common/helpers/username";
import classList from "flarum/common/utils/classList";
import type User from "flarum/common/models/User";
import type Mithril from "mithril";

import userLink from "../utils/userLink";

import type Channel from "../../common/models/Channel";
import { NotificationLevel } from "../../common/models/Channel";
import chatState from "../state/chat";
import { isOnline } from "../utils/presence";
import { MembersSkeleton } from "./Skeletons";
import { channelIcon } from "../utils/channelIcon";
import AddMembersModal from "./AddMembersModal";

export interface ChannelInfoModalAttrs extends IInternalModalAttrs {
  channel: Channel;
}

/**
 * Channel details: notification preferences, the member list, and the moderation
 * actions that change a channel's state.
 *
 * Distinct from ChannelFormModal, which edits what a channel *is* (name, category,
 * threading) and needs the edit permission. This is what any member may see and
 * adjust for themselves, with the state-changing actions gated per capability
 * flag — so a plain member gets a useful panel rather than a locked one.
 */
export default class ChannelInfoModal extends Modal<ChannelInfoModalAttrs> {
  private tab: "settings" | "members" = "settings";
  private members: User[] = [];
  private loadingMembers = false;
  private loadedMembers = false;
  private memberFilter = "";
  private working = false;

  className(): string {
    return "ChatModal ChatChannelInfoModal Modal--medium";
  }

  title(): Mithril.Children {
    const channel = this.attrs.channel;

    return (
      <>
        <span className="ChatChannelInfo-icon">{channelIcon(channel)}</span>
        {channel.displayName()}
      </>
    );
  }

  content(): Mithril.Children {
    return (
      <div className="ChatChannelInfo">
        <div className="ChatChannelInfo-tabs">
          {this.tabButton("settings", "ramon-chat.forum.info.tab_settings")}
          {this.tabButton("members", "ramon-chat.forum.info.tab_members")}
        </div>

        {this.tab === "settings" ? this.settings() : this.memberTab()}
      </div>
    );
  }

  protected tabButton(
    tab: "settings" | "members",
    key: string,
  ): Mithril.Children {
    return (
      <button
        type="button"
        className={classList("ChatChannelInfo-tab", {
          "ChatChannelInfo-tab--active": this.tab === tab,
        })}
        onclick={() => {
          this.tab = tab;

          if (tab === "members") this.loadMembers();
        }}
      >
        {app.translator.trans(key)}
      </button>
    );
  }

  // ── Settings tab ───────────────────────────────────────────────────────────

  protected settings(): Mithril.Children {
    const channel = this.attrs.channel;

    return (
      <div>
        {channel.description() ? (
          <div className="ChatChannelInfo-section">
            <div className="ChatChannelInfo-section-label">
              {app.translator.trans("ramon-chat.forum.info.description")}
            </div>
            <div>{channel.description()}</div>
          </div>
        ) : null}

        <div className="ChatChannelInfo-section">
          <div className="ChatChannelInfo-section-label">
            {app.translator.trans("ramon-chat.forum.info.notifications")}
          </div>

          <label>
            {app.translator.trans("ramon-chat.forum.info.notification_level")}
            <select
              className="FormControl"
              value={String(
                channel.notificationLevel() ?? NotificationLevel.Mentions,
              )}
              onchange={(e: Event) =>
                this.saveNotifications(
                  Number((e.target as HTMLSelectElement).value),
                  null,
                )
              }
            >
              <option value={String(NotificationLevel.Always)}>
                {app.translator.trans(
                  "ramon-chat.forum.info.level_always",
                  {},
                  true,
                )}
              </option>
              <option value={String(NotificationLevel.Mentions)}>
                {app.translator.trans(
                  "ramon-chat.forum.info.level_mentions",
                  {},
                  true,
                )}
              </option>
              <option value={String(NotificationLevel.Never)}>
                {app.translator.trans(
                  "ramon-chat.forum.info.level_never",
                  {},
                  true,
                )}
              </option>
            </select>
          </label>

          {/* What the chosen level actually does. Three named levels with no
              explanation left the difference between "mentions only" and
              "nothing" to be discovered by picking one and waiting. */}
          <div className="helpText">
            {app.translator.trans(this.notificationLevelHelp(channel))}
          </div>

          <Switch
            state={Boolean(channel.isMuted())}
            onchange={(value: boolean) => this.saveNotifications(null, value)}
            disabled={this.working}
          >
            {app.translator.trans("ramon-chat.forum.info.mute")}
          </Switch>

          <div className="helpText">
            {app.translator.trans("ramon-chat.forum.info.mute_help")}
          </div>
        </div>

        {this.moderation()}
      </div>
    );
  }

  /** The line describing the notification level currently selected. */
  protected notificationLevelHelp(channel: Channel): string {
    switch (channel.notificationLevel() ?? NotificationLevel.Mentions) {
      case NotificationLevel.Always:
        return "ramon-chat.forum.info.level_always_help";
      case NotificationLevel.Never:
        return "ramon-chat.forum.info.level_never_help";
      default:
        return "ramon-chat.forum.info.level_mentions_help";
    }
  }

  /**
   * Actions that change the channel for everyone. Each is drawn only when the
   * server said the actor may do it, so nothing here is present-and-rejected.
   */
  protected moderation(): Mithril.Children {
    const channel = this.attrs.channel;
    const items: Mithril.Children[] = [];

    if (channel.canClose()) {
      const closed = channel.status() === "closed";

      items.push(
        <Button
          className="Button"
          icon={closed ? "fas fa-lock-open" : "fas fa-lock"}
          loading={this.working}
          onclick={() => this.setStatus(closed ? "open" : "closed")}
        >
          {app.translator.trans(
            closed
              ? "ramon-chat.forum.info.reopen_channel"
              : "ramon-chat.forum.info.close_channel",
          )}
        </Button>,
      );
    }

    if (channel.canArchive() && !channel.archivedAt()) {
      items.push(
        <Button
          className="Button"
          icon="fas fa-box-archive"
          loading={this.working}
          onclick={() => this.archive()}
        >
          {app.translator.trans("ramon-chat.forum.info.archive_channel")}
        </Button>,
      );
    }

    if (items.length === 0 && !channel.canDelete()) return null;

    return (
      <>
        {items.length > 0 ? (
          <div className="ChatChannelInfo-section">{items}</div>
        ) : null}

        {channel.canDelete() ? (
          <div className="ChatChannelInfo-danger">
            <Button
              className="Button Button--text"
              icon="fas fa-trash"
              onclick={() => this.destroy()}
            >
              {app.translator.trans("ramon-chat.forum.info.delete_channel")}
            </Button>
          </div>
        ) : null}
      </>
    );
  }

  // ── Members tab ────────────────────────────────────────────────────────────

  protected memberTab(): Mithril.Children {
    if (this.loadingMembers) {
      return <div className="ChatChannelInfo-section">{MembersSkeleton()}</div>;
    }

    const term = this.memberFilter.trim().toLowerCase();
    const shown = term
      ? this.members.filter((user) =>
          (user.displayName() + " " + user.username())
            .toLowerCase()
            .includes(term),
        )
      : this.members;

    return (
      <div className="ChatChannelInfo-section">
        <div className="ChatChannelInfo-memberHeader">
          <span className="ChatChannelInfo-memberCount">
            {app.translator.trans("ramon-chat.forum.channel.members", {
              count: this.members.length,
            })}
          </span>

          {/* Opens a picker rather than unfolding a field here: choosing people
              is its own task, and a search box for candidates directly above a
              search box for members made two controls that look identical do
              different things. */}
          {this.attrs.channel.canManageMembers() ? (
            <Button
              className="Button Button--primary Button--compact"
              icon="fas fa-user-plus"
              disabled={this.working}
              onclick={() => this.openAddMembers()}
            >
              {/* The dialog's own title, not `info.add_member`: that string is
                  the phrasing of a search field ("Add someone by name") and
                  reads as an instruction rather than as a button. */}
              {app.translator.trans("ramon-chat.forum.add_members.title")}
            </Button>
          ) : null}
        </div>

        <input
          className="FormControl ChatChannelInfo-filter"
          type="search"
          placeholder={app.translator.trans(
            "ramon-chat.forum.info.member_search",
            {},
            true,
          )}
          value={this.memberFilter}
          oninput={(e: Event) => {
            this.memberFilter = (e.target as HTMLInputElement).value;
          }}
        />

        <div className="ChatChannelInfo-memberList">
          {shown.map((user) => (
            <div
              key={user.id()}
              className={classList("ChatChannelInfo-member", {
                "ChatChannelInfo-member--online": isOnline(user),
              })}
            >
              <Avatar user={user} className="Avatar" />
              <span>{userLink(user)}</span>

              {/* Drawn only for people the actor may actually remove, so the button
                  is never a promise the server refuses to keep. Removing yourself is
                  what "Leave channel" is for, and the endpoint rejects it. */}
              {this.attrs.channel.canManageMembers() &&
              user.id() !== app.session.user?.id() ? (
                <Button
                  className="Button Button--icon Button--flat ChatChannelInfo-member-remove"
                  icon="fas fa-user-minus"
                  disabled={this.working}
                  title={app.translator.trans(
                    "ramon-chat.forum.info.remove_member",
                    {
                      username: username(user),
                    },
                    true,
                  )}
                  onclick={() => this.remove(user)}
                />
              ) : null}
            </div>
          ))}

          {shown.length === 0 ? (
            <div className="ChatBrowse-empty">
              {app.translator.trans("ramon-chat.forum.info.no_members")}
            </div>
          ) : null}
        </div>
      </div>
    );
  }

  /**
   * Opens the people picker.
   *
   * Stacked on top of this modal rather than replacing it: the member list is
   * the context for the choice, and coming back to a closed dialog after adding
   * three people would mean reopening it to check they landed.
   *
   * This is how anyone gets into a private channel: it is not discoverable and
   * cannot be joined, so an existing member with `manageMembers` has to put you
   * there. Offered only when the server says the actor may — a moderator, or the
   * creator of a group conversation.
   */
  protected openAddMembers(): void {
    app.modal.show(
      AddMembersModal,
      {
        channel: this.attrs.channel,
        existing: this.members,
        onAdded: (users: User[]) => this.onMembersAdded(users),
      },
      true,
    );
  }

  /** Folds the picker's result into the list this tab is already showing. */
  protected onMembersAdded(users: User[]): void {
    const known = new Set(this.members.map((member) => member.id()));
    const fresh = users.filter((user) => !known.has(user.id()));

    if (fresh.length === 0) return;

    this.members = [...this.members, ...fresh];

    this.attrs.channel.pushAttributes({
      userCount: (this.attrs.channel.userCount() ?? 0) + fresh.length,
    });

    m.redraw();
  }

  /**
   * Removes someone else from the channel.
   *
   * Confirmed first: unlike adding, this one is not obviously undoable from the
   * other side — a private channel cannot be rejoined, so the person would have to
   * be added back by hand.
   */
  protected async remove(user: User): Promise<void> {
    const confirmed = confirm(
      app.translator.trans(
        "ramon-chat.forum.info.remove_member_confirm",
        { username: username(user) },
        true,
      ),
    );

    if (!confirmed) return;

    this.working = true;
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-channels/${this.attrs.channel.id()}/members/remove`,
        body: { data: { attributes: { userId: Number(user.id()) } } },
      });

      if (payload?.data) app.store.pushPayload(payload);

      this.members = this.members.filter((member) => member.id() !== user.id());

      // Kept in step with the server's own decrement so the count does not sit
      // one high until the next fetch.
      this.attrs.channel.pushAttributes({
        userCount: Math.max(0, (this.attrs.channel.userCount() ?? 1) - 1),
      });

      app.alerts.show(
        { type: "success" },
        app.translator.trans("ramon-chat.forum.info.member_removed", {
          username: username(user),
        }),
      );
    } catch (e: any) {
      app.alerts.show(
        { type: "error" },
        e?.response?.errors?.[0]?.detail ??
          app.translator.trans("ramon-chat.forum.info.save_failed"),
      );
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  protected async loadMembers(): Promise<void> {
    if (this.loadedMembers || this.loadingMembers) return;

    this.loadingMembers = true;

    try {
      // Re-fetched with the relationship included rather than read off the
      // sidebar's copy, which is loaded without participants.
      const channel = (await app.store.find(
        "chat-channels",
        String(this.attrs.channel.id()),
        {
          include: "participants",
        },
      )) as unknown as Channel;

      this.members = (channel.participants() || []).filter(Boolean) as User[];
    } catch {
      this.members = [];
    } finally {
      this.loadingMembers = false;
      this.loadedMembers = true;
      m.redraw();
    }
  }

  // ── Actions ────────────────────────────────────────────────────────────────

  protected async saveNotifications(
    level: number | null,
    muted: boolean | null,
  ): Promise<void> {
    const channel = this.attrs.channel;

    // Optimistic: the endpoint answers 204, so there is nothing to push back and
    // waiting would leave the control visibly lagging the click.
    channel.pushAttributes({
      ...(level !== null ? { notificationLevel: level } : {}),
      ...(muted !== null ? { isMuted: muted } : {}),
    });

    this.working = true;
    m.redraw();

    try {
      await app.request({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-channels/${channel.id()}/notifications`,
        body: {
          data: {
            attributes: {
              ...(level !== null ? { notificationLevel: level } : {}),
              ...(muted !== null ? { muted } : {}),
            },
          },
        },
      });
    } catch {
      app.alerts.show(
        { type: "error" },
        app.translator.trans("ramon-chat.forum.info.save_failed"),
      );
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  protected async setStatus(status: "open" | "closed"): Promise<void> {
    await this.act(`/chat-channels/${this.attrs.channel.id()}/status`, {
      status,
    });
  }

  protected async archive(): Promise<void> {
    await this.act(`/chat-channels/${this.attrs.channel.id()}/archive`, {});
  }

  protected async destroy(): Promise<void> {
    if (
      !confirm(
        app.translator.trans("ramon-chat.forum.info.delete_confirm", {}, true),
      )
    )
      return;

    this.working = true;

    try {
      await this.attrs.channel.delete();

      chatState.channels = chatState.channels.filter(
        (c) => c.id() !== this.attrs.channel.id(),
      );

      if (chatState.activeChannelId === Number(this.attrs.channel.id())) {
        chatState.setActiveChannel(null);
      }

      this.hide();
    } catch {
      app.alerts.show(
        { type: "error" },
        app.translator.trans("ramon-chat.forum.info.save_failed"),
      );
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  protected async act(
    path: string,
    attributes: Record<string, unknown>,
  ): Promise<void> {
    this.working = true;
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}${path}`,
        body: { data: { attributes } },
      });

      if (payload?.data) app.store.pushPayload(payload);

      this.hide();
    } catch (e: any) {
      app.alerts.show(
        { type: "error" },
        e?.response?.errors?.[0]?.detail ??
          app.translator.trans("ramon-chat.forum.info.save_failed"),
      );
    } finally {
      this.working = false;
      m.redraw();
    }
  }
}
