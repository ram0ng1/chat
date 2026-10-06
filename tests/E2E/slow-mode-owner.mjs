#!/usr/bin/env node
/**
 * Slow mode holds back the room, not the people running it.
 *
 * B creates a channel (owner) and turns on a 30-second slow mode; C joins.
 * In the browser B sends twice in a row: the composer must stay open after the
 * first send and the second must be accepted. C, an ordinary member, is held to
 * the window by the server.
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php) and Edge.
 * Skips when the forum does not let members create channels.
 */
import { api, createChannel, deleteChannel, joinChannel, sendMessage } from "./lib/api.mjs";
import { harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const t = harness("slow-mode-owner");
const { admin, b, c } = await loadTokens();

const created = await createChannel(b.token, {
  name: "E2E slow " + Date.now().toString(36),
  description: "created by tests/E2E/slow-mode-owner.mjs",
  isPrivate: false,
});

if (created.status !== 201) {
  log("members cannot create channels here (HTTP " + created.status + "); skipping");
  process.exit(t.summary());
}

const channelId = Number(created.json.data.id);
const browser = await launchBrowser({ label: "B" });

const sendFromComposer = (text) =>
  browser.evaluate(
    "(() => { const ta = document.querySelector('.ChatChannel textarea'); if (!ta) return false;" +
      " ta.focus(); ta.value = " + JSON.stringify(text) + "; ta.dispatchEvent(new Event('input', { bubbles: true }));" +
      " ta.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })); return true; })()",
  );
const inStream = (text) =>
  "(document.querySelector('.ChatChannel-stream')?.textContent ?? '').includes(" + JSON.stringify(text) + ")";

try {
  const set = await api(b.token, "PATCH", "/chat-channels/" + channelId, {
    data: { type: "chat-channels", id: String(channelId), attributes: { slowModeSeconds: 30 } },
  });
  await t.must("the owner turns on a 30 s slow mode", set.status === 200, "HTTP " + set.status);
  await t.check("the channel tells the owner it does not apply to them", set.json?.data?.attributes?.bypassesSlowMode === true);

  await t.must("C joins", (await joinChannel(c.token, channelId)).status === 200);

  await browser.loginWithRememberToken(b.remember);
  await browser.goto("/chat/c/" + channelId);
  await t.must("B has the composer", Boolean(await browser.waitFor("!!document.querySelector('.ChatChannel textarea')", 20000)));
  await sleep(800);

  await sendFromComposer("owner first");
  await t.check("the first message lands", Boolean(await browser.waitFor(inStream("owner first"), 5000, 25)));
  await sleep(300);
  await t.check(
    "the owner's composer stays open after sending",
    await browser.evaluate("!!document.querySelector('.ChatChannel textarea') && !document.querySelector('.ChatChannel-frozen--slow')"),
  );

  await sendFromComposer("owner second");
  await t.check("the second message lands at once", Boolean(await browser.waitFor(inStream("owner second"), 5000, 25)));
  await t.check("no error alert", (await browser.evaluate("document.querySelectorAll('.AlertManager .Alert--error').length")) === 0);

  // ── An ordinary member is still held to the window ───────────────────────────
  const c1 = await sendMessage(c.token, channelId, "member first");
  const c2 = await sendMessage(c.token, channelId, "member second");
  await t.check("a member's first message is accepted", c1.status === 201, "HTTP " + c1.status);
  await t.check("a member's second message within the window is refused", c2.status === 422, "HTTP " + c2.status);

  const errors = browser.consoleErrors.filter((e) => !/favicon|manifest/i.test(e));
  await t.check("no uncaught page exceptions", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
  try {
    await browser.screenshot("slow-mode-owner-99");
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
