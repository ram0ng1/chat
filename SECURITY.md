# Security policy

## Supported versions

Only the latest released version of `ramon/chat` receives security fixes. There
are no long-term support branches: if you are behind, upgrade first and report
again if the issue persists.

## Reporting a vulnerability

**Do not open a public issue for a security problem.** A chat extension holds
private conversations, and an issue describing how to read them is a working
exploit for every forum running it until a release lands.

Report privately through GitHub's [private vulnerability
reporting](https://github.com/ram0ng1/chat/security/advisories/new). If that is
unavailable to you, email <suporte@ascon.com.br> with `ramon/chat` in the
subject.

Please include:

- the extension version and the Flarum version,
- which permissions the acting account held (guest, member, moderator, admin),
- what you were able to read, write, or delete that you should not have been,
- a request or reproduction path, if you have one.

You will get an acknowledgement within a few days. Fixes are released as soon as
one is ready and correct; you will be credited in the advisory unless you ask
not to be.

## Scope

In scope — anything that lets an account reach chat data its permissions do not
grant, including:

- reading messages, channels, or direct conversations without access,
- posting, editing, or deleting as another account,
- escalating chat permissions, or bypassing `ramon-chat.use` and the per-channel
  gates,
- leaking data through the API, notifications, realtime broadcasts, or uploads.

Out of scope:

- findings that require an already-compromised administrator account, unless the
  extension makes the damage materially worse than core would,
- a forum that granted a chat permission to the Guest or Member group on
  purpose — that is configuration, and the README explains how to close it,
- vulnerabilities in Flarum core or in another extension. Report those to their
  maintainers.

## What this extension assumes of its host

Chat trusts Flarum core for authentication, sessions, CSRF, and the permission
grid. It does not add an authentication path of its own, and it does not create
API keys. If your report depends on core behaving differently than documented,
say so — it changes who should fix it.
