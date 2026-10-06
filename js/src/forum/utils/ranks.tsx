import app from "flarum/forum/app";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import type { RankBook, RankEntry } from "../../common/models/Channel";
import type Message from "../../common/models/Message";

/**
 * Channel ranks on the client: which rank each person displays, and how to
 * draw it.
 *
 * Priority mirrors Ramon\Chat\Rank\RankBook: owner, moderator, then the owner's
 * ranks in the owner's order. Pure functions, no state: this module ends up in
 * more than one chunk, and state here would be one copy per chunk.
 */

export const ICON_PATTERN =
  /^(?:fa[srb]?|fa-(?:solid|regular|brands)) fa-[a-z0-9-]{1,48}$/;

export function displayedKey(book: RankBook, userId: number): string | null {
  if (book.ownerId != null && Number(book.ownerId) === userId) return "owner";

  if ((book.moderatorIds ?? []).some((id) => Number(id) === userId)) {
    return "moderator";
  }

  const held = (
    (book.assignments as Record<string, number[]>)?.[String(userId)] ?? []
  ).map(Number);

  if (held.length === 0) return null;

  const rank = book.ranks.find(
    (entry) => entry.builtin === null && held.includes(Number(entry.id)),
  );

  return rank ? rank.key : null;
}

export function displayedRank(
  book: RankBook,
  userId: number,
): RankEntry | null {
  const key = displayedKey(book, userId);

  return key === null
    ? null
    : (book.ranks.find((entry) => entry.key === key) ?? null);
}

/** The custom ranks a member holds, as their ids. */
export function heldRankIds(book: RankBook, userId: number): number[] {
  return (
    (book.assignments as Record<string, number[]>)?.[String(userId)] ?? []
  ).map(Number);
}

export function bookFor(channelId: number | string): RankBook | null {
  const channel = app.store.getById<Channel>(
    "chat-channels",
    String(channelId),
  );
  const book = channel?.rankBook?.();

  return book && Array.isArray(book.ranks) ? book : null;
}

/**
 * The rank before a message's author.
 *
 * The channel's book wins when it is loaded: realtime keeps it current, so a
 * rank renamed or taken away a moment ago is already right there. Without it,
 * the rank the server attached to the message when it was read.
 */
export function messageRank(message: Message): RankEntry | null {
  if (message.isBot() || message.isSystem()) return null;

  const user = message.user();

  if (!user) return null;

  const book = bookFor(message.channelId());

  if (book) return displayedRank(book, Number(user.id()));

  return message.authorRank() ?? null;
}

/** The label: what the owner typed, or the translated default of a built-in. */
export function rankName(rank: RankEntry): string {
  if (rank.name) return rank.name;

  if (rank.builtin === "owner") {
    return app.translator.trans(
      "ramon-chat.forum.ranks.owner_default",
      {},
      true,
    ) as string;
  }

  if (rank.builtin === "moderator") {
    return app.translator.trans(
      "ramon-chat.forum.ranks.moderator_default",
      {},
      true,
    ) as string;
  }

  return "";
}

export function validIcon(icon: string | null | undefined): string | null {
  return icon && ICON_PATTERN.test(icon) ? icon : null;
}

/**
 * The tag. A custom colour comes in as custom properties the stylesheet reads;
 * a built-in left at its default has none, and its class picks the theme's.
 */
export function rankTag(rank: RankEntry, className = ""): Mithril.Children {
  const custom = Boolean(rank.color);
  const icon = validIcon(rank.icon);
  const name = rankName(rank);

  return (
    <span
      className={classList("ChatRankTag", className, {
        [`ChatRankTag--${rank.builtin}`]: !custom && rank.builtin !== null,
      })}
      style={
        custom
          ? {
              "--chat-rank-bg": rank.color!,
              "--chat-rank-fg": rank.textColor ?? "#ffffff",
            }
          : undefined
      }
      title={name}
      data-rank={rank.key}
    >
      {icon ? <i className={icon} aria-hidden="true" /> : null}
      <span className="ChatRankTag-label">{name}</span>
    </span>
  );
}

/**
 * Attributes for the element around a name drawn in the rank's colour: the
 * rank without its tag. Empty for a rank shown as a tag, and for none.
 */
export function rankNameAttrs(rank: RankEntry | null): {
  className?: string;
  style?: Record<string, string>;
  title?: string;
} {
  if (!rank || rank.showBadge) return {};

  const title = rankName(rank);

  if (!rank.color) {
    return {
      className: `ChatRankName ChatRankName--${rank.builtin ?? "default"}`,
      title,
    };
  }

  return {
    className: "ChatRankName",
    style: {
      "--chat-rank-name": rank.nameLight ?? rank.color,
      "--chat-rank-name-dark": rank.nameDark ?? rank.color,
    },
    title,
  };
}
