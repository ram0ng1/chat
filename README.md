# Chat — `ramon/chat`

Discourse-style realtime chat for **Flarum v2**: category-scoped public channels,
threads, direct and group messages, reactions, mentions, uploads and search —
integrated with Flarum's discussions, notifications and search rather than bolted
beside them.

Styled against the **Avocado** design system.

---

## Provenance

This repository began as a clone of [`Push-EDX/flarum-ext-chat`][upstream], whose
last release targeted **Flarum 0.1.0-beta.8 (2018)**. That codebase used Mithril
0.2 (`controller`/`config`/`context`), the removed `ConfigureApiRoutes` and
`Serializing` events, `app.pusher`, and inlined ~85 KB of base64 audio into a
component file. None of it survives contact with Flarum 2, so this is a **ground-up
rewrite**, not a migration.

The original tree is preserved in git history at `fa24070` (see
`.legacy-upstream-ref.txt`). Nothing was silently discarded.

[upstream]: https://github.com/Push-EDX/flarum-ext-chat

---

## Status

**Installed and verified** on Flarum **v2.0.0-rc.5** / PHP 8.5.4 / MySQL 8.0.30.

| Check | Result |
|---|---|
| `php -l` across the tree | 96 files, 0 errors |
| `tsc --noEmit` | 0 errors |
| `webpack --mode production` | compiles; `forum.js` ~48 KB, `admin.js` ~3.7 KB |
| Migrations applied | 13/13 registered, 12 tables, 31 foreign keys |
| Permissions seeded | 7 rows |
| End-to-end smoke test | **35/35 passing** |
| `GET /` and `GET /chat` | 200, chat bundle + `canUseChat` present |
| `GET /api/chat-channels` unauthenticated | 401 `not_authenticated` (correct) |

The smoke test exercises what only a real database can validate: the per-channel
`number` sequence under repeated inserts, the visibility scopes (including that a
guest sees nothing), `MessageDispatcher`'s transaction, thread creation and
back-fill, mention resolution and fan-out targets, unread counters against
`recalculate()`, every search filter, and the policy semantics — including that a
closed channel blocks posting *for admins too*.

### Complete

| Area | What's there |
|---|---|
| **Schema** | 13 migrations. `chat_channels`, `chat_channel_user`, `chat_threads`, `chat_thread_user`, `chat_messages`, `chat_message_reactions`, `chat_message_mentions`, `chat_uploads`, `chat_drafts`, `chat_bookmarks`, `chat_message_revisions`, `chat_webhooks`, plus default permissions. |
| **Models** | 11 Eloquent models with relations, casts, and derived helpers. Per-channel monotonic `number` sequence assigned via SQL subquery so concurrent sends cannot collide. |
| **Security** | 3 visibility scopes + 4 policies. Category channels **inherit their bound tag's `viewForum` permission**, so category permissions govern chat access with no parallel permission surface. Fails closed when `flarum/tags` is absent. |
| **Service layer** | `MessageDispatcher` (single transactional send path), `MentionResolver`, `UnreadTracker`, `MembershipManager`, `RateLimiter`, `ChannelArchiver`, `TranscriptRenderer`. |
| **API** | `ChannelResource`, `MessageResource`, `ThreadResource`, `UploadResource`. Custom endpoints for read/join/leave/notifications/status/archive, send/edit/delete/restore/react/bookmark, thread read/tracking. Filtering lives in `src/Search/` — 3 searchers, 12 filters. |
| **Design system** | LESS anchored to Avocado tokens, with a fallback layer mapping every `--avocado-*` token onto a Flarum core variable so the chat is legible on any theme. Dark mode, reduced-motion, and a full component sheet. |
| **Admin** | Settings page (retention, limits, uploads, behaviour) + 7 registered permissions. |
| **Locale** | `en.yml` and `pt-BR.yml`, 194 keys each. |

