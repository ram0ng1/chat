import app from "flarum/forum/app";
import FormModal from "flarum/common/components/FormModal";
import type { IFormModalAttrs } from "flarum/common/components/FormModal";
import Avatar from "flarum/common/components/Avatar";
import Button from "flarum/common/components/Button";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import username from "flarum/common/helpers/username";
import classList from "flarum/common/utils/classList";
import type User from "flarum/common/models/User";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";

/** What the endpoint accepts in one call — mirrors ChannelResource::addMembers. */
const MAX_PER_REQUEST = 50;

export interface AddMembersModalAttrs extends IFormModalAttrs {
  channel: Channel;
  /** Who is already in, so they are never offered as candidates. */
  existing: User[];
  /** Called with the people the server actually added. */
  onAdded: (users: User[]) => void;
}

/**
 * Picks people to put into a channel.
 *
 * A modal rather than the field that used to unfold inside the members tab.
 * That field could only add one person per click, each one its own request and
 * its own alert, and it shared the tab with the member list and the member
 * filter — three search-shaped controls stacked on top of each other, two of
 * which searched different things. Choosing several people is one task, so it
 * gets one surface and one request.
 */
export default class AddMembersModal extends FormModal<AddMembersModalAttrs> {
  private query = "";
  private results: User[] = [];
  private selected: User[] = [];
  private searching = false;
  private timer: number | null = null;

  /**
   * Guards against an earlier search resolving after a later one.
   *
   * Typing "ram" issues a request for "ra" and one for "ram"; without this the
   * slower of the two wins and the list contradicts the field.
   */
  private sequence = 0;

  onremove(vnode: Mithril.VnodeDOM<AddMembersModalAttrs, this>): void {
    super.onremove(vnode);

    if (this.timer !== null) window.clearTimeout(this.timer);
  }

  className(): string {
    return "ChatModal ChatAddMembers Modal--medium";
  }

