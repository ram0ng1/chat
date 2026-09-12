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
import { isOnline } from "../utils/presence";

/** What the endpoint accepts in one call; mirrors ChannelResource::addMembers. */
const MAX_PER_REQUEST = 50;

/** How many recently active people are offered before anything is typed. */
const SUGGESTIONS = 8;

export interface AddMembersModalAttrs extends IFormModalAttrs {
  channel: Channel;
  /** Already in the channel: listed, but not offered. */
  members: User[];
  /** Already asked and not answered: listed, but not offered again. */
  invited: User[];
  /** Called with the people the server was asked to invite. */
  onAdded: (users: User[]) => void;
}

/**
 * Picks people to invite into a channel.
 *
 * A token field: the chosen people sit inside the search box as chips, so the
 * question "who am I about to invite" is answered where the typing happens.
 * Before anything is typed the box offers the people most recently seen on the
 * forum, so the dialog never opens onto an empty grey square; typing narrows
 * that to a search. People who are already in, or already asked, still appear
 * in the results, marked and unselectable, so their absence from the offer is
 * explained rather than silent.
 *
 * The keyboard works the way a token field is expected to: arrows move along
 * the list, Enter picks, Backspace on an empty box takes the last chip back,
 * Escape clears the query.
 */
export default class AddMembersModal extends FormModal<AddMembersModalAttrs> {
  private query = "";
  private results: User[] = [];
  private suggestions: User[] = [];
  private selected: User[] = [];
  private searching = false;
  private loadingSuggestions = true;
  private highlighted = 0;
  private timer: number | null = null;

  /**
   * Guards against an earlier search resolving after a later one.
   *
   * Typing "ram" issues a request for "ra" and one for "ram"; without this the
   * slower of the two wins and the list contradicts the field.
   */
  private sequence = 0;

  oninit(vnode: Mithril.Vnode<AddMembersModalAttrs, this>): void {
    super.oninit(vnode);

    void this.loadSuggestions();
  }

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
        <p className="ChatAddMembers-intro">
          {app.translator.trans("ramon-chat.forum.add_members.intro", {
            channel: (
              <strong>{this.attrs.channel.displayName()}</strong>
            ) as unknown as string,
          })}
        </p>

