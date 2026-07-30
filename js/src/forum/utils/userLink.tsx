import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import username from 'flarum/common/helpers/username';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';

/**
 * A username that goes to the profile.
 *
 * `username()` alone renders text, which is what the chat shipped with — the one
 * place in Flarum where a name was not clickable. Everywhere else in the forum a
 * name is a link, and a chat is exactly where you most often want to look someone
 * up.
 *
 * Falls back to plain text for a deleted or unloaded author: `app.route.user()`
 * needs a real record, and a link to nowhere is worse than no link.
 */
export default function userLink(user: User | null | false | undefined): Mithril.Children {
  if (!user) return username(user as any);

  return (
    <Link className="ChatUserLink" href={app.route.user(user)}>
      {username(user)}
    </Link>
  );
}
