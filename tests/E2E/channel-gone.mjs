#!/usr/bin/env node
/**
 * A deleted channel leaves every member's screen live.
 *
 * B sits on the channel list, then inside a channel; the administrator deletes
 * each from the API. The row must vanish without a click, and whoever was
 * inside must be stepped out to the chat with a notice rather than left on a
 * page whose every request now answers 404.
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php) and Edge.
 */
import { createChannel, deleteChannel, joinChannel } from "./lib/api.mjs";
import { harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const t = harness("channel-gone");
const { admin, b } = await loadTokens();
const stamp = Date.now().toString(36);
const created = [];

const make = async (name) => {
  const response = await createChannel(admin.token, { name, isPrivate: false });
  const id = Number(response.json?.data?.id);

  created.push(id);
  await joinChannel(b.token, id);

  return id;
};

const listed = (name) =>
  "[...document.querySelectorAll('.ChatChannelRow')].some(r => r.textContent.includes(" + JSON.stringify(name) + "))";

const browser = await launchBrowser({ label: "B" });

try {
  const listName = "E2E gone list " + stamp;
  const openName = "E2E gone open " + stamp;
  const listId = await make(listName);
  const openId = await make(openName);

  await browser.loginWithRememberToken(b.remember);
  await browser.goto("/chat");
  await t.must("B sees both channels listed", Boolean(await browser.waitFor(listed(listName) + " && " + listed(openName), 15000)));
  await sleep(1000);

  // ── Deleted while only listed ───────────────────────────────────────────────
  let at = Date.now();
  await t.must("admin deletes the listed channel", (await deleteChannel(admin.token, listId)).status === 204);
  await t.check(
    "its row leaves B's list without a click",
    Boolean(await browser.waitFor("!" + listed(listName), 5000, 25)),
    Date.now() - at + " ms",
  );

  await t.check(
    "a channel that was only listed leaves without a notice",
    (await browser.evaluate("document.querySelectorAll('.AlertManager .Alert').length")) === 0,
  );

  // ── Deleted while open ──────────────────────────────────────────────────────
  await browser.goto("/chat/c/" + openId);
  await browser.waitFor("!!document.querySelector('.ChatChannel-stream')", 15000);
  await sleep(1000);

  at = Date.now();
  await t.must("admin deletes the open channel", (await deleteChannel(admin.token, openId)).status === 204);
  await t.check(
    "B is stepped out to the chat",
    Boolean(await browser.waitFor("location.pathname === '/chat'", 5000, 25)),
    Date.now() - at + " ms",
  );

  const alerts = await browser.evaluate(
    "[...document.querySelectorAll('.AlertManager .Alert')].map(a => a.className + ' ' + a.textContent.trim())",
  );
  await t.check("with a notice, not an error", alerts.some((a) => /Alert--warning/.test(a)) && !alerts.some((a) => /Alert--error/.test(a)), alerts.join(" | "));
  await t.check("and the row is gone", !(await browser.evaluate(listed(openName))));

  // ── After a reload the snapshot does not bring them back ────────────────────
  await browser.goto("/chat");
  await sleep(1500);
  await t.check(
    "neither comes back after a reload",
    !(await browser.evaluate(listed(listName))) && !(await browser.evaluate(listed(openName))),
  );

  const errors = browser.consoleErrors.filter((e) => !/favicon|manifest/i.test(e));
  await t.check("no uncaught page exceptions", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
  try {
    await browser.screenshot("channel-gone-99");
  } catch {
    // Nothing more to capture.
  }
} finally {
  await browser.close();

  for (const id of created) {
    const removed = await deleteChannel(admin.token, id);
    log("cleanup channel " + id + " HTTP " + removed.status);
  }
}

await sleep(100);
process.exit(t.summary());
