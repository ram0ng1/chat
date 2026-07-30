import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import { displayEmoji } from './emoji';

/**
 * The mark that stands for a channel: its picture, its emoji, or a hash.
 *
 * One helper rather than the same ternary in the sidebar, the browse page, the
 * channel header and the info modal. Those four had already drifted once — three
 * fell back to `fa-hashtag` and the fourth to nothing — and adding a third case to
 * each of them by hand would have guaranteed it happened again.
 *
 * Precedence is picture, then emoji. An emoji is the cheap default and most
 * channels keep it; an upload is a deliberate act, so it wins while it exists and
 * removing it falls back to whatever emoji was already set.
 */
export function channelIcon(channel: Channel): Mithril.Children {
  const image = channel.imageUrl();

  if (image) {
    return (
      <img
        className="ChatChannelIcon-image"
        src={image}
        alt=""
        // Decorative: the channel's name is always beside it, so announcing the
        // picture too would just make a screen reader say the name twice.
        aria-hidden="true"
        loading="lazy"
      />
    );
  }

  const emoji = channel.emoji();

  if (emoji) return displayEmoji(emoji);

  return <i className="fas fa-hashtag" aria-hidden="true" />;
}

export default channelIcon;
