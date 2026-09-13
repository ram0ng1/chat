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
import { sendKeyPreference, type SendKey } from "../utils/shortcuts";
import AddMembersModal from "./AddMembersModal";
import iconLabel from "../utils/iconLabel";

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
  /** People invited and not yet answered. Served to managers only. */
  private invited: User[] = [];
  private loadingMembers = false;
  private loadedMembers = false;
  private memberFilter = "";
  private working = false;

  /** Stops listening for membership pushes; set while the modal is open. */
  private stopListening: (() => void) | null = null;

  /**
   * Which immediate action is running, or null.
   *
   * `working` answers "is anything in flight", which is what every control reads
   * to disable itself. A spinner is a narrower claim — it says *this* button
   * started something — and driving them all from the one flag meant changing
   * the notification level spun Close and Archive along with it.
   */
  private pending: "status" | "archive" | "delete" | null = null;

  className(): string {
    return "ChatModal ChatChannelInfoModal Modal--medium";
  }

  title(): Mithril.Children {
    const channel = this.attrs.channel;

    return (
      <>
        {channelIcon(channel, "ChatChannelInfo-icon")}
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

        {this.composerSection()}

        {this.moderation()}
      </div>
    );
  }

  /**
   * How this member's own composer behaves.
   *
   * Its own section rather than a row under NOTIFICATIONS, and worth being
   * explicit about why it is in a per-channel panel at all: which key sends is a
   * setting for the person, not for the channel, and it applies everywhere. The
   * section sits here because this is the panel a member already opens to adjust
   * the chat for themselves — but the help line says "every channel" so nobody
   * reads the surrounding modal as the scope.
   *
   * Guests never reach this: the chat requires an account, and `savePreferences`
   * needs a user to save against.
   */
  protected composerSection(): Mithril.Children {
    if (!app.session.user) return null;

    const preference = sendKeyPreference();

    return (
      <div className="ChatChannelInfo-section">
        <div className="ChatChannelInfo-section-label">
          {app.translator.trans("ramon-chat.forum.info.composer")}
        </div>

        <label>
          {app.translator.trans("ramon-chat.forum.info.send_key")}
          <select
            className="FormControl"
            value={preference}
            disabled={this.working}
            onchange={(e: Event) =>
              this.saveSendKey((e.target as HTMLSelectElement).value as SendKey)
            }
          >
            <option value="enter">
              {app.translator.trans(
                "ramon-chat.forum.info.send_key_enter",
                {},
                true,
              )}
            </option>
            <option value="ctrl">
              {app.translator.trans(
                "ramon-chat.forum.info.send_key_ctrl",
                {},
                true,
              )}
            </option>
          </select>
        </label>

        <div className="helpText">
          {app.translator.trans(
            preference === "ctrl"
              ? "ramon-chat.forum.info.send_key_help_ctrl"
              : "ramon-chat.forum.info.send_key_help_enter",
          )}
        </div>
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
          loading={this.pending === "status"}
          disabled={this.working}
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
          loading={this.pending === "archive"}
          disabled={this.working}
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
              className="Button ChatChannelInfo-deleteButton"
              icon="fas fa-trash"
              loading={this.pending === "delete"}
              disabled={this.working}
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

        {/* What the role means, shown to whoever can hand it out. */}
        {this.attrs.channel.canManageModerators() ? (
          <p className="helpText ChatChannelInfo-moderatorHelp">
            {app.translator.trans("ramon-chat.forum.info.moderator_help")}
          </p>
        ) : null}

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
              {this.memberBadge(user)}
              {this.memberControls(user)}
            </div>
          ))}

          {shown.length === 0 ? (
            <div className="ChatBrowse-empty">
              {app.translator.trans("ramon-chat.forum.info.no_members")}
            </div>
          ) : null}
        </div>

        {this.invitedList()}
      </div>
    );
  }

  /**
   * Who has been asked in and not answered, for whoever manages the list.
   *
   * Under the members rather than among them: an invitation is not a
   * membership, and a name in the member list that cannot be messaged would
   * be a lie. Each row can be withdrawn, which is the manager's half of the
   * exchange the invitee's accept and decline are the other half of.
   */
  protected invitedList(): Mithril.Children {
    if (!this.attrs.channel.canManageMembers() || this.invited.length === 0) {
      return null;
    }

    return (
      <div className="ChatChannelInfo-invited">
        <div className="ChatChannelInfo-memberHeader">
          <span className="ChatChannelInfo-memberCount">
            {app.translator.trans("ramon-chat.forum.info.invited_heading", {
              count: this.invited.length,
            })}
          </span>
        </div>

        <div className="ChatChannelInfo-memberList">
          {this.invited.map((user) => (
            <div
              key={user.id()}
              className="ChatChannelInfo-member ChatChannelInfo-member--invited"
            >
              <Avatar user={user} className="Avatar" />
              <span>{userLink(user)}</span>
              <span className="ChatChannelInfo-member-badge">
                {app.translator.trans("ramon-chat.forum.info.invited_badge")}
              </span>
              <Button
                className="Button Button--icon Button--flat ChatChannelInfo-member-remove"
                icon="fas fa-user-xmark"
                disabled={this.working}
                {...iconLabel(
                  app.translator.trans(
                    "ramon-chat.forum.info.cancel_invite",
                    { username: username(user) },
                    true,
                  ),
                )}
                onclick={() => this.cancelInvite(user)}
              />
            </div>
          ))}
        </div>
      </div>
    );
  }

  /**
   * Withdraws an invitation. The invitee's notification goes with it, so
   * confirmed first: the person may be reading it at that very moment.
   */
  protected async cancelInvite(user: User): Promise<void> {
    const confirmed = confirm(
      app.translator.trans(
        "ramon-chat.forum.info.cancel_invite_confirm",
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
        url: `${app.forum.attribute("apiUrl")}/chat-channels/${this.attrs.channel.id()}/invites/cancel`,
        body: { data: { attributes: { userId: Number(user.id()) } } },
      });

      if (payload?.data) app.store.pushPayload(payload);

      this.invited = this.invited.filter((entry) => entry.id() !== user.id());

      app.alerts.show(
        { type: "success" },
        app.translator.trans("ramon-chat.forum.info.invite_cancelled", {
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

  protected isOwner(user: User): boolean {
    const creatorId = this.attrs.channel.creatorId();

    return creatorId != null && Number(creatorId) === Number(user.id());
  }

  protected isModerator(user: User): boolean {
    return (this.attrs.channel.moderatorIds() ?? []).some(
      (id) => Number(id) === Number(user.id()),
    );
  }

  /** The owner's and channel moderators' labels, so the roles are visible to everyone. */
  protected memberBadge(user: User): Mithril.Children {
    if (this.isOwner(user)) {
      return (
        <span className="ChatChannelInfo-member-badge ChatChannelInfo-member-badge--owner">
          {app.translator.trans("ramon-chat.forum.info.owner_badge")}
        </span>
      );
    }

    if (this.isModerator(user)) {
      return (
        <span className="ChatChannelInfo-member-badge">
          {app.translator.trans("ramon-chat.forum.info.moderator_badge")}
        </span>
      );
    }

    return null;
  }

  /**
   * Promote, demote and remove — each drawn only for people the actor may
   * actually act on, so no button is a promise the server refuses to keep.
   * Nothing for yourself: leaving is what "Leave channel" is for, and the owner
   * is not something you can stop being from here.
   */
  protected memberControls(user: User): Mithril.Children {
    const channel = this.attrs.channel;

    if (user.id() === app.session.user?.id()) return null;

    const controls: Mithril.Children[] = [];

    if (channel.canManageModerators() && !this.isOwner(user)) {
      const moderator = this.isModerator(user);

      controls.push(
        <Button
          className="Button Button--icon Button--flat ChatChannelInfo-member-role"
          icon={moderator ? "fas fa-user-slash" : "fas fa-user-shield"}
          disabled={this.working}
          {...iconLabel(
            app.translator.trans(
              moderator
                ? "ramon-chat.forum.info.demote_moderator"
                : "ramon-chat.forum.info.promote_moderator",
              { username: username(user) },
              true,
            ),
          )}
          onclick={() => this.setModerator(user, !moderator)}
        />,
      );
    }

    if (channel.canManageMembers()) {
      controls.push(
        <Button
          className="Button Button--icon Button--flat ChatChannelInfo-member-remove"
          icon="fas fa-user-minus"
          disabled={this.working}
          {...iconLabel(
            app.translator.trans(
              "ramon-chat.forum.info.remove_member",
              { username: username(user) },
              true,
            ),
          )}
          onclick={() => this.remove(user)}
        />,
      );
    }

    return controls;
  }

  /**
   * Hands the channel's moderator role to a member, or takes it back.
   *
   * The response carries the channel with its participants, so pushing it
   * refreshes `moderatorIds` on the very model this modal is drawing from.
   */
  protected async setModerator(user: User, moderator: boolean): Promise<void> {
    this.working = true;
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-channels/${this.attrs.channel.id()}/moderators${moderator ? "" : "/remove"}`,
        body: { data: { attributes: { userId: Number(user.id()) } } },
      });

      if (payload?.data) app.store.pushPayload(payload);

      app.alerts.show(
        { type: "success" },
        app.translator.trans(
          moderator
            ? "ramon-chat.forum.info.moderator_promoted"
            : "ramon-chat.forum.info.moderator_demoted",
          { username: username(user) },
        ),
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
        // Both lists: the picker shows them marked rather than offering them,
        // so someone already in, or already asked, is not a mystery absence.
        members: this.members,
        invited: this.invited,
        onAdded: (users: User[]) => this.onMembersInvited(users),
      },
      true,
    );
  }

  /**
   * Folds the picker's result into the pending list.
   *
   * Invited, not added: nobody is a member until they say yes, so the count
   * does not move and the names go under "invitations pending" rather than
   * into the member list.
   */
  protected onMembersInvited(users: User[]): void {
    const known = new Set([
      ...this.members.map((member) => member.id()),
      ...this.invited.map((user) => user.id()),
    ]);
    const fresh = users.filter((user) => !known.has(user.id()));

    if (fresh.length === 0) return;

    this.invited = [...this.invited, ...fresh];

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

    // From here on, a membership push for this channel re-reads the list, so
    // an invitation answered while the tab is open is seen being answered.
    this.stopListening ??= chatState.onMembershipChange((channelId) => {
      if (channelId !== Number(this.attrs.channel.id())) return;

      this.loadedMembers = false;
      void this.loadMembers();
    });

    try {
      // Re-fetched with the relationships included rather than read off the
      // sidebar's copy, which is loaded without participants. The pending
      // invitations ride along; the server omits them for anyone who may not
      // manage the list.
      const channel = (await app.store.find(
        "chat-channels",
        String(this.attrs.channel.id()),
        {
          include: "participants,invitedUsers",
        },
      )) as unknown as Channel;

      this.members = (channel.participants() || []).filter(Boolean) as User[];
      this.invited = (channel.invitedUsers() || []).filter(Boolean) as User[];
    } catch {
      this.members = [];
      this.invited = [];
    } finally {
      this.loadingMembers = false;
      this.loadedMembers = true;
      m.redraw();
    }
  }

  onremove(vnode: Mithril.VnodeDOM<ChannelInfoModalAttrs, this>): void {
    super.onremove(vnode);

    this.stopListening?.();
    this.stopListening = null;
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

  /**
   * Stores the send-key choice on the user.
   *
   * Optimistic like `saveNotifications`, and for a sharper reason: the composer
   * reads the preference off the same record on every keystroke, so the change
   * has to be in place before the next one — waiting for the round trip would
   * mean the first Enter after choosing still did the old thing.
   *
   * The rollback is not the redundant belt-and-braces it looks like. `Model.save`
   * does snapshot and restore on failure, but `savePreferences` mutates the
   * preferences object *in place* before calling it, so the snapshot is taken
   * with the new value already in it and core's revert restores that. Undoing it
   * here is the only thing that stops a rejected save from leaving the select —
   * and the composer — agreeing with a server that never accepted it.
   */
  protected async saveSendKey(value: SendKey): Promise<void> {
    const user = app.session.user;

    if (!user) return;

    const previous = sendKeyPreference();

    if (value === previous) return;

    this.working = true;

    try {
      await user.savePreferences({ "ramon-chat.sendWithCtrlEnter": value });
    } catch {
      await user
        .savePreferences({ "ramon-chat.sendWithCtrlEnter": previous })
        .catch(() => {});

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
    await this.act(
      "status",
      `/chat-channels/${this.attrs.channel.id()}/status`,
      {
        status,
      },
    );
  }

  protected async archive(): Promise<void> {
    await this.act(
      "archive",
      `/chat-channels/${this.attrs.channel.id()}/archive`,
      {},
    );
  }

  protected async destroy(): Promise<void> {
    if (
      !confirm(
        app.translator.trans("ramon-chat.forum.info.delete_confirm", {}, true),
      )
    )
      return;

    this.pending = "delete";
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
      this.pending = null;
      this.working = false;
      m.redraw();
    }
  }

  /**
   * Runs one immediate state change.
   *
   * `action` names the button that owns the spinner for the duration; `working`
   * still gates every other control, so nothing else can be started meanwhile.
   */
  protected async act(
    action: "status" | "archive",
    path: string,
    attributes: Record<string, unknown>,
  ): Promise<void> {
    this.pending = action;
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
      this.pending = null;
      this.working = false;
      m.redraw();
    }
  }
}
