#!/usr/bin/env node
/**
 * The drawer and the full-screen page grow out of whatever opened them, the way
 * the Avocado theme's composer does: header button → drawer, drawer → page, and
 * page → drawer on the way back. With reduced motion nothing animates.
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php) and Edge.
 */
import { createChannel, deleteChannel, joinChannel, sendMessage } from "./lib/api.mjs";
import { harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const t = harness("transitions");
const { admin, b } = await loadTokens();

const created = await createChannel(admin.token, {
  name: "E2E morph " + Date.now().toString(36),
  description: "created by tests/E2E/transitions.mjs",
  isPrivate: false,
});

await t.must("admin creates a public channel", created.status === 201, "HTTP " + created.status);

const channelId = Number(created.json.data.id);
const browser = await launchBrowser({ label: "B" });

/** Transform animations running on an element right now. */
const morphing = (selector) =>
  "(() => { const el = document.querySelector(" + JSON.stringify(selector) +
  "); return !!el && el.getAnimations().some(a => a.playState === 'running'); })()";

try {
  await t.must("B joins", (await joinChannel(b.token, channelId)).status === 200);
  await sendMessage(admin.token, channelId, "hello");

  await browser.loginWithRememberToken(b.remember);
  await browser.goto("/");
  await browser.evaluate("localStorage.clear(), true");
  await browser.goto("/");
  await browser.waitFor("!!document.querySelector('.ChatNavButton')", 15000);
  await sleep(500);

  await browser.mouseClick(".ChatNavButton", 60);
  await t.check("the drawer grows out of the header button", Boolean(await browser.waitFor(morphing(".ChatDrawer"), 400, 10)));
  await browser.screenshot("transitions-01-open");

  await browser.waitFor("!!document.querySelector('.ChatDrawer .ChatChannelRow')", 10000);
  await sleep(600);
  await browser.evaluate("document.querySelector('.ChatDrawer .ChatChannelRow').click(), true");
  await browser.waitFor("!!document.querySelector('.ChatDrawer .ChatMessage')", 10000);

  await browser.evaluate("document.querySelector('.ChatDrawer .fa-expand').closest('button').click(), true");
  await t.check("the full-screen page grows out of the drawer", Boolean(await browser.waitFor(morphing(".ChatPage"), 400, 10)));
  await browser.waitFor("!!document.querySelector('.ChatPage .ChatMessage')", 10000);
  await sleep(700);
  await t.check("the page settles without a transform", await browser.evaluate("getComputedStyle(document.querySelector('.ChatPage')).transform === 'none'"));
  await browser.screenshot("transitions-02-page");

  await browser.evaluate("m.route.set('/'), true");
  await t.check("leaving puts the drawer back, growing out of the page", Boolean(await browser.waitFor(morphing(".ChatDrawer"), 600, 10)));
  await sleep(700);

  // ── Reduced motion ──────────────────────────────────────────────────────────
  await browser.evaluate("document.querySelector('.ChatDrawer .fa-times').closest('button').click(), true");
  await browser.emulateReducedMotion(true);
  await sleep(300);
  await browser.mouseClick(".ChatNavButton", 60);
  await browser.waitFor("!!document.querySelector('.ChatDrawer')", 3000, 10);
  await t.check("with reduced motion the drawer just appears", !(await browser.evaluate(morphing(".ChatDrawer"))));

  const errors = browser.consoleErrors.filter((e) => !/favicon|manifest/i.test(e));
  await t.check("no uncaught page exceptions", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
  try {
    await browser.screenshot("transitions-99");
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
