#!/usr/bin/env node
/**
 * Entering and leaving, as the room sees it, live.
 *
 * Two browsers. B sits in the channel and never reloads. The administrator
 * acts from a second browser: inspects the channel from Browse, opens it,
 * leaves from the header. B must see nothing at all of that. Then the
 * administrator joins and leaves visibly through the API, and B must see the
 * arrival, the departure and the member count move without a reload.
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php) and Edge.
 */
import {
  api,
  createChannel,
  deleteChannel,
  joinChannel,
  leaveChannel,
} from "./lib/api.mjs";
import { harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const t = harness("membership-live");
const { admin, b } = await loadTokens();
const stamp = Date.now().toString(36);

const created = await createChannel(admin.token, {
  name: "E2E live " + stamp,
  description: "created by tests/E2E/membership-live.mjs",
  isPrivate: false,
});

await t.must("admin creates a public channel", created.status === 201, "HTTP " + created.status);

const channelId = Number(created.json.data.id);
const cardName = "E2E live " + stamp;

// The creator is a member; B joins, the creator steps out. From here the
// creator has no membership and B is the only person in the room.
await t.must("B joins", (await joinChannel(b.token, channelId)).status === 200);
await t.must("the creator leaves", (await leaveChannel(admin.token, channelId)).status === 204);

const systemRows = () =>
  "[...document.querySelectorAll('.ChatChannel-stream .ChatMessage--system, .ChatChannel-stream .ChatMessage-system')].length";

const streamText = () => "document.querySelector('.ChatChannel-stream')?.textContent ?? ''";

const watcher = await launchBrowser({ label: "B" });
const actor = await launchBrowser({ label: "admin" });

try {
  await watcher.loginWithRememberToken(b.remember);
  await watcher.goto("/chat/c/" + channelId);
  await t.must("B opens the channel", Boolean(await watcher.waitFor("document.querySelector('.ChatChannel-stream') !== null", 20000)));

  // Give the socket a moment to bind before anything happens.
  await sleep(1500);

  const textBefore = await watcher.evaluate(streamText());

  // ── Inspecting: nothing reaches B ──────────────────────────────────────────
  await actor.loginWithRememberToken(admin.remember);
  await actor.goto("/chat/browse");

  const findCard = "[...document.querySelectorAll('.ChatBrowseCard')].find(c => c.textContent.includes(" + JSON.stringify(cardName) + "))";

  await t.must(
    "the browse card offers Inspect",
    Boolean(await actor.waitFor("(() => { const c = " + findCard + "; return c && c.querySelector('.ChatBrowseCard-iconButton .fa-user-secret') ? true : false; })()", 20000)),
  );

  await actor.clickWhere(findCard + "?.querySelector('.ChatBrowseCard-iconButton .fa-user-secret')?.closest('button')");
  await t.must(
    "inspecting marks the card",
    Boolean(await actor.waitFor("(() => { const c = " + findCard + "; return c && c.querySelector('.ChatBrowseCard-status .fa-user-secret') ? true : false; })()", 15000)),
  );

  await actor.clickWhere(findCard + "?.querySelector('.ChatBrowseCard-action .Button')");
  await t.must("the inspector opens the channel", Boolean(await actor.waitFor("document.querySelector('.ChatChannel-headerActions') !== null", 20000)));

  // Leaving asks for confirmation; the dialog would block a headless page.
  await actor.evaluate("window.confirm = () => true");
  await actor.clickWhere("document.querySelector('.ChatChannel-headerActions .fa-arrow-right-from-bracket')?.closest('button')");

  const backOnIndex = await actor.waitFor("location.pathname.endsWith('/chat') || location.pathname.endsWith('/chat/')", 15000);
  await t.check("leaving takes the inspector out of the channel at once", Boolean(backOnIndex), String(await actor.evaluate("location.pathname")));

  const gone = await actor.waitFor(
    "![...document.querySelectorAll('.ChatChannelRow')].some(r => r.textContent.includes(" + JSON.stringify(cardName) + "))",
    5000,
  );
  await t.check("the channel leaves the inspector's sidebar at once", Boolean(gone));

  await sleep(2500);

  const textAfterInspect = await watcher.evaluate(streamText());
  await t.check("B saw nothing of the inspection or its end", textAfterInspect === textBefore, textAfterInspect === textBefore ? "" : textAfterInspect.slice(-200));

  const serverRows = (await api(admin.token, "GET", "/chat-messages?filter[channel]=" + channelId)).json.data
    .map((row) => row.attributes.systemKey)
    .filter(Boolean);
  // Newest first: the creator's departure, then B's arrival. Nothing from the
  // hidden visit in between or after.
  await t.check(
    "the server wrote no announcement for the hidden visit",
    serverRows.length === 2 && serverRows[0] === "user_left" && serverRows[1] === "user_joined",
    JSON.stringify(serverRows),
  );

  // ── Visible join and leave: B sees both, live ──────────────────────────────
  const countBefore = await watcher.evaluate("document.querySelector('.ChatChannel-title')?.textContent ?? ''");

  await t.must("admin joins visibly", (await joinChannel(admin.token, channelId)).status === 200);

  const sawJoin = await watcher.waitFor(
    "(document.querySelector('.ChatChannel-stream')?.textContent ?? '').includes(" + JSON.stringify(admin.username) + ")",
    10000,
  );
  await t.check("B sees the arrival announced without a reload", Boolean(sawJoin));

  await t.must("admin leaves visibly", (await leaveChannel(admin.token, channelId)).status === 204);

  const sawLeave = await watcher.waitFor(
    "(() => { const text = document.querySelector('.ChatChannel-stream')?.textContent ?? ''; return text.split(" + JSON.stringify(admin.username) + ").length >= 3; })()",
    10000,
  );
  await t.check("B sees the departure announced without a reload", Boolean(sawLeave));

  const count = await watcher.evaluate(
    "(() => { const s = app.store.getById('chat-channels', " + JSON.stringify(String(channelId)) + "); return s ? s.userCount() : null; })()",
  );
  await t.check("B's copy of the channel carries the settled member count", count === 1, String(count) + " (before: " + countBefore.trim() + ")");

  await watcher.screenshot("live-01-watcher");

  const errors = [...watcher.consoleErrors, ...actor.consoleErrors].filter((e) => !/favicon|manifest/i.test(e));
  await t.check("no uncaught page exceptions", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
  try {
    await watcher.screenshot("live-99-watcher");
    await actor.screenshot("live-99-actor");
  } catch {
    // Nothing more to capture.
  }
} finally {
  await watcher.close();
  await actor.close();

  const removed = await deleteChannel(admin.token, channelId);
  log("cleanup channel " + channelId + " HTTP " + removed.status);
}

await sleep(100);
process.exit(t.summary());
