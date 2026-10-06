# End-to-end tests

These run against a real forum with the extension enabled and `flarum/realtime`
serving websockets, so they prove what the PHPUnit suites cannot: that a push
leaves the server and lands in another user's socket, and that the browser draws
the right thing without a reload.

No dependencies beyond Node 22+ (global `fetch` and `WebSocket`) and Microsoft
Edge for the browser suite. PHP 8.4+ is needed once, to mint tokens.

## Setup

```powershell
# From the forum root. Creates chat_e2e_a, chat_e2e_b and chat_e2e_c (ordinary
# members), chat_e2e_mod (Moderators), chat_e2e_susp (suspended) and
# chat_e2e_unconf (unconfirmed email) if absent and writes
# tests/E2E/.tokens.json, which is git-ignored.
php workbench/chat/tests/E2E/mint-tokens.php
```

The forum URL defaults to `https://alegatest.alega.com.br`; set `CHAT_E2E_BASE`
to point elsewhere. The websocket settings are read from the forum document.
Certificates are verified normally; for a forum on a private CA, set
`NODE_EXTRA_CA_CERTS` to the CA file in the shell before running.

## Running

```powershell
node tests/E2E/run.mjs               # every suite
node tests/E2E/run.mjs invite        # only invite-flow
node tests/E2E/invite-flow.mjs       # one suite directly
```

Each check is appended to `tests/E2E/results/<suite>.jsonl`; the browser suite
also writes `tests/E2E/screenshots/*.png`. Both directories are git-ignored.

## Suites

