<p align="center">
  <img src="icon.svg" width="80" height="80" alt="Chat">
</p>

<h1 align="center">Chat</h1>

<p align="center">
  <a href="https://github.com/ram0ng1/chat/actions/workflows/ci.yml"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/ram0ng1/chat/ci.yml?branch=main&style=flat-square&label=ci"></a>
  <a href="https://packagist.org/packages/ramon/chat"><img alt="Packagist" src="https://img.shields.io/packagist/v/ramon/chat?style=flat-square&label=packagist"></a>
  <a href="https://packagist.org/packages/ramon/chat"><img alt="Downloads" src="https://img.shields.io/packagist/dt/ramon/chat?style=flat-square"></a>
  <img alt="Flarum" src="https://img.shields.io/badge/flarum-2.x-e7672e?style=flat-square">
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-MIT-blue?style=flat-square"></a>
  <a href="https://donate.stripe.com/fZe5o66nebkf39S28a"><img alt="Donate" src="https://img.shields.io/badge/donate-stripe-6772E5?style=flat-square"></a>
</p>

<p align="center">Discourse-style realtime chat for Flarum 2.</p>

Chat adds public channels, threads and direct messages to a Flarum forum, wired into the parts of Flarum you already run. A channel bound to a category inherits that category's permissions, mentions arrive through Flarum's notifications, and messages are searchable alongside everything else.

I started it because every chat extension I tried sat beside the forum rather than inside it: a second permission system to keep in sync, a second notification inbox, and a second place for conversations to go missing.

## What it does

- Public channels scoped to a category, so category permissions govern who reads and posts, with no parallel permission surface to keep in sync
- Threads on any message, each with its own reply tracking and notification level
- Direct and group messages, with the invite delivered through Flarum's notifications
- Private channels people are invited into: the invitation arrives as a notification with Accept and Decline, nobody is a member until they say yes, and whoever asked hears back if they say no
- A permission to inspect any channel unnoticed: no place in the member list, no arrival or departure announced, no notification written
- Realtime delivery through `flarum/realtime`, falling back to polling when it is not installed
- Reactions, `@` mentions including `@here` and `@all`, image and file uploads, and full text search across every channel you can read
- New discussions in a bound category announced into the channel, posted by the chat's bot or by a member you nominate
- Pinned messages, edit history, and moderation with attribution, so a removed message names the moderator who removed it
- A drawer that follows you around the forum and a full screen page, sharing one conversation state
- Plays nice with `flarum/gdpr` for export, anonymization and erasure, and with `flarum/audit` for the trail

## Installation

```sh
composer require ramon/chat
php flarum migrate
php flarum cache:clear
```

Enable Chat on the Extensions page. Channels, the announcer, the bot and permissions are all managed in the admin panel, each option explained in place.

Optional companions: `flarum/tags` unlocks category scoped channels, `flarum/realtime` makes delivery, typing and presence instant, `flarum/mentions` gives `@user` parity with discussions, and `flarum/emoji` renders `:shortcode:`. None of them are required.

## Good to know

- Installing seeds `ramon-chat.use`, `startDirect`, `upload` and `react` to **Members**, which is every registered account, and moderation to Moderators. To run the chat for a subset instead, go to **Admin → Permissions** and *remove* the Members row from `ramon-chat.use` before adding your group — adding a group without removing Members leaves the chat open to everyone.
- Those defaults are seeded once, on the install migration. Upgrades never reapply them, so a permission you revoke stays revoked.
- Channels inherit `viewForum` from the tag they are bound to, so a private category produces a private channel with no extra configuration. On top of that, each channel chooses who may post: everyone, or moderators only.
- Attachments follow their channel. A file posted in a public channel is served straight from `public/assets/chat`; one posted in a private channel, a direct conversation or a channel on a restricted category is kept under `storage/chat-uploads`, outside the webroot, and served through `/api/chat/uploads/{id}/file` only to people who can see the message. Making a channel private, or moving a message into one, moves its files as well. Upgrading runs a migration that moves what was already there, so `storage/` must be writable when you run `php flarum migrate`.
- The announcer posts as a bot by default, an ordinary message with no account behind it. Its name and picture are settings, so there is nothing to log into and nothing to impersonate. Nominate a member instead and the bot disappears entirely.
- Everything the frontend does goes through the `/api/chat/*` endpoints, so channels, messages and membership can also be driven from outside.
- Adding someone to a channel (`POST /api/chat-channels/{id}/members`) creates an invitation, not a membership. The invitee answers at `POST /api/chat/invites/{channelId}/accept` or `/decline`; a manager can withdraw one at `POST /api/chat-channels/{id}/invites/cancel`. Joining a public channel with a pending invitation accepts it. An invitee to a private channel sees the channel's row (so the invitation can be answered) and none of its messages until they accept.
- Every membership change reaches the people it concerns over `flarum/realtime` as `ramonChat.membership`, so a channel appears in the sidebar the moment its invitation is accepted and disappears the moment someone is removed, on every open tab.
- `tests/E2E/` holds end-to-end suites that run against a live forum, websocket included; see its README.

## Storing attachments with FoF Upload

With [`fof/upload`](https://github.com/FriendsOfFlarum/upload) installed and enabled, **Admin → Chat → Uploads** offers a choice of where *public* attachments are stored: this forum's disk (the default) or FoF Upload's storage, through one of its adapters (S3 and S3-compatible buckets, Qiniu, or its local directory). Only the storage is borrowed:

- **Private files never leave the forum.** Attachments in private channels, direct conversations and channels on restricted categories stay under `storage/chat-uploads` whatever is configured. A bucket is public by design.
- **Only the chat's permissions and limits apply.** Who may attach is `ramon-chat.upload`; the size limit and the allowed file types are the chat's own. FoF Upload's mime rules, size limit and permissions do not apply, and nothing is added to FoF Upload's file list or media manager, so its orphan cleanup (`fof:upload --cleanup`) never touches chat files.
- **No silent fallback.** If the adapter cannot store a file, the upload is refused with an error and the reason is logged. Files written before a change of setting stay where they were and keep working.
- **The bucket must be publicly readable**, since the chat links to the file's URL directly. Imgur is not offered: it cannot delete by path.
- **FoF Upload's `local` adapter** writes to `public/assets/files` on this server. It only saves anything if FoF Upload's CDN URL is set; the settings page warns when it is selected.
- **Deletion and CDNs.** Deleting a message, discarding a pending attachment, the nightly prune and a GDPR erasure delete the file from the bucket. A CDN in front of the bucket may keep serving a cached copy until it expires; set a short cache lifetime, or purge, if that matters.
- **Making a channel private** flags its bucket files private at once, so their bucket URL is no longer handed out, then a queued job copies each file to `storage/chat-uploads` and deletes it from the bucket. On a real queue that happens on the worker's next run; failures are logged and retried.
- **Content Security Policy.** If your forum sends a CSP, add the bucket or CDN host to `img-src` and `media-src`, or images and audio from the chat will be blocked.

## License

[MIT](LICENSE). Suggestions and bug reports go in the [issue tracker](https://github.com/ram0ng1/chat/issues).
