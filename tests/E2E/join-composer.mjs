#!/usr/bin/env node
/**
 * The browser half: what a member actually sees, driven in a headless Edge.
 *
 *   1. From Browse, join a channel and open it: the composer is there at once,
 *      with no "channel is closed" notice and no reload (the regression this
 *      guards against).
 *   2. Land on a channel one has not joined: the join bar appears, and the
 *      composer replaces it on click.
 *   3. While the channel is open, a message sent by someone else appears in
 *      the stream without reloading (realtime in the browser).
 *   4. An invitation to a private channel shows up on the notifications page
 *      with Accept and Decline; Accept lands in the channel with the composer.
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php) and Edge.
 */
import {
  createChannel,
  deleteChannel,
  inviteMembers,
  sendMessage,
} from "./lib/api.mjs";
import { harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const t = harness("join-composer");
const { admin, c } = await loadTokens();
const stamp = Date.now().toString(36);

const openName = "E2E browse " + stamp;
const directName = "E2E direct " + stamp;
const privateName = "E2E private " + stamp;

const created = {};

for (const [key, name, isPrivate] of [
  ["open", openName, false],
  ["direct", directName, false],
  ["private", privateName, true],
]) {
  const response = await createChannel(admin.token, {
    name,
    description: "created by tests/E2E/join-composer.mjs",
    isPrivate,
  });

  await t.must("admin creates " + key + " channel", response.status === 201, "HTTP " + response.status + " " + response.text.slice(0, 200));
  created[key] = Number(response.json.data.id);
}

const browser = await launchBrowser({ label: "C" });

const COMPOSER = 'document.querySelector(".ChatChannel .ChatComposer-input") !== null';
const FROZEN = 'document.querySelector(".ChatChannel-frozen")';

try {
  await browser.loginWithRememberToken(c.remember);

  // ── 1. Browse, join, open ───────────────────────────────────────────────────
  await browser.goto("/chat/browse");

  const card = await browser.waitFor(
    "[...document.querySelectorAll('.ChatBrowseCard')].some(c => c.textContent.includes(" + JSON.stringify(openName) + "))",
    20000,
  );
  await t.must("the browse page lists the new channel", Boolean(card));
  await browser.screenshot("01-browse");

  const findCard = "[...document.querySelectorAll('.ChatBrowseCard')].find(c => c.textContent.includes(" + JSON.stringify(openName) + "))";

  await browser.clickWhere(findCard + "?.querySelector('.ChatBrowseCard-action .Button--primary')");

  const opened = await browser.waitFor(
    "(() => { const c = " + findCard + "; return c && c.querySelector('.ChatBrowseCard-status--joined') && c.querySelector('.ChatBrowseCard-action .Button'); })() ? true : false",
    15000,
  );
  await t.check("the card reads Joined with an Open button after joining", Boolean(opened));

  await browser.clickWhere(findCard + "?.querySelector('.ChatBrowseCard-action .Button')");

  const composer = await browser.waitFor(COMPOSER, 20000);
  await t.check("opening the channel shows the composer without a reload", Boolean(composer));

  const frozen = await browser.evaluate(FROZEN + " ? " + FROZEN + ".textContent : null");
  await t.check("no closed or join notice is drawn", frozen === null, String(frozen));
  await browser.screenshot("02-joined-channel");

  // The channel has threads on (the forum default), so the header offers its
  // thread list; opening it scopes the threads section to this channel.
  const threadsButton = await browser.evaluate(
    "document.querySelector('.ChatChannel-headerActions .fa-code-branch') !== null",
  );
  await t.check("the header offers the channel's threads", threadsButton === true);

  await browser.clickWhere("document.querySelector('.ChatChannel-headerActions .fa-code-branch')?.closest('button')");

  const scopedList = await browser.waitFor(
    "location.search.includes('channel=" + created.open + "') && document.querySelector('.ChatThreadsList .ChatThreadPanel-header') !== null",
    10000,
  );
  await t.check("the threads section opens scoped to the channel", Boolean(scopedList), String(await browser.evaluate("location.pathname + location.search")));

  // The same list in the drawer covers the conversation, the way the pinned
  // list does, rather than taking a column beside it.
  await browser.goto("/");
  await browser.waitFor("document.querySelector('.ChatNavButton') !== null", 15000);
  await browser.click(".ChatNavButton");

  const drawerOpen = await browser.waitFor("document.querySelector('.ChatDrawer') !== null", 15000);
  await t.check("the header button opens the drawer", Boolean(drawerOpen));

  await browser.clickWhere(
    "[...document.querySelectorAll('.ChatDrawer .ChatChannelRow')].find(r => r.textContent.includes(" + JSON.stringify(openName) + "))",
  );
  await browser.waitFor("document.querySelector('.ChatDrawer .ChatComposer-input') !== null", 15000);

  await browser.click(".ChatDrawer .ChatDrawer-channelMenu .Dropdown-toggle");
  await browser.waitFor("document.querySelector('.ChatDrawer .Dropdown-menu .fa-code-branch') !== null", 5000);
  await browser.clickWhere("document.querySelector('.ChatDrawer .Dropdown-menu .fa-code-branch')?.closest('button')");

  const covers = await browser.waitFor(
    `(() => {
      const list = document.querySelector('.ChatDrawer .ChatThreadsList--embedded');
      const body = document.querySelector('.ChatDrawer-body');
      if (!list || !body) return false;
      const a = list.getBoundingClientRect(), b = body.getBoundingClientRect();
      return Math.abs(a.left - b.left) <= 2 && Math.abs(a.right - b.right) <= 2;
    })()`,
    10000,
  );
  await t.check("in the drawer the thread list covers the conversation edge to edge", Boolean(covers));
  await browser.screenshot("07-drawer-threads");

  // A full load this time, so the socket has to bind again before the next
  // step can expect a push; a moment for it, as the live suite allows.
  await browser.goto("/chat/c/" + created.open);
  await browser.waitFor(COMPOSER, 20000);
  await sleep(2500);

  // ── 3. Realtime in the browser ──────────────────────────────────────────────
  const text = "realtime probe " + stamp;
  const sent = await sendMessage(admin.token, created.open, text);
  await t.check("admin sends a message through the API", sent.status === 201, "HTTP " + sent.status + " " + sent.text.slice(0, 200));

  const arrived = await browser.waitFor(
    "document.querySelector('.ChatChannel-stream')?.textContent.includes(" + JSON.stringify(text) + ")",
    15000,
  );
  await t.check("the message appears in the open channel without a reload", Boolean(arrived));
  await browser.screenshot("03-realtime-message");

  // ── 2. Direct link to an unjoined channel ───────────────────────────────────
  await browser.goto("/chat/c/" + created.direct);

  const joinBar = await browser.waitFor(
    "document.querySelector('.ChatChannel-frozen--join .Button') !== null",
    20000,
  );
  await t.check("an unjoined channel offers Join in place of the composer", Boolean(joinBar));

  await browser.click(".ChatChannel-frozen--join .Button");

  const composerAfterJoin = await browser.waitFor(COMPOSER, 15000);
  await t.check("the composer replaces the join bar on click", Boolean(composerAfterJoin));
  await browser.screenshot("04-joined-from-link");

  // ── 4. Invitation on the notifications page ─────────────────────────────────
  const invited = await inviteMembers(admin.token, created.private, [c.id]);
  await t.check("admin invites C to the private channel", invited.status === 200, "HTTP " + invited.status);

  await browser.goto("/notifications");

  const row = await browser.waitFor(
    "[...document.querySelectorAll('.Notification--chatChannelInvite')].find(n => n.textContent.includes(" + JSON.stringify(privateName) + ")) ? true : false",
    20000,
  );
  await t.check("the invitation is listed with the channel name", Boolean(row));

  const buttons = await browser.evaluate(
    "(() => { const n = [...document.querySelectorAll('.Notification--chatChannelInvite')].find(n => n.textContent.includes(" + JSON.stringify(privateName) + ")); return n ? n.querySelectorAll('.ChatInviteActions .Button').length : 0; })()",
  );
  await t.check("the row carries Accept and Decline", buttons === 2, buttons + " buttons");
  await browser.screenshot("05-invite-notification");

  await browser.clickWhere(
    "[...document.querySelectorAll('.Notification--chatChannelInvite')].find(n => n.textContent.includes(" + JSON.stringify(privateName) + "))?.querySelector('.ChatInviteActions .Button--primary')",
  );

  // Where the channel opens depends on the member's own preference: the
  // full-screen page, or the drawer over the page they are on. Either way the
  // composer for the private channel is what must appear.
  const landed = await browser.waitFor(
    "(location.pathname.endsWith('/chat/c/" + created.private + "') || document.querySelector('.ChatDrawer') !== null) && " +
      COMPOSER +
      " && document.querySelector('.ChatComposer-input')?.placeholder.includes(" + JSON.stringify(privateName) + ")",
    20000,
  );
  await t.check("accepting opens the private channel with the composer", Boolean(landed), String(await browser.evaluate("location.pathname + ' drawer=' + (document.querySelector('.ChatDrawer') !== null)")));
  await browser.screenshot("06-accepted-private");

  const errors = browser.consoleErrors.filter((e) => !/favicon|manifest/i.test(e));
  await t.check("no uncaught page exceptions", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
  try {
    await browser.screenshot("99-failure");
  } catch {
    // Nothing more to capture.
  }
} finally {
  await browser.close();

  for (const [key, id] of Object.entries(created)) {
    const removed = await deleteChannel(admin.token, id);
    log("cleanup " + key + " channel " + id + " HTTP " + removed.status);
  }
}

await sleep(100);
process.exit(t.summary());