| Suite | What it proves |
| --- | --- |
| `invite-flow.mjs` | Inviting creates an invitation, not a membership. The invitee gets the notification and the websocket push, cannot read the private channel, and can decline (owner and inviter notified live) or accept (membership, capability flags, sidebar push, the "joined, invited by" line narrated live). Withdrawing removes the notification. The public `join` endpoint answers with the channel record. |
| `join-composer.mjs` | In a headless Edge: join from Browse and open the channel with the composer drawn at once; land on an unjoined channel and join from the bar; see another user's message arrive live; answer an invitation from the notifications page and land in the private channel. |
| `invite-modal.mjs` | In a headless Edge, as a channel owner: the invite picker opens onto recently active people, searches by name, marks people already in or invited, keeps chips in the field with keyboard support, and the members tab lists a new invitation at once. Then, from Browse, the Inspect button for a holder of `inspectChannels`. |
| `membership-live.mjs` | Two headless Edges. B sits in a channel and never reloads; the administrator inspects it from Browse, opens it and leaves from the header, and B sees nothing of it (no announcement written either). Then a visible join and leave through the API: B sees both announced live and its member count settles. |
| `realtime-live.mjs` | In a headless Edge: the socket proves itself with a ping/pong within a second of opening the chat, an idle live chat sends no polls at all, a message arrives over the socket, dropping the socket restarts polling within seconds and reconnecting catches up and stands the poller down again. |
| `pinned-jump.mjs` | In a headless Edge: a pin buried under 160 newer messages is out of the first page, yet clicking the pinned strip loads the history down to it, centres and highlights it; from a fresh page a quote of it does the same, with no "not loaded" alert. |
| `transitions.mjs` | In a headless Edge: the drawer grows out of the header button, the full-screen page out of the drawer and the drawer back out of the page (the Avocado composer's morph), the page settles with no transform left behind, and with reduced motion nothing animates. |
| `channel-gone.mjs` | In a headless Edge: a channel deleted by the administrator leaves B's list without a click, B is stepped out of a deleted channel they were in with a warning (not core's not-found error), and neither comes back after a reload. |
| `channel-status.mjs` | In a headless Edge: closing a channel replaces B's composer with the closed notice live, with no error alert (a refused typing signal stays silent), and reopening brings the composer back. Archiving lands live with a link to the transcript; the status endpoint refuses an archived channel; then, from the administrator's own channel details, Unarchive brings it back closed (B's composer stays away), Reopen and Close reach B live, and the Archive action is offered again and archives it a second time. |
| `live-state.mjs` | Two headless Edges, B never reloading: a direct conversation started with B appears in B's list, a thread rename and a channel picture set and cleared reach B's records, messages moved from X to Y leave B's X and arrive in B's Y, promoting B from the members tab flips the row at once (badge and Remove moderator, one alert) and gives B the room's controls live, demoting reverses both, and a report filed and closed moves the administrator's moderation badge both ways. |
| `slow-mode-owner.mjs` | In a headless Edge: a channel owner under a 30 s slow mode sends twice in a row from the composer without being locked out (the channel reports `bypassesSlowMode`), while an ordinary member's second message is refused. Skips when members cannot create channels. |
| `ownership-transfer.mjs` | Promoting a member notifies them alone, with ids only. Handing a channel over: the owner starts it (no code in the response, nothing visible to the member yet), a wrong code is refused, a known code set by `lib/transfer-helper.php` (the real one is only mailed; no HTTP route reveals it) turns it into the member's notification, live; the member accepts and the owner's open members tab moves the OWNER badge and draws the old owner as MODERATOR without a reload. The new owner leaves and the oldest moderator inherits the channel, notified live. A declined offer notifies the owner; a cancelled one disappears. Skips when members do not run their channels. |
| `ranks.mjs` | Two headless Edges, C never reloading: a rank the owner creates and gives B reaches C on B's next message as a tag before the name, in its colour with readable text and its icon; the owner's lines carry the built-in owner tag. Turning the tag off colours B's name instead (darkened in the light theme, lightened in the dark one) live; a second rank moved above the first takes over, deleting it falls back, and deleting the last leaves no rank. When members run their channels, promoting B shows the moderator tag above the custom rank and demoting restores it. In the owner's editor, a rank created through the form shows its light and dark preview, and ticking it in the members tab tags B on C's screen. Screenshots in both themes. |
| `admin-page.mjs` | In a headless Edge, as the administrator: the settings page draws its titled cards aligned with the save bar, the webhooks card carries its switch, the permission grid explains its two chat blocks and lists the inspect row, and the webhook route refuses deliveries while the switch is off. |
| `permissions.mjs` | API only, every permission level (guest, member, moderator, admin, suspended, unconfirmed) against every chat capability, derived from `src/Access`: listing and reading public, private and direct channels, creating, posting, editing and deleting own and others' messages, pin, move, purge, restore, react, flag and the moderation queue, uploads and private attachment downloads, direct conversations (granting `startDirect` for one request and restoring it), invitations, typing, drafts, webhooks, bot avatar, channel picture, transcript, realtime ping and unseen inspection. Then isolation between two members: A probing B's direct conversation, private channel, messages, drafts, bookmarks, uploads, counters, flags and websocket channel by id, mass-assignment of ownership fields, and guest variants. Every response is swept at the end for markers planted in B's private content, so a leak through `included`, search or a push is caught too. Direct channels no token can delete are removed by `lib/purge-channels.php`. |

Every channel the suites create is deleted at the end, even when a check fails.

## Measuring

Not suites — they print numbers so a change can be measured before and after.

| Script | What it measures |
| --- | --- |
| `node tests/E2E/seed-channel.mjs [n]` | Creates a channel with B and C as members and `n` messages (some quoting), prints its id. `--delete <id>` removes it. |
| `php tests/E2E/query-count.php <channelId> [v]` | Run from the forum root. SQL queries, DB time and total time per hot request, in process; repeated SQL is listed, which is how an N+1 shows. `v` lists every query. |
| `node tests/E2E/bench.mjs [runs]` | Median and p90 HTTP time of the hot endpoints. |
| `node tests/E2E/bench-open.mjs <a> <b>` | In Edge: full page load to the first message, and in-app channel switches. |
| `node tests/E2E/bench-drawer.mjs` | In Edge, with a real mouse press: forum index → drawer list → channel, with each request's start and finish. `STACKS=1` adds the JS call site of every request. |
