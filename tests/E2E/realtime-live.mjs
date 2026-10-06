#!/usr/bin/env node
/**
 * The websocket carries the chat, and the poller stands down while it does.
 *
 * B opens a channel and sits idle. The socket must prove itself within a few
 * seconds (ping → pong), after which B sends no chat polls at all. A message
 * from the administrator must land in B's stream over the socket. Dropping the
 * socket must bring polling back at once; reconnecting must catch up and stand
 * the poller down again.
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php) and Edge.
 */
import { createChannel, deleteChannel, joinChannel, sendMessage } from "./lib/api.mjs";
import { harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const t = harness("realtime-live");
const { admin, b } = await loadTokens();
const stamp = Date.now().toString(36);

const created = await createChannel(admin.token, {
  name: "E2E realtime " + stamp,
  description: "created by tests/E2E/realtime-live.mjs",
  isPrivate: false,
});

await t.must("admin creates a public channel", created.status === 201, "HTTP " + created.status);

const channelId = Number(created.json.data.id);

await t.must("B joins", (await joinChannel(b.token, channelId)).status === 200);

const diag = (fn) => "flarum.extensions['ramon-chat']." + fn + "()";
const chatPolls = (since) =>
  browser.requests.filter(
    (r) => r.at >= since && /\/api\/chat-(messages|channels)(\?|$)/.test(r.url) && r.method === "GET",
  );

const browser = await launchBrowser({ label: "B" });

try {
  await browser.loginWithRememberToken(b.remember);
  await browser.goto("/chat/c/" + channelId);
  await t.must(
    "B opens the channel",
    Boolean(await browser.waitFor("document.querySelector('.ChatChannel-stream') !== null", 20000)),
  );

  const opened = Date.now();
  const live = await browser.waitFor(diag("realtimeLive"), 8000, 100);
  await t.must("the socket proves itself without any chat traffic", Boolean(live), (Date.now() - opened) + " ms");
  await t.check(
    "the proof is one ping request",
    browser.requests.filter((r) => r.url.endsWith("/chat/realtime/ping")).length === 1,
  );

  // ── Idle: nothing polled ────────────────────────────────────────────────────
  const idleFrom = Date.now();
  await sleep(35000);
  const idlePolls = chatPolls(idleFrom);
  await t.check(
    "an idle, live chat sends no polls for 35 s",
    idlePolls.length === 0,
    idlePolls.map((r) => r.url.replace(/^.*\/api/, "")).slice(0, 4).join(" | "),
  );

  // ── A message arrives over the socket ───────────────────────────────────────
  const text = "live-" + stamp;
  const sentAt = Date.now();
  const sent = await sendMessage(admin.token, channelId, text);
  await t.must("admin sends a message", sent.status === 201, "HTTP " + sent.status);

  const seen = await browser.waitFor(
    "(document.querySelector('.ChatChannel-stream')?.textContent ?? '').includes(" + JSON.stringify(text) + ")",
    5000,
    25,
  );
  await t.check(
    "B sees it without a reload",
    Boolean(seen),
    (Date.now() - sentAt) + " ms after the send returned; " +
      (await browser.evaluate("JSON.stringify({ state: app.websocket.connection.state, live: " + diag("realtimeLive") + ", hidden: document.hidden })")),
  );

  // ── Drop and reconnect ──────────────────────────────────────────────────────
  await browser.evaluate("app.websocket.disconnect(), true");
  await t.check("a dropped socket is not live", !(await browser.evaluate(diag("realtimeLive"))));

  const droppedAt = Date.now();
  await sleep(4500);
  await t.check("a dropped socket brings polling back within seconds", chatPolls(droppedAt).length > 0, chatPolls(droppedAt).length + " polls");

  const reconnectAt = Date.now();
  await browser.evaluate("app.websocket.connect(), true");
  await t.check("reconnecting goes live again", Boolean(await browser.waitFor(diag("realtimeLive"), 8000, 100)));
  await t.check(
    "reconnecting catches up at once",
    chatPolls(reconnectAt).some((r) => r.at - reconnectAt < 3000),
  );

  const errors = browser.consoleErrors.filter((e) => !/favicon|manifest/i.test(e));
  await t.check("no uncaught page exceptions", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
  try {
    await browser.screenshot("realtime-99");
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
