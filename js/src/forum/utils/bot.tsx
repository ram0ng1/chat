import app from "flarum/forum/app";
import Avatar from "flarum/common/components/Avatar";
import username from "flarum/common/helpers/username";
import type Mithril from "mithril";

import type Message from "../../common/models/Message";
import userLink from "./userLink";

/**
 * The chat bot's identity.
 *
 * There is no user account behind it — deliberately. An account would need a row
 * in `users`, would appear in member lists and mention autocomplete, and would be
 * something that could in principle be logged into. The bot is only ever an author
 * label, so it is a pair of settings instead: nothing to authenticate as, nothing
 * to impersonate, nothing to clean up if the feature is turned off.
 */

/** Falls back to the forum's own name, so an unconfigured bot still reads sensibly. */
export function botName(): string {
  const configured = app.forum.attribute<string | null>("ramon-chat.botName");

  if (typeof configured === "string" && configured.trim() !== "") {
    return configured.trim();
  }

  return app.translator.trans("ramon-chat.forum.bot.default_name", {}, true);
}

/**
 * The uploaded file wins over a typed URL.
 *
 * Both are kept: an admin who uploads a picture and later removes it gets their
 * external URL back rather than an empty field. Precedence goes to the upload
 * because it is the more deliberate act — you type a URL once and forget it.
 */
export function botAvatarUrl(): string | null {
  const uploaded = app.forum.attribute<string | null>(
    "ramon-chat.botAvatarPath",
  );

  if (typeof uploaded === "string" && uploaded.trim() !== "") {
    return `${app.forum.attribute("assetsBaseUrl")}/${uploaded.trim()}`;
  }

  const url = app.forum.attribute<string | null>("ramon-chat.botAvatarUrl");

  return typeof url === "string" && url.trim() !== "" ? url.trim() : null;
}

/**
 * The avatar, shaped like core's so it sits in the same gutter as everyone else's.
 *
 * With no image configured it falls back to an initial on a coloured disc, which is
 * what core does for a user without an avatar — the row should not be identifiable
 * as a bot post by the shape of a hole where the picture goes.
 */
export function botAvatar(className = "Avatar"): Mithril.Children {
  const url = botAvatarUrl();
  const name = botName();

  if (url) {
    return <img className={className} src={url} alt={name} loading="lazy" />;
  }

  return (
    <span className={`${className} Avatar--bot`} aria-label={name}>
      {name.charAt(0).toUpperCase()}
    </span>
  );
}

/**
 * The avatar for a message, whoever wrote it.
 *
 * A bot message has no `user_id` — deliberately, since there is no account behind
 * it — so anything that renders `message.user()` directly gets a null relation and
 * draws it as a deleted account. That is what the bookmark list did: every pinned
 * announcement appeared as `[deleted]` with a blank disc.
 *
 * Written once here because four places need the same distinction, and the fourth
 * one to be written got it wrong.
 */
export function authorAvatar(
  message: Message,
  className = "Avatar",
): Mithril.Children {
  return message.isBot() ? (
    botAvatar(className)
  ) : (
    <Avatar user={message.user()} className={className} />
  );
}

/** The author's name as plain text — for summaries and one-line rows. */
export function authorName(message: Message): Mithril.Children {
  return message.isBot() ? botName() : username(message.user());
}

/** The author's name as a link to their profile. The bot has none to link to. */
export function authorLink(message: Message): Mithril.Children {
  return message.isBot() ? botName() : userLink(message.user());
}
