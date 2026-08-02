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

Chat adds public channels, threads and direct messages to a Flarum forum, wired into
the parts of Flarum you already run: a channel bound to a category inherits that
category's permissions, mentions arrive through Flarum's notifications, and messages
are searchable alongside everything else.

I started it because every chat extension I tried sat *beside* the forum rather than
inside it — a second permission system to keep in sync, a second notification inbox,
and a second place for conversations to go missing.

## What it does

- Public channels scoped to a category, so category permissions govern who reads and posts — there is no parallel permission surface to keep in sync
- Threads on any message, each with its own reply tracking and notification level
- Direct and group messages, with the invite delivered through Flarum's notifications
- Private channels an admin adds people to, and removes people from
- Realtime delivery through `flarum/realtime`, falling back to polling when it is not installed
- Reactions, `@` mentions including `@here` and `@all`, image and file uploads, and full-text search across every channel you can read
- Announces new discussions from a bound category into the channel, posted by the chat's bot or by a member you nominate
- Pinned messages, edit history, and moderation with attribution — a removed message names the moderator who removed it
- A drawer that follows you around the forum and a full-screen page, sharing one conversation state

## Installation

```sh
composer require ramon/chat
php flarum migrate
php flarum cache:clear
```

Then enable Chat on the Extensions page of the admin panel.

Enabling Chat grants `ramon-chat.use`, `ramon-chat.startDirect`,
`ramon-chat.upload` and `ramon-chat.react` to **Members** — that is, to every
registered account. Moderation and channel-wide mentions go to Moderators.

To run the chat for a subset of the forum instead, go to **Admin → Permissions**,
**remove the Members row from `ramon-chat.use`**, and add the group you want.
Adding a group without removing Members leaves the chat open to everyone, because
every registered account belongs to Members implicitly.

Those defaults are seeded once, on the migration that installs the extension.
Upgrades never reapply them, so a permission you revoke stays revoked.

## Permissions

Channels inherit the `viewForum` permission of the tag they are bound to, so a
private category produces a private channel with no extra configuration. On top of
that, Chat registers the permissions a category cannot express:

| Permission | Grants |
|---|---|
| `ramon-chat.use` | Open the chat at all |
| `ramon-chat.createChannel` | Create channels |
| `ramon-chat.editChannel` | Rename and reconfigure channels |
| `ramon-chat.createThread` | Start a thread from a message |
| `ramon-chat.pinMessage` | Pin and unpin messages |
| `ramon-chat.startDirect` | Start direct and group messages |
| `ramon-chat.upload` | Attach images and files |
| `ramon-chat.react` | React to messages |
| `ramon-chat.mentionChannelWide` | Use `@here` and `@all` |
| `ramon-chat.moderate` | Delete anyone's message, manage members, join unseen |

Each channel additionally chooses who may post: everyone, or moderators only.

## The announcer

When a channel is bound to a category, new discussions in that category are posted
into the channel. Admin chooses who posts them:

- **The bot**, by default — an ordinary message with no account behind it. Its name and picture are settings, so there is nothing to log into and nothing to impersonate.
- **A member you nominate**, in which case announcements become ordinary messages from that account and no bot appears at all.

## Requirements

- PHP 8.3+
- Flarum ^2.0
- `flarum/tags` — for category-scoped channels *(optional; forum-wide channels work without it)*
- `flarum/realtime` — for live delivery, typing and presence *(optional; polls otherwise)*
- `flarum/mentions` — for `@user` parity with discussions *(optional)*
- `flarum/emoji` — for `:shortcode:` rendering *(optional)*

`flarum/gdpr` and `flarum/audit` are supported when present, through conditional
extenders, so neither is a dependency.

## Development

```sh
cd js && npm install && npm run build
```

The PHP suite runs with `vendor/bin/phpunit --testsuite unit`; CI runs it across
PHP 8.3, 8.4 and 8.5.

## License

[MIT](LICENSE). Found a bug or have an idea? [Open an issue](https://github.com/ram0ng1/chat/issues).
