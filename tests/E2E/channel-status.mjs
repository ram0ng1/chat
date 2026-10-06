#!/usr/bin/env node
/**
 * Closing and reopening a channel reaches everyone in it live.
 *
 * B sits in a channel with the composer open and never reloads. The
 * administrator closes it through the API: B's composer must give way to the
 * "closed" notice at once, without a single error alert, and typing must not
 * raise one either. Reopening must bring B's composer back.
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php) and Edge.
 */
import { api, createChannel, deleteChannel, joinChannel } from "./lib/api.mjs";
import { harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const t = harness("channel-status");
const { admin, b } = await loadTokens();

const created = await createChannel(admin.token, {
  name: "E2E status " + Date.now().toString(36),
  description: "created by tests/E2E/channel-status.mjs",
  isPrivate: false,
});

await t.must("admin creates a public channel", created.status === 201, "HTTP " + created.status);

const channelId = Number(created.json.data.id);
const setStatus = (status) =>
  api(admin.token, "POST", "/chat-channels/" + channelId + "/status", {
    data: { type: "chat-channels", id: String(channelId), attributes: { status } },
  });

const composer = "!!document.querySelector('.ChatChannel textarea')";
const frozen = "!!document.querySelector('.ChatChannel .ChatChannel-frozen')";
const errorAlerts = "document.querySelectorAll('.AlertManager .Alert--error').length";

const browser = await launchBrowser({ label: "B" });

try {
  await t.must("B joins", (await joinChannel(b.token, channelId)).status === 200);

  await browser.loginWithRememberToken(b.remember);
  await browser.goto("/chat/c/" + channelId);
  await t.must("B has the composer", Boolean(await browser.waitFor(composer, 20000)));
  await sleep(1000);

  // ── Closed ──────────────────────────────────────────────────────────────────
  let at = Date.now();
  await t.must("admin closes the channel", (await setStatus("closed")).status === 200);
  await t.check(
    "B's composer gives way to the closed notice live",
    Boolean(await browser.waitFor("!(" + composer + ") && " + frozen, 5000, 25)),
    Date.now() - at + " ms",
  );
  await t.check("without an error alert", (await browser.evaluate(errorAlerts)) === 0);

  // A typing signal sent by a stale composer is refused by the server; it must
  // stay silent rather than raise an alert.
  await browser.evaluate(
    "(() => { const s = flarum.extensions['ramon-chat'].chatState; s.typingSentAt = 0; s.announceTyping(" + channelId + "); return true; })()",
  );
  await sleep(500);
  await t.check("a refused typing signal raises no alert", (await browser.evaluate(errorAlerts)) === 0);

  // ── Reopened ────────────────────────────────────────────────────────────────
  at = Date.now();
  await t.must("admin reopens the channel", (await setStatus("open")).status === 200);
  await t.check(
    "B's composer comes back live",
    Boolean(await browser.waitFor(composer, 5000, 25)),
    Date.now() - at + " ms",
  );

  await browser.screenshot("channel-status-01");

  const errors = browser.consoleErrors.filter((e) => !/favicon|manifest/i.test(e));
  await t.check("no uncaught page exceptions", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
  try {
    await browser.screenshot("channel-status-99");
  } catch {
    // Nothing more to capture.
  }
} finally {
  await browser.close();

  const removed = await deleteChannel(admin.token, channelId);
  log("cleanup channel " + channelId + " HTTP " + removed.status);
}

await sleep(100);
process.exit(t.summary());
