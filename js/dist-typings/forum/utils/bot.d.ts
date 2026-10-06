import type Mithril from "mithril";
import type Message from "../../common/models/Message";
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
export declare function botName(): string;
/**
 * The uploaded file wins over a typed URL.
 *
 * Both are kept: an admin who uploads a picture and later removes it gets their
 * external URL back rather than an empty field. Precedence goes to the upload
 * because it is the more deliberate act — you type a URL once and forget it.
 */
export declare function botAvatarUrl(): string | null;
/**
 * The avatar, shaped like core's so it sits in the same gutter as everyone else's.
 *
 * With no image configured it falls back to an initial on a coloured disc, which is
 * what core does for a user without an avatar — the row should not be identifiable
 * as a bot post by the shape of a hole where the picture goes.
 */
export declare function botAvatar(className?: string): Mithril.Children;
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
export declare function authorAvatar(message: Message, className?: string): Mithril.Children;
/** The author's name as plain text — for summaries and one-line rows. */
export declare function authorName(message: Message): Mithril.Children;
/** The author's name as a link to their profile. The bot has none to link to. */
export declare function authorLink(message: Message): Mithril.Children;
