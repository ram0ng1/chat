import app from "flarum/forum/app";
import Component from "flarum/common/Component";
import type { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import Switch from "flarum/common/components/Switch";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import type { RankBook, RankEntry } from "../../common/models/Channel";
import { adoptChannelPayload, invitationErrorText } from "../utils/invitations";
import iconLabel from "../utils/iconLabel";
import { rankName, rankNameAttrs, rankTag, validIcon } from "../utils/ranks";
import { deriveRankColors, isHex } from "../utils/rankPalette";

/** Mirrors ChannelRank::MAX_PER_CHANNEL and ChannelRank::NAME_MAX. */
export const MAX_RANKS = 20;
export const RANK_NAME_MAX = 32;

type RankPath = "" | "/update" | "/delete" | "/order" | "/assign";

/**
 * One rank write, answered with the channel; the new `rankBook` lands on the
 * store record every author line and the members tab read from.
 */
export async function rankRequest(
  channelId: number | string,
  path: RankPath,
  attributes: Record<string, unknown>,
): Promise<Channel | null> {
  const payload = await app.request<any>({
    method: "POST",
    url: `${app.forum.attribute("apiUrl")}/chat-channels/${channelId}/ranks${path}`,
    body: { data: { attributes } },
    errorHandler: () => undefined,
  });

  return adoptChannelPayload(payload);
}

interface RankDraft {
  /** 'new', a built-in's name, or a custom rank's id. */
  target: "new" | "owner" | "moderator" | number;
  builtin: "owner" | "moderator" | null;
  name: string;
  color: string;
  /** A built-in left at the theme's colour. */
  themeColor: boolean;
  icon: string;
  showBadge: boolean;
}

export interface ChannelRanksTabAttrs extends ComponentAttrs {
  channel: Channel;
}

/**
 * The ranks editor, for whoever may manage them: the two built-in ranks every
 * channel has, then the owner's own in priority order, each editable, the own
 * ones reorderable and removable. Handing them out happens in the Members tab,
 * where the people are.
 *
 * Draws only from the channel's `rankBook`, and every write answers with the
 * new one, so the list is always the server's word.
 */
export default class ChannelRanksTab extends Component<ChannelRanksTabAttrs> {
  private draft: RankDraft | null = null;
  private working = false;
  private loading = false;

  oninit(vnode: Mithril.Vnode<ChannelRanksTabAttrs, this>): void {
    super.oninit(vnode);

    if (!this.book()) void this.load();
  }

  view(): Mithril.Children {
    const book = this.book();

    if (!book) {
      return (
        <div className="ChatChannelInfo-section">
          <LoadingIndicator />
        </div>
      );
    }

    const custom = book.ranks.filter((rank) => rank.builtin === null);

    return (
      <div className="ChatChannelInfo-section ChatRanks">
        <p className="helpText ChatRanks-intro">
          {app.translator.trans("ramon-chat.forum.ranks.intro")}
        </p>

        {this.draft ? this.editor(this.draft) : null}

        <ul className="ChatRanks-list">
          {book.ranks.map((rank) => this.row(book, rank, custom))}
        </ul>

        {custom.length === 0 ? (
          <p className="ChatRanks-empty">
            {app.translator.trans("ramon-chat.forum.ranks.empty")}
          </p>
        ) : null}

        <div className="ChatRanks-footer">
          <Button
            className="Button Button--primary Button--compact"
            icon="fas fa-plus"
            disabled={
              this.working || this.draft !== null || custom.length >= MAX_RANKS
            }
            onclick={() => this.edit(null)}
          >
            {app.translator.trans("ramon-chat.forum.ranks.new")}
          </Button>

          {custom.length >= MAX_RANKS ? (
            <span className="helpText">
              {app.translator.trans("ramon-chat.forum.ranks.limit", {
                max: MAX_RANKS,
              })}
            </span>
          ) : null}
        </div>
      </div>
    );
  }

  protected book(): RankBook | null {
    const book = this.attrs.channel.rankBook?.();

    return book && Array.isArray(book.ranks) ? book : null;
  }

  protected async load(): Promise<void> {
    if (this.loading) return;

    this.loading = true;

    try {
      await app.store.find("chat-channels", String(this.attrs.channel.id()));
    } catch {
      app.alerts.show(
        { type: "error" },
        app.translator.trans("ramon-chat.forum.ranks.save_failed"),
      );
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  protected row(
    book: RankBook,
    rank: RankEntry,
    custom: RankEntry[],
  ): Mithril.Children {
    const index = custom.indexOf(rank);
    const name = rankName(rank);
    const holders = this.holders(book, rank);

    return (
      <li key={rank.key} className="ChatRanks-row">
        <span className="ChatRanks-sample">
          {rank.showBadge ? (
            rankTag(rank)
          ) : (
            <span {...rankNameAttrs(rank)}>{name}</span>
          )}
        </span>

        <span className="ChatRanks-meta">
          {rank.builtin !== null ? (
            <span className="ChatRanks-chip">
              {app.translator.trans("ramon-chat.forum.ranks.builtin_tag")}
            </span>
          ) : (
            <span className="ChatRanks-holders">
              {app.translator.trans("ramon-chat.forum.ranks.holders", {
                count: holders,
              })}
            </span>
          )}

          {!rank.showBadge ? (
            <span className="ChatRanks-chip">
              {app.translator.trans("ramon-chat.forum.ranks.name_color")}
            </span>
          ) : null}
        </span>

        <span className="ChatRanks-actions">
          {rank.builtin === null ? (
            <>
              <Button
                className="Button Button--icon Button--flat"
                icon="fas fa-arrow-up"
                disabled={this.working || index <= 0}
                {...iconLabel(
                  app.translator.trans(
                    "ramon-chat.forum.ranks.move_up",
                    { name },
                    true,
                  ),
                )}
                onclick={() => this.move(custom, index, -1)}
              />
              <Button
                className="Button Button--icon Button--flat"
                icon="fas fa-arrow-down"
                disabled={this.working || index >= custom.length - 1}
                {...iconLabel(
                  app.translator.trans(
                    "ramon-chat.forum.ranks.move_down",
                    { name },
                    true,
                  ),
                )}
                onclick={() => this.move(custom, index, 1)}
              />
            </>
          ) : null}

          <Button
            className="Button Button--icon Button--flat"
            icon="fas fa-pen"
            disabled={this.working}
            {...iconLabel(
              app.translator.trans(
                "ramon-chat.forum.ranks.edit",
                { name },
                true,
              ),
            )}
            onclick={() => this.edit(rank)}
          />

          {rank.builtin === null || rank.id !== null ? (
            <Button
              className="Button Button--icon Button--flat ChatRanks-delete"
              icon={
                rank.builtin === null ? "fas fa-trash" : "fas fa-rotate-left"
              }
              disabled={this.working}
              {...iconLabel(
                app.translator.trans(
                  rank.builtin === null
                    ? "ramon-chat.forum.ranks.delete"
                    : "ramon-chat.forum.ranks.reset",
                  { name },
                  true,
                ),
              )}
              onclick={() => this.remove(rank)}
            />
          ) : null}
        </span>
      </li>
    );
  }

  protected holders(book: RankBook, rank: RankEntry): number {
    if (rank.builtin !== null) return 0;

    return Object.values(
      (book.assignments as Record<string, number[]>) ?? {},
    ).filter((ids) => ids.map(Number).includes(Number(rank.id))).length;
  }

  // ── Editor ─────────────────────────────────────────────────────────────────

  protected edit(rank: RankEntry | null): void {
    this.draft = rank
      ? {
          target: rank.builtin ?? Number(rank.id),
          builtin: rank.builtin,
          name: rank.name ?? "",
          color: rank.color ?? "#5865f2",
          themeColor: rank.builtin !== null && !rank.color,
          icon: rank.icon ?? "",
          showBadge: rank.showBadge,
        }
      : {
          target: "new",
          builtin: null,
          name: "",
          color: "#5865f2",
          themeColor: false,
          icon: "",
          showBadge: true,
        };
  }

  protected editor(draft: RankDraft): Mithril.Children {
    const nameKey =
      draft.builtin === "owner"
        ? "ramon-chat.forum.ranks.owner_default"
        : draft.builtin === "moderator"
          ? "ramon-chat.forum.ranks.moderator_default"
          : null;
    const icon = validIcon(draft.icon.trim());

    return (
      <form
        className="ChatRanks-editor"
        onsubmit={(e: SubmitEvent) => {
          e.preventDefault();
          void this.save(draft);
        }}
      >
        <div className="ChatRanks-editorTitle">
          {app.translator.trans(
            draft.target === "new"
              ? "ramon-chat.forum.ranks.new"
              : "ramon-chat.forum.ranks.edit_title",
          )}
        </div>

        <div className="ChatRanks-fields">
          <label className="ChatRanks-field">
            <span className="ChatRanks-label">
              {app.translator.trans("ramon-chat.forum.ranks.name")}
            </span>
            <input
              className="FormControl"
              maxlength={RANK_NAME_MAX}
              value={draft.name}
              placeholder={
                nameKey
                  ? (app.translator.trans(nameKey, {}, true) as string)
                  : ""
              }
              oninput={(e: Event) => {
                draft.name = (e.target as HTMLInputElement).value;
              }}
            />
          </label>

          <div className="ChatRanks-field">
            <span className="ChatRanks-label">
              {app.translator.trans("ramon-chat.forum.ranks.color")}
            </span>

            {draft.builtin !== null ? (
              <Switch
                state={draft.themeColor}
                onchange={(value: boolean) => {
                  draft.themeColor = value;
                }}
              >
                {app.translator.trans("ramon-chat.forum.ranks.color_theme")}
              </Switch>
            ) : null}

            {draft.themeColor ? null : (
              <div className="ChatRanks-color">
                <input
                  type="color"
                  className="ChatRanks-swatch"
                  value={isHex(draft.color) ? draft.color : "#5865f2"}
                  aria-label={app.translator.trans(
                    "ramon-chat.forum.ranks.color",
                    {},
                    true,
                  )}
                  oninput={(e: Event) => {
                    draft.color = (e.target as HTMLInputElement).value;
                  }}
                />
                <input
                  className={classList("FormControl", "ChatRanks-hex", {
                    "ChatRanks-hex--invalid": !isHex(draft.color),
                  })}
                  maxlength={7}
                  value={draft.color}
                  spellcheck={false}
                  oninput={(e: Event) => {
                    draft.color = (e.target as HTMLInputElement).value.trim();
                  }}
                />
              </div>
            )}
          </div>

          <label className="ChatRanks-field">
            <span className="ChatRanks-label">
              {app.translator.trans("ramon-chat.forum.ranks.icon")}
            </span>
            <div className="ChatRanks-icon">
              <span className="ChatRanks-iconPreview" aria-hidden="true">
                {icon ? <i className={icon} /> : null}
              </span>
              <input
                className="FormControl"
                maxlength={64}
                value={draft.icon}
                placeholder="fas fa-star"
                spellcheck={false}
                oninput={(e: Event) => {
                  draft.icon = (e.target as HTMLInputElement).value;
                }}
              />
            </div>
            <span className="helpText">
              {app.translator.trans("ramon-chat.forum.ranks.icon_help")}
            </span>
          </label>

          <div className="ChatRanks-field">
            <Switch
              state={draft.showBadge}
              onchange={(value: boolean) => {
                draft.showBadge = value;
              }}
            >
              {app.translator.trans("ramon-chat.forum.ranks.show_badge")}
            </Switch>
            <span className="helpText">
              {app.translator.trans(
                draft.showBadge
                  ? "ramon-chat.forum.ranks.show_badge_help_on"
                  : "ramon-chat.forum.ranks.show_badge_help_off",
              )}
            </span>
          </div>
        </div>

        {this.preview(draft)}

        <div className="ChatRanks-editorActions">
          <Button
            type="submit"
            className="Button Button--primary"
            loading={this.working}
            disabled={this.working}
          >
            {app.translator.trans("ramon-chat.forum.ranks.save")}
          </Button>
          <Button
            className="Button"
            disabled={this.working}
            onclick={() => {
              this.draft = null;
            }}
          >
            {app.translator.trans("ramon-chat.forum.ranks.cancel")}
          </Button>
        </div>
      </form>
    );
  }

  /**
   * The rank as it would look, on a light surface and on a dark one side by
   * side, so whoever picks a colour sees both themes before anyone else does.
   */
  protected preview(draft: RankDraft): Mithril.Children {
    const color = draft.themeColor || !isHex(draft.color) ? null : draft.color;
    const rank: RankEntry = {
      key: "preview",
      id: null,
      builtin: draft.builtin,
      name: draft.name.trim() || null,
      color,
      icon: draft.icon.trim() || null,
      showBadge: draft.showBadge,
      position: 0,
      ...deriveRankColors(color),
    };
    const who =
      app.session.user?.displayName() ??
      (app.translator.trans("ramon-chat.forum.ranks.name", {}, true) as string);

    const sample = (dark: boolean) => {
      const attrs = rankNameAttrs(rank);
      const style =
        attrs.style && dark
          ? { color: rank.nameDark ?? undefined }
          : attrs.style
            ? { color: rank.nameLight ?? undefined }
            : undefined;

      return (
        <div
          className={classList(
            "ChatRanks-sampleLine",
            dark ? "ChatRanks-sampleLine--dark" : "ChatRanks-sampleLine--light",
          )}
        >
          {rank.showBadge && (rank.name || rank.builtin) ? rankTag(rank) : null}
          <span className={attrs.className} style={style}>
            {who}
          </span>
        </div>
      );
    };

    return (
      <div className="ChatRanks-preview">
        <span className="ChatRanks-label">
          {app.translator.trans("ramon-chat.forum.ranks.preview")}
        </span>
        <div className="ChatRanks-previewPair">
          {sample(false)}
          {sample(true)}
        </div>
      </div>
    );
  }

  protected async save(draft: RankDraft): Promise<void> {
    if (this.working) return;

    const name = draft.name.trim();

    if (draft.builtin === null && name === "") {
      app.alerts.show(
        { type: "error" },
        app.translator.trans("ramon-chat.forum.ranks.name_required"),
      );

      return;
    }

    if (!draft.themeColor && !isHex(draft.color)) {
      app.alerts.show(
        { type: "error" },
        app.translator.trans("ramon-chat.forum.ranks.color_invalid"),
      );

      return;
    }

    const attributes: Record<string, unknown> = {
      name,
      color: draft.themeColor ? null : draft.color.toLowerCase(),
      icon: draft.icon.trim() || null,
      showBadge: draft.showBadge,
    };

    if (draft.target !== "new") attributes.rankId = draft.target;

    await this.run(
      draft.target === "new" ? "" : "/update",
      attributes,
      "ramon-chat.forum.ranks.saved",
      () => {
        this.draft = null;
      },
    );
  }

  protected async move(
    custom: RankEntry[],
    index: number,
    by: number,
  ): Promise<void> {
    const ids = custom.map((rank) => Number(rank.id));
    const to = index + by;

    if (to < 0 || to >= ids.length) return;

    [ids[index], ids[to]] = [ids[to], ids[index]];

    await this.run("/order", { rankIds: ids }, null);
  }

  protected async remove(rank: RankEntry): Promise<void> {
    const name = rankName(rank);
    const builtin = rank.builtin !== null;

    if (
      !confirm(
        app.translator.trans(
          builtin
            ? "ramon-chat.forum.ranks.reset_confirm"
            : "ramon-chat.forum.ranks.delete_confirm",
          { name },
          true,
        ) as string,
      )
    ) {
      return;
    }

    await this.run(
      "/delete",
      { rankId: rank.builtin ?? rank.id },
      builtin
        ? "ramon-chat.forum.ranks.reset_done"
        : "ramon-chat.forum.ranks.deleted",
      () => {
        if (this.draft && this.draft.target === (rank.builtin ?? rank.id)) {
          this.draft = null;
        }
      },
    );
  }

  protected async run(
    path: RankPath,
    attributes: Record<string, unknown>,
    successKey: string | null,
    after?: () => void,
  ): Promise<void> {
    this.working = true;
    m.redraw();

    try {
      await rankRequest(this.attrs.channel.id() as string, path, attributes);

      after?.();

      if (successKey) {
        app.alerts.show({ type: "success" }, app.translator.trans(successKey));
      }
    } catch (e: any) {
      app.alerts.show(
        { type: "error" },
        invitationErrorText(e, "ramon-chat.forum.ranks.save_failed"),
      );
    } finally {
      this.working = false;
      m.redraw();
    }
  }
}