Also complete: 7 controllers, 2 notification blueprints, 4 listeners, the retention
command, realtime broadcasting, 3 searchers with 12 filters, and the frontend core
(state, drawer, full-screen page, sidebar, channel view, message rows, composer,
realtime client with a polling fallback). Locale is complete in **en** and
**pt-BR**, 194 keys each, key sets verified identical.

### Still to build — see [`docs/ROADMAP.md`](docs/ROADMAP.md)

Phases 3–4: thread panel UI, `@`/`:` autocomplete and emoji picker, browse-channels
page, channel info panel, search UI, selection mode (quote/copy/move), webhook admin
CRUD, presence halo, edit-history viewer, flagging, keyboard shortcuts, and tests.

The backend for most of these already exists — `TranscriptRenderer`,
`ChannelArchiver`, `MoveMessagesController`, `Webhook`, and the bookmark and
participating filters are all in place and unused by the UI so far.

---

## Architecture notes

Decisions that are load-bearing and non-obvious:

**Channels inherit tag permissions.** A category channel stores a `tag_id` and its
visibility scope resolves through `Tag::whereHasPermission($actor, 'viewForum')`.
Creating a channel on a restricted category restricts the channel, with no second
permission model to keep in sync. When `flarum/tags` is unavailable, tag-bound
channels become **invisible** rather than public — the only safe direction for an
inherited-permission model.

**Policies return `?bool`, not `bool`.** Flarum's `Gate` only applies its
`isAdmin()` fallback when *no* policy reached a decision. Returning `false` from a
policy therefore denies admins too. Every policy here returns `true` to grant,
`false` only for structural invariants that must hold for everyone (posting into an
archived channel, editing a system message), and `null` when the actor merely
lacks a permission. Getting this backwards silently locks admins out of their own
forum, which is why it is called out here.

**Moderators delete, authors edit.** `MessagePolicy::edit` refuses non-authors
outright, including moderators. Silently rewriting attributed speech is a
different power from removing it, and Discourse draws the same line.

**Flarum 2 has no JSON:API resource filters.**
`AbstractDatabaseResource::filters()` is `final` and throws, pointing extensions at
the search driver. Every `filter[...]` therefore goes through a searcher
(`ChannelSearcher`, `MessageSearcher`, `ThreadSearcher`) plus `FilterInterface`
implementations in `src/Search/Filter/`; `Endpoint\Index` routes through
`SearchManager` once a model is registered as searchable. Declaring a `filters()`
method on a resource is a fatal error at boot, not a no-op.

**Chat is never broadcast on the `public` websocket channel.** `flarum/realtime`
offers only `public` (every connected client, guests included) and
`private-user=<id>`. Since chat channels are permission-scoped, putting a message
on `public` would hand every restricted channel to every browser. `ChatBroadcaster`
addresses each recipient's private channel instead, choosing the audience as
*connected ∩ members* and then re-checking the visibility scope — membership rows
outlive permission changes, so the second check is not redundant.

**Frontend startup runs from `extend(app, 'mount')` — the instance, not
`ForumApplication.prototype`, and not `app.beforeMount()`.** Four constraints
force this shape, and getting it wrong took the whole forum down once:

- `Application.boot()` runs the initializers *before* it assigns `app.forum` and
  `app.session`, so neither can be read at initializer time.
- `mount()` is what calls `m.route()`. Core mounts its own secondary roots
  (navigation, header) *after* that call, with a comment saying so. Mounting a
  Mithril root earlier attaches it to a router that does not exist yet.
- `runBeforeMount()` has **no** try/catch. Anything thrown there stops `mount()`
  from ever running, and the symptom is not a chat error — it is a forum that
  renders nothing, cannot open a discussion, and cannot create anything.
- `ForumApplication` cannot be imported at initializer time. Core registers it via
  `flarum.reg.addChunkModule`, so `flarum.reg.get('core', 'forum/ForumApplication')`
  resolves to `undefined` until that chunk executes, and `.prototype` on it is a
  TypeError. `flarum/forum/app` — the instance — is available, so extending the
  instance installs an own property that shadows the prototype method; `boot()`
  calls `this.mount()`, so the override is picked up.

