import Avatar from "flarum/common/components/Avatar";
import classList from "flarum/common/utils/classList";
import type Mithril from "mithril";

import type Channel from "../../common/models/Channel";
import { displayEmoji } from "./emoji";

/**
 * The mark that stands for a channel: its picture, the people in it, its emoji,
 * or a hash.
 *
 * One helper rather than the same ternary in the sidebar, the browse page, the
 * channel header and the info modal. Those four had already drifted once — three
 * fell back to `fa-hashtag` and the fourth to nothing — and adding a third case to
 * each of them by hand would have guaranteed it happened again. It did: the
 * sidebar grew an avatar stack for direct channels and the other three kept
 * drawing a hash, so the same conversation was a face in the list and a `#`
 * everywhere else. The stack lives here now and all four get it.
 *
 * Precedence is picture, then participants, then emoji. An emoji is the cheap
 * default and most channels keep it; an upload is a deliberate act, so it wins
 * while it exists and removing it falls back to whatever was already set. A
 * conversation is named after who is in it, so its participants outrank a glyph —
 * but not a picture a moderator went out of their way to set on it.
 *
 * Returns the slot element itself rather than its contents, so `className` is
 * what each surface passes to keep its own sizing. That is also what lets the
 * avatar case widen the slot: a stack does not fit the fixed width a single glyph
 * is given, and the modifier that releases it can only be applied where the
 * branch is decided.
 */
export function channelIcon(
  channel: Channel,
  className = "",
): Mithril.Children {
  const image = channel.imageUrl();

  if (image) {
    return (
      <span className={classList("ChatChannelIcon", className)}>
        <img
          className="ChatChannelIcon-image"
          src={image}
          alt=""
          // Decorative: the channel's name is always beside it, so announcing the
          // picture too would just make a screen reader say the name twice.
          aria-hidden="true"
          loading="lazy"
        />
      </span>
    );
  }

  if (channel.isDirect()) {
    // Two at most. A group conversation of six would otherwise push everything
    // beside it off the row, and the name already carries the full list.
    const others = channel.others().slice(0, 2);

    if (others.length > 0) {
      return (
        <span
          className={classList(
            "ChatChannelIcon ChatChannelIcon--avatars",
            className,
          )}
        >
          {others.map((user) => (
            <Avatar key={user.id()} user={user} className="Avatar" />
          ))}
        </span>
      );
    }

    // A conversation whose participants are not loaded, or one with nobody left
    // in it. An envelope rather than a hash: it is still a conversation, and the
    // hash would say it is a channel anyone could join.
    return (
      <span className={classList("ChatChannelIcon", className)}>
        <i className="fas fa-envelope" aria-hidden="true" />
      </span>
    );
  }

  const emoji = channel.emoji();

  return (
    <span className={classList("ChatChannelIcon", className)}>
      {emoji ? (
        displayEmoji(emoji)
      ) : (
        <i className="fas fa-hashtag" aria-hidden="true" />
      )}
    </span>
  );
}

export default channelIcon;