  title(): Mithril.Children {
    return app.translator.trans("ramon-chat.forum.add_members.title");
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body ChatAddMembers-body">
        <div className="ChatAddMembers-search">
          <i
            className="ChatAddMembers-search-icon fas fa-magnifying-glass"
            aria-hidden="true"
          />
          <input
            className="ChatAddMembers-search-input"
            type="search"
            placeholder={app.translator.trans(
              "ramon-chat.forum.add_members.search_placeholder",
              {},
              true,
            )}
            value={this.query}
            disabled={this.loading}
            oninput={(e: Event) =>
              this.search((e.target as HTMLInputElement).value)
            }
            oncreate={(vnode: Mithril.VnodeDOM) =>
              (vnode.dom as HTMLInputElement).focus()
            }
          />

          {this.searching ? (
            <LoadingIndicator display="inline" size="small" />
          ) : null}
        </div>

        {this.chips()}
        {this.candidates()}
      </div>
    );
  }

  /**
   * The current selection, as removable chips.
   *
   * Kept outside the results list because a chosen person stops matching as soon
   * as the query changes — without this, selecting three people and typing a
   * fourth name leaves nothing on screen saying who is about to be added.
   */
  protected chips(): Mithril.Children {
    if (this.selected.length === 0) return null;

    return (
      <div className="ChatAddMembers-chips">
        {this.selected.map((user) => (
          <button
            type="button"
            key={user.id()}
            className="ChatAddMembers-chip"
            disabled={this.loading}
            title={app.translator.trans(
              "ramon-chat.forum.add_members.deselect",
              { username: username(user) },
              true,
            )}
            onclick={() => this.toggle(user)}
          >
            <Avatar user={user} className="Avatar" />
            <span>{username(user)}</span>
            <i className="fas fa-xmark" aria-hidden="true" />
          </button>
        ))}
      </div>
    );
  }

  protected candidates(): Mithril.Children {
    const typed = this.query.trim().length;

    if (typed < 2) {
      return (
        <p className="ChatAddMembers-hint">
          {app.translator.trans("ramon-chat.forum.add_members.hint")}
        </p>
      );
    }

    if (this.results.length === 0) {
      return (
        <p className="ChatAddMembers-hint">
          {this.searching
            ? app.translator.trans("ramon-chat.forum.add_members.searching")
            : app.translator.trans("ramon-chat.forum.add_members.empty")}
        </p>
      );
    }

    return (
      <div className="ChatAddMembers-results">
        {this.results.map((user) => {
          const picked = this.isSelected(user);

          return (
            <button
              type="button"
              key={user.id()}
              className={classList("ChatAddMembers-result", {
                "ChatAddMembers-result--selected": picked,
              })}
              // Checkbox semantics: the row is a toggle, not a command, and the
              // selection is only applied when the form is submitted.
              role="checkbox"
              aria-checked={picked}
              disabled={this.loading}
              onclick={() => this.toggle(user)}
            >
              <Avatar user={user} className="Avatar" />

              <span className="ChatAddMembers-result-name">
                {username(user)}
              </span>

              <span className="ChatAddMembers-result-mark" aria-hidden="true">
                <i className={picked ? "fas fa-check" : "fas fa-plus"} />
              </span>
            </button>
          );
        })}
      </div>
    );
  }

  protected footer(): Mithril.Children {
    const count = this.selected.length;
    const tooMany = count > MAX_PER_REQUEST;

    return (
      <div className="Modal-footer ChatAddMembers-footer">
        {tooMany ? (
          <span className="ChatAddMembers-footer-warning">
            {app.translator.trans("ramon-chat.api.members_too_many", {
              max: MAX_PER_REQUEST,
            })}
          </span>
        ) : null}

        <Button
          className="Button Button--link"
          type="button"
          disabled={this.loading}
          onclick={() => this.hide()}
        >
          {app.translator.trans("ramon-chat.forum.new_channel.cancel")}
        </Button>

        <Button
          className="Button Button--primary"
          type="submit"
          loading={this.loading}
          disabled={this.loading || count === 0 || tooMany}
        >
          {count === 0
            ? app.translator.trans("ramon-chat.forum.add_members.submit")
            : app.translator.trans(
                "ramon-chat.forum.add_members.submit_count",
                {
                  count,
                },
              )}
        </Button>
      </div>
    );
  }

  protected inner(): Mithril.Children {
    return (
      <>
        {super.inner()}
        {this.footer()}
      </>
    );
  }

  // ── Behaviour ──────────────────────────────────────────────────────────────

  protected isSelected(user: User): boolean {
    return this.selected.some((picked) => picked.id() === user.id());
  }

  protected toggle(user: User): void {
    this.selected = this.isSelected(user)
      ? this.selected.filter((picked) => picked.id() !== user.id())
      : [...this.selected, user];
  }

  /** Debounced so typing does not issue a request per keystroke. */
  protected search(value: string): void {
    this.query = value;

    if (this.timer !== null) window.clearTimeout(this.timer);

    if (value.trim().length < 2) {
      this.results = [];
      this.searching = false;

      return;
    }

    this.searching = true;
    const mine = ++this.sequence;

    this.timer = window.setTimeout(() => {
      app.store
        .find<User[]>("users", {
          filter: { q: value.trim() },
          page: { limit: 10 },
        })
        .then((results) => {
          if (mine !== this.sequence) return;

          const already = new Set(
            this.attrs.existing.map((member) => member.id()),
          );

          // Someone already in the channel is not a candidate; offering them and
          // then silently doing nothing is worse than not offering.
          this.results = (Array.isArray(results) ? results : []).filter(
            (user) => !already.has(user.id()),
          );
          this.searching = false;

          m.redraw();
        })
        .catch(() => {
          if (mine !== this.sequence) return;

          this.results = [];
          this.searching = false;

          app.alerts.show(
            { type: "error" },
            app.translator.trans("ramon-chat.forum.add_members.search_failed"),
          );

          m.redraw();
        });
    }, 250);
  }

  onsubmit(e: SubmitEvent): void {
    e.preventDefault();

    if (this.loading || this.selected.length === 0) return;

    this.loading = true;
    m.redraw();

    const chosen = this.selected;

    app
      .request<any>({
        method: "POST",
        url: `${app.forum.attribute("apiUrl")}/chat-channels/${this.attrs.channel.id()}/members`,
        // One request for the whole selection: the endpoint takes a list, and
        // adding five people used to be five requests, five notifications'
        // worth of round trips and five chances to half-fail.
        body: {
          data: { attributes: { userIds: chosen.map((u) => Number(u.id())) } },
        },
      })
      .then((payload) => {
        if (payload?.data) app.store.pushPayload(payload);

        this.hide();

        app.alerts.show(
          { type: "success" },
          app.translator.trans("ramon-chat.forum.add_members.added", {
            count: chosen.length,
          }),
        );

        this.attrs.onAdded(chosen);
      })
      .catch((error: any) => {
        this.loading = false;

        if (error?.alert) {
          this.onerror(error);
        } else {
          app.alerts.show(
            { type: "error" },
            error?.response?.errors?.[0]?.detail ??
              app.translator.trans("ramon-chat.forum.add_members.failed"),
          );

          m.redraw();
        }
      });
  }
}