The startup block is additionally wrapped in try/catch. `extend()` already traps
callback errors via `handleErrorOnce`, but the inner guard keeps a chat failure
from even being reported as a forum error.

The general rule this cost three attempts to learn: **only import core modules that
are proven to resolve eagerly.** `forum/app`, `common/extend`, `common/Component`,
`common/components/*` and `common/helpers/*` all do. Anything else should be reached
through the string form of `extend()`/`override()`, which defers via
`flarum.reg.onLoad`, or not imported at all.

**Admin registration is `app.registry`, not `app.extensionData`.** `extensionData`
is the Flarum 1 name and is simply absent in Flarum 2, where the surface is
`AdminRegistry` exposed as `app.registry`. Reading `.for()` off `undefined` aborts
the admin initializer loop and takes the entire admin panel with it.

**A failed notification must not fail the message.** On the sync queue driver
`NotificationSyncer` mails inline, so an unreachable mail server would otherwise
propagate out of the listener and abort the send. `SendChatNotifications` catches
and logs instead: a message that was accepted stays accepted.

**Unread state is denormalised.** The sidebar renders every followed channel on
every draw, so counters live on `chat_channel_user` rather than being computed as
"rows newer than `last_read_message_id`". `UnreadTracker::recalculate()` rebuilds
them from source after deletes and moves, where incremental drift is possible.

**Muting ≠ level 0.** A muted channel stops contributing unread badges as well as
notifications; level 0 only suppresses notifications. Both are needed.

**One send path.** All user-authored messages go through
`MessageDispatcher::send()`, which holds validation, rate limiting, mention
resolution, thread bookkeeping, upload binding, counter maintenance and unread
fan-out in a single transaction. Bypassing it will produce inconsistent counters.

---

## Requirements

- PHP 8.3+
- Flarum ^2.0
- `flarum/tags` — for category-scoped channels *(optional; forum-wide channels work without it)*
- `flarum/realtime` — for live delivery, typing and presence *(optional; polling fallback otherwise)*
- `flarum/mentions` — for `@user` / `@group` parity with discussions *(optional)*
- `flarum/emoji` — for `:shortcode:` rendering *(optional)*

## Permissions

| Permission | Default |
|---|---|
| `ramon-chat.use` | Moderators |
| `ramon-chat.startDirect` | Moderators |
| `ramon-chat.upload` | Moderators |
| `ramon-chat.react` | Moderators |
| `ramon-chat.mentionChannelWide` | Moderators |
| `ramon-chat.createChannel` | Administrators |
| `ramon-chat.moderate` | Moderators |

Discourse ships chat staff-only and has admins widen it via *chat allowed groups*.
This mirrors that: grant `ramon-chat.use` to Members once you're ready.

## License

MIT

---

## Installation notes for this site

Installed into the Laragon Flarum at `d:/laragon/www/flarum` (whose `config.php`
url is `https://alegatest.alega.com.br`) via the existing `workbench/*/` path
repository:

```bash
composer require ramon/chat:*@dev     # junctions vendor/ramon/chat -> workbench/chat
php flarum extension:enable ramon-chat # also runs the 13 migrations
php flarum assets:publish
php flarum cache:clear
```

Pre-change backups of the site's composer files were left at
`composer.json.bak-pre-chat` and `composer.lock.bak-pre-chat`.

`composer require` also refreshed the lock entries for `ramon/avocado`,
`ramon/dfs` and `ramon/point-system`. Those are path repositories, so composer
simply recorded the branch already checked out in each workbench directory — no
files in them were modified.

Permissions default to moderators only. Grant `ramon-chat.use` to Members in
**Admin → Permissions** to open the chat up.

After editing anything under `js/src`:

```bash
cd js && npm install && npm run build && cd .. && php ../../flarum assets:publish
```
