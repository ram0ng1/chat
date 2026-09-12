#!/usr/bin/env node
/**
 * The invite picker, driven in a headless Edge as the channel's owner.
 *
 *   - opens onto recently active people, not an empty box
 *   - typing searches; someone already in the channel is shown marked, not hidden
 *   - picking someone puts a chip in the field; Backspace takes it back
 *   - inviting closes the picker and the members tab lists the invitation at once
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php) and Edge.
 */
import {
  api,
  createChannel,
  deleteChannel,
  inviteMembers,
  leaveChannel,
} from "./lib/api.mjs";
import { harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const t = harness("invite-modal");
const { admin, a, b } = await loadTokens();
const stamp = Date.now().toString(36);

const created = await createChannel(admin.token, {
  name: "E2E picker " + stamp,
  description: "created by tests/E2E/invite-modal.mjs",
  isPrivate: true,
});

await t.must("admin creates a private channel", created.status === 201, "HTTP " + created.status);

const channelId = Number(created.json.data.id);

// A is already invited before the picker opens, so the picker has someone to mark.
const pre = await inviteMembers(admin.token, channelId, [a.id]);
await t.must("A is invited ahead of time", pre.status === 200, "HTTP " + pre.status);

// The suggestion query an ordinary member's picker falls back to. Core gates
// the `lastSeenAt` sort behind a permission members usually lack, so the
// picker must never depend on it; this is the order it uses for them.
const memberSuggestions = await api(b.token, "GET", "/users?sort=-commentCount&page[limit]=5");
await t.check(
  "an ordinary member can load picker suggestions",
  memberSuggestions.status === 200 && (memberSuggestions.json?.data ?? []).length > 0,
  "HTTP " + memberSuggestions.status,
);

const browser = await launchBrowser({ label: "owner" });

const type = async (text) =>
  browser.evaluate(
    "(() => { const el = document.querySelector('.ChatAddMembers-input'); if (!el) return false; el.value = " +
      JSON.stringify(text) +
      "; el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()",
  );

const key = async (name) =>
  browser.evaluate(
    "(() => { const el = document.querySelector('.ChatAddMembers-input'); if (!el) return false; el.dispatchEvent(new KeyboardEvent('keydown', { key: " +
      JSON.stringify(name) +
      ", bubbles: true })); return true; })()",
  );

try {
  await browser.loginWithRememberToken(admin.remember);
  await browser.goto("/chat/c/" + channelId);

  await t.must("the channel opens", Boolean(await browser.waitFor("document.querySelector('.ChatChannel-title') !== null", 20000)));

  await browser.click(".ChatChannel-title");
  await t.must("the channel details open", Boolean(await browser.waitFor("document.querySelector('.ChatChannelInfo-tab') !== null", 10000)));

  await browser.clickWhere("[...document.querySelectorAll('.ChatChannelInfo-tab')][1]");
  await t.must(
    "the members tab offers the invite button",
    Boolean(await browser.waitFor("document.querySelector('.ChatChannelInfo-memberHeader .Button--primary') !== null", 10000)),
  );

  const pendingBefore = await browser.waitFor("document.querySelectorAll('.ChatChannelInfo-member--invited').length", 10000);
  await t.check("the members tab already lists the pending invitation", pendingBefore === 1, String(pendingBefore));

  await browser.click(".ChatChannelInfo-memberHeader .Button--primary");
  await t.must("the picker opens", Boolean(await browser.waitFor("document.querySelector('.ChatAddMembers-input') !== null", 10000)));

  const suggested = await browser.waitFor("document.querySelectorAll('.ChatAddMembers-result').length", 15000);
  await t.check("the picker opens onto recently active people", suggested > 0, suggested + " rows");
  await browser.screenshot("picker-01-suggestions");

  await type("chat_e2e");

  // Suggestions may already include these names, so the wait is for the
  // search itself to have replaced them: the "recently active" heading goes.
  const found = await browser.waitFor(
    "document.querySelector('.ChatAddMembers-heading') === null && [...document.querySelectorAll('.ChatAddMembers-result')].filter(r => r.textContent.includes('chat_e2e')).length",
    15000,
  );
  await t.check("typing searches by name", found >= 2, found + " matching rows");

  const marked = await browser.evaluate(
    "[...document.querySelectorAll('.ChatAddMembers-result--taken')].map(r => r.textContent.replace(/\\s+/g, ' ').trim())",
  );
  await t.check(
    "the person already invited is shown marked, not hidden",
    Array.isArray(marked) && marked.some((text) => text.includes(a.username)),
    JSON.stringify(marked),
  );

  await browser.clickWhere(
    "[...document.querySelectorAll('.ChatAddMembers-result')].find(r => r.textContent.includes(" + JSON.stringify(b.username) + ") && !r.disabled)",
  );

  const chips = await browser.waitFor("document.querySelectorAll('.ChatAddMembers-chip').length", 5000);
  await t.check("picking someone puts a chip in the field", chips === 1, String(chips));

  const cleared = await browser.evaluate("document.querySelector('.ChatAddMembers-input').value");
  await t.check("the query is cleared after a pick", cleared === "", JSON.stringify(cleared));

  await key("Backspace");
  await sleep(200);
  const afterBackspace = await browser.evaluate("document.querySelectorAll('.ChatAddMembers-chip').length");
  await t.check("Backspace on an empty box takes the chip back", afterBackspace === 0, String(afterBackspace));

  await type("chat_e2e_b");
  await browser.waitFor(
    "document.querySelector('.ChatAddMembers-heading') === null && [...document.querySelectorAll('.ChatAddMembers-result')].some(r => r.textContent.includes(" + JSON.stringify(b.username) + "))",
    15000,
  );
  await key("Enter");

  const chipsAgain = await browser.waitFor("document.querySelectorAll('.ChatAddMembers-chip').length", 5000);
  await t.check("Enter picks the highlighted person", chipsAgain === 1, String(chipsAgain));

  const count = await browser.evaluate("document.querySelector('.ChatAddMembers-footer-count')?.textContent.trim()");
  await t.check("the footer counts the selection", Boolean(count), String(count));
  await browser.screenshot("picker-02-selected");

  await browser.click(".ChatAddMembers-footer .Button--primary");

  const closed = await browser.waitFor("document.querySelector('.ChatAddMembers-input') === null", 10000);
  await t.check("inviting closes the picker", Boolean(closed));

  const pendingAfter = await browser.waitFor(
    "document.querySelectorAll('.ChatChannelInfo-member--invited').length === 2 ? 2 : 0",
    10000,
  );
  await t.check("the members tab lists the new invitation at once", pendingAfter === 2, String(pendingAfter));
  await browser.screenshot("picker-03-members-tab");

  // ── Inspecting from Browse ──────────────────────────────────────────────────
  // The owner leaves, so the card offers a way back in; holding
  // `inspectChannels` (every administrator does), the quiet way in is offered
  // beside the plain one, and taking it marks the card as inspecting.
  const left = await leaveChannel(admin.token, channelId);
  await t.check("the owner leaves the channel", left.status === 204, "HTTP " + left.status);

  await browser.goto("/chat/browse");

  const cardName = "E2E picker " + stamp;
  const findCard = "[...document.querySelectorAll('.ChatBrowseCard')].find(c => c.textContent.includes(" + JSON.stringify(cardName) + "))";

  const inspectButton = await browser.waitFor(
    "(() => { const c = " + findCard + "; return c && c.querySelector('.ChatBrowseCard-iconButton .fa-user-secret') ? true : false; })()",
    20000,
  );
  await t.check("the browse card offers Inspect to a holder of the permission", Boolean(inspectButton));
  await browser.screenshot("picker-04-browse-inspect");

  await browser.clickWhere(findCard + "?.querySelector('.ChatBrowseCard-iconButton .fa-user-secret')?.closest('button')");

  const inspecting = await browser.waitFor(
    "(() => { const c = " + findCard + "; return c && c.querySelector('.ChatBrowseCard-status .fa-user-secret') ? true : false; })()",
    15000,
  );
  await t.check("inspecting marks the card and offers Open", Boolean(inspecting));

  const errors = browser.consoleErrors.filter((e) => !/favicon|manifest/i.test(e));
  await t.check("no uncaught page exceptions", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
  try {
    await browser.screenshot("picker-99-failure");
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
