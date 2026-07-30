import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import Avatar from 'flarum/common/components/Avatar';
import HeaderListItem from 'flarum/forum/components/HeaderListItem';
import type Mithril from 'mithril';

import type MessageFlag from '../../common/models/MessageFlag';
import { messagePreview } from './preview';
import { authorName } from './bot';

/**
 * Puts chat reports in flarum/flags' own list.
 *
 * The database cannot hold them there: `flags.post_id` is a non-nullable foreign
 * key into `posts`, so a chat message id is rejected outright, and altering that
 * extension's schema would be undone by its next release. What *can* be shared is
 * the surface — the dropdown and the /flags page both render `FlagList`, so
 * adding to that one component puts chat reports in front of a moderator wherever
 * they already look for flags, in the same rows.
 *
 * Everything here is guarded: with flarum/flags absent none of it runs, and the
 * chat's own queue at /chat/flags is unaffected either way.
 */

/** Loaded once per page, then kept for redraws. */
let reports: MessageFlag[] = [];
let loaded = false;
let loading = false;

/**
 * Resolves a module from another extension without importing it.
 *
 * A bare `import` would be a hard dependency: webpack resolves it at build time
 * and the bundle throws on a forum where flarum/flags is not installed.
 * `checkModule` answers false instead of raising.
 */
function moduleOf(path: string): any {
  const registry = (window as any)?.flarum?.reg;

  if (!registry) return null;

  try {
    const found =
      typeof registry.checkModule === 'function'
        ? registry.checkModule('flarum-flags', path)
        : registry.get?.('flarum-flags', path);

    // The registry hands back the module namespace; the component is its default.
    return found ? found.default ?? found : null;
  } catch {
    return null;
  }
}

function load(): void {
  if (loaded || loading) return;

  loading = true;

  app.store
    .find('chat-message-flags', {
      filter: { resolved: '0' },
      sort: '-createdAt',
      page: { limit: 20 },
    })
    .then((results) => {
      reports = (Array.isArray(results) ? results : []) as unknown as MessageFlag[];
    })
    .catch(() => {
      // A forum without the chat queue, or a moderator who lost the permission
      // mid-session. Either way the flags list is still useful without this.
      reports = [];
    })
    .finally(() => {
      loading = false;
      loaded = true;
      m.redraw();
    });
}

/**
 * One report, drawn as flarum/flags draws a flagged post.
 *
 * Deliberately unkeyed. flarum/flags' own rows carry no key, and Mithril refuses
 * a fragment that mixes keyed and unkeyed children — "vnodes must either all have
 * keys or none have keys" — which would take the whole dropdown down rather than
 * just this row.
 */
function row(report: MessageFlag): Mithril.Children {
  const message = report.message();

  if (!message) return null;

  return (
    <li>
      <HeaderListItem
        className="Flag Flag--chat"
        avatar={<Avatar user={message.user() || null} />}
        icon="fas fa-comment-dots"
        content={app.translator.trans('ramon-chat.forum.flags.flarum_item', {
          username: authorName(message),
          em: <em />,
          channel: (message.channel() || null)?.displayName() ?? '',
          reason: app.translator.trans(`ramon-chat.forum.flag.reasons.${report.reason()}`, {}, true),
        })}
        excerpt={messagePreview(message, 120)}
        datetime={report.createdAt()}
        href={app.route('chat.flags')}
        onclick={(e: MouseEvent) => {
          e.redraw = false;
        }}
      />
    </li>
  );
}

export default function bindFlagsIntegration(): void {
  const FlagList = moduleOf('forum/components/FlagList');
  const FlagsDropdown = moduleOf('forum/components/FlagsDropdown');

  if (!FlagList?.prototype) return;

  // Fetched when the list mounts rather than at boot: a member who never opens the
  // flags dropdown should not pay for a request they will not read.
  extend(FlagList.prototype, 'oninit', function () {
    if (app.forum.attribute<boolean>('canModerateChat')) load();
  });

  // `override`, not `extend`. extend() discards whatever the callback returns —
  // it exists to mutate an object in place — and `content()` returns a fresh
  // array, or null when there is nothing to show. Appending to a null is not a
  // thing, so the method has to be replaced.
  override(FlagList.prototype, 'content', function (this: any, original: Function, state: any) {
    const vdom = original(state);

    if (!app.forum.attribute<boolean>('canModerateChat')) return vdom;

    const rows = reports.map(row).filter(Boolean);

    if (rows.length === 0) return vdom;

    // Appended rather than merged by date: flarum/flags paginates its own list,
    // and interleaving would put chat reports in a page that then loads more
    // posts over them.
    return vdom === null ? rows : [vdom, rows];
  });

  // The header badge counts both, so a moderator with only chat reports waiting
  // still sees the flag icon light up. A number, so `extend` cannot touch it.
  if (FlagsDropdown?.prototype) {
    override(FlagsDropdown.prototype, 'getUnreadCount', function (this: any, original: Function) {
      const own = Number(original() ?? 0);
      const chat = Number(app.forum.attribute<number>('chatOpenFlagsCount') ?? 0);

      return own + chat;
    });
  }
}
