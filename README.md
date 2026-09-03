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
- Private channels an admin adds people to, and removes people from
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

## License

[MIT](LICENSE). Suggestions and bug reports go in the [issue tracker](https://github.com/ram0ng1/chat/issues).
