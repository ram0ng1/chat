# Roadmap

Feature parity target: [Discourse Chat](https://meta.discourse.org/t/discourse-chat/230881).

Phase 1 is done. Each item below names the exact files to create; the `extend.php`
wiring already exists as a commented block, so nothing needs re-deriving.

---

## Phase 1 — Foundation ✅

Schema, models, security, service layer, API resources, LESS design system, admin
settings, English locale. See the status table in [`../README.md`](../README.md).

---

## Phase 2 — Make it usable

The minimum for a working chat. Nothing in phases 3–4 matters until this lands.

### 2a. Backend completion

| File | Purpose |
|---|---|
| `src/Api/Controller/UploadController.php` | Multipart attachment upload → `chat` filesystem disk. Validate against `allow_uploads`, `max_upload_size`, and `ramon-chat.upload`. Extract image dimensions into `width`/`height`. |
| `src/Api/Controller/StartDirectController.php` | Find-or-create a direct channel for a participant set. Must reuse an existing channel for the same participants — that is what makes "restarting a DM links you back to earlier messages" work. |
| `src/Api/Controller/DraftController.php` | `Draft::store()` passthrough. |
| `src/Api/Controller/ListDraftsController.php` | The actor's drafts, so the client can restore them on load. |
| `src/Listener/SendChatNotifications.php` | Fan-out on `MessageWasSent`. Consult `ChannelUser::wantsNotificationFor()`; expand group and `@here`/`@all` mentions via `UnreadTracker::mentionedUserIds()`. |
| `src/Notification/ChatMentionBlueprint.php` | `alert` + `email`. |
| `src/Notification/ChatMessageBlueprint.php` | `alert` only — a message notification must never become an email per message. |
| `src/Listener/RecalculateUnreadCounts.php` | Calls `UnreadTracker::recalculate()` after delete/move. |
| `src/Listener/AutoJoinUsers.php` | Honours `Channel::$auto_join` on create. Use `MembershipManager::addMany()`, which is already chunked. |
| `src/Console/PruneChatCommand.php` | Retention sweep: messages past `channel_retention_days` / `dm_retention_days`, plus orphaned uploads (`Upload::isOrphaned()`) and their files on disk. Must call `Channel::refreshMetadata()` afterwards. |

### 2b. Realtime

`src/Realtime/BroadcastListener.php`, guarded by the existing
`whenExtensionEnabled('flarum-realtime')` conditional.

`flarum/realtime` gives us, server-side:

```php
(new \Flarum\Realtime\Extend\Realtime())
    ->broadcastModelEvent($events, $getModel, $getActor, $eventName)
    ->registerModelEndpoint(\Ramon\Chat\Message::class, 'chat-messages')
    ->registerModelEndpoint(\Ramon\Chat\Channel::class, 'chat-channels')
```

`registerModelEndpoint` is **required** — the payload generator makes an internal
JSON:API request per recipient to build a permission-correct payload, and it needs
the model→endpoint mapping to do it.

Client-side, from `js/src/forum/realtime.ts`:

```ts
import { RealtimeExtend } from 'flarum/realtime/forum';

export default [
  ...('flarum-realtime' in flarum.extensions
    ? [new RealtimeExtend().onPublicChannelEvent('chatMessage', handler)]
    : []),
];
```

**Caveat:** realtime's channels are the forum-wide public channel and the user's
private channel — there is no per-chat-channel Pusher channel. Private channel
traffic must therefore be routed over the **user** channel via
`broadcastDialogEvent`-style fan-out, not the public one, or messages in a
restricted channel would be delivered to every connected client. This is the single
highest-risk item in the phase; get the channel selection right before the UI.

Fallback when realtime is absent: poll `GET /api/chat-messages?filter[channel]=N`
with the newest known id as a cursor.

### 2c. Frontend core

State first — the components are thin over it.

| File | Purpose |
|---|---|
| `js/src/forum/state/ChatState.ts` | Channel list, per-channel message pages, unread, drafts, presence, typing, selection. Single source of truth; components stay presentational. |
| `js/src/forum/components/ChatDrawer.tsx` | Floating panel. Styles exist: `.ChatDrawer`. |
| `js/src/forum/components/ChatPage.tsx` | Full-screen. `.ChatPage`. |
| `js/src/forum/components/ChatSidebar.tsx` | My Threads / Search / Channels / DMs. `.ChatSidebar`, `.ChatChannelRow`. |
| `js/src/forum/components/ChannelView.tsx` | Header, stream, date separators, unread divider. `.ChatChannel`, `.ChatDateSeparator`, `.ChatUnreadDivider`. |
| `js/src/forum/components/ChatMessage.tsx` | Grouping (`Message::isGroupedWith()`), mention highlight (`mentionsActor()`), tombstones (`isRedacted()`), hover actions, reactions, uploads, thread indicator. `.ChatMessage`, `.ChatReactions`, `.ChatUploads`, `.ChatThreadIndicator`. |
| `js/src/forum/components/ChatComposer.tsx` | Auto-grow textarea, reply/edit context bar, pending attachments, counter. `.ChatComposer`. |

Register the routes declared in `extend.php` (`chat.index`, `chat.channel`,
`chat.thread`, `chat.browse`, `chat.threads`, `chat.search`). They are currently
served but unmapped client-side, so those URLs render "not found" until this lands.

Also: `ChatNavButton.open()` currently routes to the page in both branches. Wire
the drawer branch once `ChatDrawer` exists.

---

## Phase 3 — Depth

- **Threads UI** — `ThreadPanel.tsx` (`.ChatThreadPanel`), thread titles, tracking bell, "My Threads" page via `filter[participating]`.
- **Composer richness** — `@`/`:` autocomplete (`.ChatAutocomplete`), emoji picker, drag-and-drop upload, date insertion, draft persistence.
- **Browse channels** — `BrowseChannelsPage.tsx` (`.ChatBrowse`, `.ChatBrowseCard`), filters wired to the existing `ChannelTypeFilter` / `ChannelStatusFilter` / `ChannelFollowingFilter` / `ChannelSearchFilter`.
- **Channel info panel** — `.ChatChannelInfo`; Settings + Members tabs, notification level, mute, auto-join, close/archive/delete.
- **Search** — per-channel and global, via `MessageSearchFilter`; plus `src/Search/MessageSearcher.php` for the global search driver.
- **Selection mode** — `.ChatSelectionBar`; "Quote in discussion", "Copy", "Move to channel". `TranscriptRenderer` already produces both the markup and plain-text forms.
- **Preferences page** — `/settings` chat pane over the five registered user preferences.
- **`pt-BR.yml`** — mirror of `en.yml`. Not yet written.

---

## Phase 4 — Integrations and polish

- **Incoming webhooks** — `WebhookDeliveryController` (Slack-compatible payload), admin CRUD over `.ChatWebhookList`. `Webhook` model, `url()`, `recordDelivery()` and CSRF exemption are already in place.
- **Channel archiving UI** — dialog for new-vs-existing discussion. `ChannelArchiver` is done and chunks at 200 messages/post.
- **Presence** — online halo (`.chat-online-ring()` mixin exists), typing indicator (`.ChatTyping` exists, animation included).
- **Message edit history** — `chat_message_revisions` is populated by `Message::reviseContent()`; needs a viewer.
- **Flagging** — route chat messages into `flarum/flags`.
- **Keyboard shortcuts** — arrow-up to edit last message, `Esc` to cancel, `/` to focus.
- **Bookmarks page** — `filter[bookmarked]=1` already works.
- **Tests** — `tests/` is scaffolded in `composer.json` but empty. Priority: the visibility scopes and the `?bool` policy semantics, since those are the security boundary.

---

## Known gaps to close deliberately

1. **Realtime channel selection for private channels** (2b) — a correctness and privacy issue, not a polish item.
2. **`ChatNavButton` drawer branch** is a placeholder.
3. **`js/dist/*.js` are placeholders** — run `yarn --cwd js install && yarn --cwd js build`.
4. **No `pt-BR.yml`** yet.
5. **`Upload::url()` and `Webhook::url()` use `resolve()`** — acceptable in a model accessor, but inject `UrlGenerator` if these move onto a hot path.
