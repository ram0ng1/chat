#!/usr/bin/env node
/**
 * A pinned message far above the loaded window is still reachable.
 *
 * The administrator pins the first message of a channel and buries it under
 * more than three pages of newer ones, the last of which quotes it. B opens the
 * channel: the first page holds only the newest fifty, so the pin is not on
 * screen. Clicking the pinned strip must load the history down to it and centre
 * it; so must clicking the quote, from a fresh page.
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php) and Edge.
 */
import { api, createChannel, deleteChannel, joinChannel, sendMessage } from "./lib/api.mjs";
import { harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const t = harness("pinned-jump");
const { admin, b } = await loadTokens();
const stamp = Date.now().toString(36);
const BURY = 160;

const created = await createChannel(admin.token, {
  name: "E2E pinned " + stamp,
  description: "created by tests/E2E/pinned-jump.mjs",
  isPrivate: false,
});

await t.must("admin creates a public channel", created.status === 201, "HTTP " + created.status);

const channelId = Number(created.json.data.id);
const browser = await launchBrowser({ label: "B" });

const inStream = (id) =>
  "!!document.querySelector('.ChatChannel-stream .ChatMessage[data-id=\"" + id + "\"]')";
const visible = (id) =>
  "(() => { const n = document.querySelector('.ChatChannel-stream .ChatMessage[data-id=\"" + id +
  "\"]'); const s = document.querySelector('.ChatChannel-stream'); if (!n || !s) return false;" +
  " const a = n.getBoundingClientRect(), b = s.getBoundingClientRect(); return a.bottom > b.top && a.top < b.bottom; })()";

try {
  await t.must("B joins", (await joinChannel(b.token, channelId)).status === 200);

  const first = await sendMessage(admin.token, channelId, "the pinned one " + stamp);
  await t.must("admin posts the message to pin", first.status === 201, "HTTP " + first.status);

  const pinnedId = Number(first.json.data.id);
  const pin = await api(admin.token, "POST", "/chat-messages/" + pinnedId + "/pin");
  await t.must("admin pins it", pin.status === 200, "HTTP " + pin.status);

  for (let i = 0; i < BURY; i += 10) {
    await Promise.all(
      Array.from({ length: Math.min(10, BURY - i) }, (_, k) =>
        sendMessage(admin.token, channelId, "filler " + (i + k + 1)),
      ),
    );
  }

  const quote = await api(admin.token, "POST", "/chat-messages", {
    data: {
      type: "chat-messages",
      attributes: { content: "quoting the pin", channelId, replyToId: pinnedId },
    },
  });
  await t.must("admin quotes the pin at the bottom", quote.status === 201, "HTTP " + quote.status);

  // ── The pinned strip ────────────────────────────────────────────────────────
  await browser.loginWithRememberToken(b.remember);
  await browser.goto("/chat/c/" + channelId);
  await t.must(
    "B sees the pinned strip",
    Boolean(await browser.waitFor("document.querySelector('.ChatChannel-pinnedBar-jump') !== null", 20000)),
  );
  await t.check("the pin is not in the first page", !(await browser.evaluate(inStream(pinnedId))));
  await t.check(
    "the strip is clickable",
    !(await browser.evaluate("document.querySelector('.ChatChannel-pinnedBar-jump').disabled")),
  );

  let clickedAt = Date.now();
  await browser.click(".ChatChannel-pinnedBar-jump");
  await t.check(
    "clicking the strip brings the pin into view",
    Boolean(await browser.waitFor(visible(pinnedId), 10000, 50)),
    (Date.now() - clickedAt) + " ms",
  );
  await t.check(
    "it is highlighted",
    await browser.evaluate("!!document.querySelector('.ChatMessage--flash[data-id=\"" + pinnedId + "\"]')"),
  );
  await browser.screenshot("pinned-01-jumped");

  // ── The quote, from a fresh page ────────────────────────────────────────────
  await browser.goto("/chat/c/" + channelId);
  await browser.waitFor("document.querySelector('.ChatMessage-replyTo') !== null", 20000);
  await t.check("after a reload the pin is out of the window again", !(await browser.evaluate(inStream(pinnedId))));

  clickedAt = Date.now();
  await browser.click(".ChatMessage-replyTo");
  await t.check(
    "clicking the quote brings the pin into view",
    Boolean(await browser.waitFor(visible(pinnedId), 10000, 50)),
    (Date.now() - clickedAt) + " ms",
  );
  await t.check(
    "no 'not loaded' alert was shown",
    !(await browser.evaluate("!!document.querySelector('.Alert--error')")),
  );

  const errors = browser.consoleErrors.filter((e) => !/favicon|manifest/i.test(e));
  await t.check("no uncaught page exceptions", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
  try {
    await browser.screenshot("pinned-99");
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