        <div className="ChatAddMembers-field" onclick={() => this.focusInput()}>
          <i
            className="ChatAddMembers-field-icon fas fa-magnifying-glass"
            aria-hidden="true"
          />

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
              onclick={(e: MouseEvent) => {
                e.stopPropagation();
                this.toggle(user);
              }}
            >
              <Avatar user={user} className="Avatar" />
              <span>{user.displayName()}</span>
              <i className="fas fa-xmark" aria-hidden="true" />
            </button>
          ))}

          <input
            className="ChatAddMembers-input"
            type="text"
            autocomplete="off"
            placeholder={
              this.selected.length === 0
                ? app.translator.trans(
                    "ramon-chat.forum.add_members.search_placeholder",
                    {},
                    true,
                  )
                : ""
            }
            value={this.query}
            disabled={this.loading}
            oninput={(e: Event) =>
              this.search((e.target as HTMLInputElement).value)
            }
            onkeydown={(e: KeyboardEvent) => this.onKey(e)}
            oncreate={(vnode: Mithril.VnodeDOM) =>
              (vnode.dom as HTMLInputElement).focus()
            }
          />

          {this.searching ? (
            <LoadingIndicator display="inline" size="small" />
          ) : null}
        </div>

        {this.list()}
      </div>
    );
  }

  /**
   * The offer: suggestions until two letters are typed, then the search.
   */
  protected list(): Mithril.Children {
    const typed = this.query.trim().length;

    if (typed < 2) {
      if (this.loadingSuggestions) {
        return (
          <div className="ChatAddMembers-results ChatAddMembers-results--empty">
            <LoadingIndicator display="inline" size="small" />
          </div>
        );
      }

      const candidates = this.suggestions.filter(
        (user) => this.statusOf(user) === null,
      );

      if (candidates.length === 0) {
        return (
          <p className="ChatAddMembers-hint">
            {app.translator.trans("ramon-chat.forum.add_members.hint")}
          </p>
        );
      }

      return (
        <div className="ChatAddMembers-results">
          <div className="ChatAddMembers-heading">
            {app.translator.trans("ramon-chat.forum.add_members.suggested")}
          </div>
          {candidates.map((user, index) => this.row(user, index))}
        </div>
      );
    }

    if (this.results.length === 0) {
      return (
        <p className="ChatAddMembers-hint">
          {this.searching
            ? app.translator.trans("ramon-chat.forum.add_members.searching")
            : app.translator.trans("ramon-chat.forum.add_members.no_results", {
                query: this.query.trim(),
              })}
        </p>
      );
    }

    return (
      <div className="ChatAddMembers-results" role="listbox">
        {this.rows(this.results)}
      </div>
    );
  }

  /**
   * Rows for a list that mixes people who can be picked with people who
   * cannot. The highlight counts only the former, the way the arrow keys do,
   * so a marked row in the middle of the results does not shift it.
   */
  protected rows(users: User[]): Mithril.Children {
    let offeredIndex = 0;

    return users.map((user) =>
      this.row(user, this.statusOf(user) === null ? offeredIndex++ : -1),
    );
  }

  protected row(user: User, index: number): Mithril.Children {
    const status = this.statusOf(user);
    const picked = this.isSelected(user);
    const offered = status === null;

    return (
      <button
        type="button"
        key={user.id()}
        className={classList("ChatAddMembers-result", {
          "ChatAddMembers-result--selected": picked,
          "ChatAddMembers-result--highlighted":
            offered && index === this.highlighted,
          "ChatAddMembers-result--taken": !offered,
        })}
        role="option"
        aria-selected={picked}
        disabled={this.loading || !offered}
        onmouseenter={() => {
          this.highlighted = index;
        }}
        onclick={() => this.toggle(user)}
      >
        <span
          className={classList("ChatAddMembers-result-avatar", {
            "ChatAddMembers-result-avatar--online": isOnline(user),
          })}
        >
          <Avatar user={user} className="Avatar" />
        </span>

        <span className="ChatAddMembers-result-text">
          <span className="ChatAddMembers-result-name">
            {user.displayName()}
          </span>
          {user.displayName() !== user.username() ? (
            <span className="ChatAddMembers-result-handle">
              @{user.username()}
            </span>
          ) : null}
        </span>

        {status ? (
          <span className="ChatAddMembers-result-status">
            {app.translator.trans(
              `ramon-chat.forum.add_members.${status}_badge`,
            )}
          </span>
        ) : (
          <span className="ChatAddMembers-result-mark" aria-hidden="true">
            <i className={picked ? "fas fa-check" : "fas fa-plus"} />
          </span>
        )}
      </button>
    );
  }

  protected footer(): Mithril.Children {
    const count = this.selected.length;
    const tooMany = count > MAX_PER_REQUEST;

    return (
      <div className="Modal-footer ChatAddMembers-footer">
        <span className="ChatAddMembers-footer-count">
          {tooMany
            ? app.translator.trans("ramon-chat.api.members_too_many", {
                max: MAX_PER_REQUEST,
              })
            : count > 0
              ? app.translator.trans(
                  "ramon-chat.forum.add_members.selected_count",
                  { count },
                )
              : null}
        </span>

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
          icon="fas fa-paper-plane"
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

  /**
   * Why a person cannot be picked, or null when they can.
   */
  protected statusOf(user: User): "you" | "member" | "invited" | null {
    const id = user.id();

    if (id === app.session.user?.id()) return "you";
    if (this.attrs.members.some((member) => member.id() === id))
      return "member";
    if (this.attrs.invited.some((pending) => pending.id() === id)) {
      return "invited";
    }

    return null;
  }

  protected isSelected(user: User): boolean {
    return this.selected.some((picked) => picked.id() === user.id());
  }

  protected toggle(user: User): void {
    if (this.statusOf(user) !== null) return;

    this.selected = this.isSelected(user)
      ? this.selected.filter((picked) => picked.id() !== user.id())
      : [...this.selected, user];

    // Picking someone from a search clears it, the way a token field does:
    // the chip is the record of the choice, and the next name starts fresh.
    if (this.query !== "") {
      this.query = "";
      this.results = [];
      this.searching = false;
      this.sequence++;
    }

    this.highlighted = 0;
    this.focusInput();
  }

  protected offered(): User[] {
    const typed = this.query.trim().length;
    const pool = typed < 2 ? this.suggestions : this.results;

    return pool.filter((user) => this.statusOf(user) === null);
  }

  protected onKey(e: KeyboardEvent): void {
    const offered = this.offered();

    if (e.key === "ArrowDown" && offered.length > 0) {
      e.preventDefault();
      this.highlighted = (this.highlighted + 1) % offered.length;
    } else if (e.key === "ArrowUp" && offered.length > 0) {
      e.preventDefault();
      this.highlighted =
        (this.highlighted - 1 + offered.length) % offered.length;
    } else if (e.key === "Enter") {
      // Enter picks; it must not submit the form behind the field, which is
      // what a bare input inside a form does.
      e.preventDefault();

      const pick = offered[this.highlighted];

      if (pick) this.toggle(pick);
    } else if (e.key === "Backspace" && this.query === "") {
      const last = this.selected[this.selected.length - 1];

      if (last) this.toggle(last);
    } else if (e.key === "Escape" && this.query !== "") {
      e.stopPropagation();
      this.search("");
    }
  }

  protected focusInput(): void {
    const input = this.element?.querySelector<HTMLInputElement>(
      ".ChatAddMembers-input",
    );

    input?.focus();
  }

  /**
   * A handful of people offered before any typing.
   *
   * The most recently seen when the actor may sort by that (core gates the
   * `lastSeenAt` sort behind `user.viewLastSeenAt`, which ordinary members
   * usually lack and which the API answers with a 400), the most active
   * posters otherwise. Fetched once per opening; the list is small and the
   * point is only to give the dialog something to open onto.
   */
  protected async loadSuggestions(): Promise<void> {
    const limit = SUGGESTIONS + 1 + this.attrs.members.length;
    // Administrators hold every permission; anyone else who happens to hold
    // this one is caught by the fallback rather than by a second request
    // for everyone.
    const orders = app.session.user?.isAdmin()
      ? ["-lastSeenAt", "-commentCount"]
      : ["-commentCount", "-lastSeenAt"];

    try {
      let results: User[] = [];

      for (const sort of orders) {
        try {
          const found = await app.store.find<User[]>("users", {
            sort,
            page: { limit },
          });

          results = Array.isArray(found) ? found : [];

          break;
        } catch {
          // A sort this actor may not use; the next one is open to everyone.
        }
      }

      this.suggestions = results.slice(0, limit);
    } finally {
      this.loadingSuggestions = false;
      m.redraw();
    }
  }

  /** Debounced so typing does not issue a request per keystroke. */
  protected search(value: string): void {
    this.query = value;
    this.highlighted = 0;

    if (this.timer !== null) window.clearTimeout(this.timer);

    if (value.trim().length < 2) {
      this.results = [];
      this.searching = false;
      this.sequence++;

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

          this.results = Array.isArray(results) ? results : [];
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
        // inviting five people used to be five requests, five notifications'
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
            names: chosen.map((user) => user.displayName()).join(", "),
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
