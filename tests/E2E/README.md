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
# members) if absent and writes tests/E2E/.tokens.json, which is git-ignored.
php workbench/chat/tests/E2E/mint-tokens.php
```

The forum URL defaults to `https://alegatest.alega.com.br`; set `CHAT_E2E_BASE`
to point elsewhere. The websocket settings are read from the forum document.

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
|---|---|
| `invite-flow.mjs` | Inviting creates an invitation, not a membership. The invitee gets the notification and the websocket push, cannot read the private channel, and can decline (owner and inviter notified live) or accept (membership, capability flags, sidebar push, the "joined, invited by" line narrated live). Withdrawing removes the notification. The public `join` endpoint answers with the channel record. |
| `join-composer.mjs` | In a headless Edge: join from Browse and open the channel with the composer drawn at once; land on an unjoined channel and join from the bar; see another user's message arrive live; answer an invitation from the notifications page and land in the private channel. |
| `invite-modal.mjs` | In a headless Edge, as a channel owner: the invite picker opens onto recently active people, searches by name, marks people already in or invited, keeps chips in the field with keyboard support, and the members tab lists a new invitation at once. Then, from Browse, the Inspect button for a holder of `inspectChannels`. |
| `membership-live.mjs` | Two headless Edges. B sits in a channel and never reloads; the administrator inspects it from Browse, opens it and leaves from the header, and B sees nothing of it (no announcement written either). Then a visible join and leave through the API: B sees both announced live and its member count settles. |
| `admin-page.mjs` | In a headless Edge, as the administrator: the settings page draws its titled cards aligned with the save bar, the webhooks card carries its switch, the permission grid explains its two chat blocks and lists the inspect row, and the webhook route refuses deliveries while the switch is off. |

Every channel the suites create is deleted at the end, even when a check fails.
