#!/usr/bin/env node
/**
 * Closing and reopening a channel reaches everyone in it live.
 *
 * B sits in a channel with the composer open and never reloads. The
 * administrator closes it through the API: B's composer must give way to the
 * "closed" notice at once, without a single error alert, and typing must not
 * raise one either. Reopening must bring B's composer back.
 *
 * Then archiving and its undoing, the second half driven from the
 * administrator's own channel details the way a person would: the archive
 * refuses a status change, Unarchive brings it back closed (B's composer stays
 * away), Reopen brings B's composer back, Close and Archive are offered again
 * and archive it a second time. This is the sequence that used to strand a
 * channel without its Archive action.
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
const owner = await launchBrowser({ label: "admin" });
const archives = new Set();

const record = "app.store.getById('chat-channels', '" + channelId + "')";

// The channel details, opened from the header, and one of its state buttons
// found by its icon.
const openDetails = async () => {
  await owner.evaluate("(() => { app.modal.close(); return true; })()");
  await sleep(400);
  await owner.click(".ChatChannel-title");
  return Boolean(await owner.waitFor("document.querySelector('.ChatChannelInfo-tab') !== null", 10000));
};
const stateButton = (icon) =>
  "[...document.querySelectorAll('.Modal .Button')].find((b) => b.querySelector('." + icon + "'))";
const offered = async (icon) => Boolean(await owner.evaluate("!!(" + stateButton(icon) + ")"));

try {
  await t.must("B joins", (await joinChannel(b.token, channelId)).status === 200);

  await browser.loginWithRememberToken(b.remember);
  await browser.goto("/chat/c/" + channelId);
  await t.must("B has the composer", Boolean(await browser.waitFor(composer, 20000)));
  // Proven live before the first push, or the close can land before B's
  // socket has subscribed and the check measures the poller instead.
  await browser.waitFor("flarum.extensions['ramon-chat'].realtimeLive()", 8000, 100);
  await sleep(500);

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

  // ── Archived ────────────────────────────────────────────────────────────────
  // Archiving needs a closed channel; the close lands live as above, then the
  // archive must turn the notice into the archived one without a reload.
  await t.must("admin closes it again", (await setStatus("closed")).status === 200);
  await browser.waitFor("!(" + composer + ")", 5000, 25);

  at = Date.now();
  const archived = await api(admin.token, "POST", "/chat-channels/" + channelId + "/archive", {
    data: { type: "chat-channels", id: String(channelId), attributes: { title: "E2E archive " + channelId } },
  });
  await t.must("admin archives the channel", archived.status === 200, "HTTP " + archived.status);
  const firstArchive = Number(archived.json?.data?.attributes?.archivedDiscussionId ?? 0) || null;
  if (firstArchive) archives.add(firstArchive);

  await t.check(
    "B sees the archived state live",
    Boolean(
      await browser.waitFor(
        "(() => { const c = app.store.getById('chat-channels', '" + channelId + "'); return c && c.isArchived() && !!c.archivedDiscussionId(); })()",
        5000,
        25,
      ),
    ),
    Date.now() - at + " ms",
  );
  await t.check("B still has no composer", !(await browser.evaluate(composer)));
  await t.check("without an error alert after archiving", (await browser.evaluate(errorAlerts)) === 0);
  await t.check(
    "B's archived notice links to the transcript",
    Boolean(await browser.waitFor("!!document.querySelector('.ChatChannel-frozen--archived a[href*=\"/d/" + firstArchive + "\"]')", 5000)),
  );
  await t.check(
    "and offers B no way out of the archive",
    !(await browser.evaluate("!!document.querySelector('.ChatChannel-frozen--archived .Button')")),
  );

  // ── The status endpoint refuses an archive ──────────────────────────────────
  for (const status of ["open", "closed"]) {
    const refused = await setStatus(status);
    await t.check("setting an archived channel " + status + " is refused with 422", refused.status === 422, "HTTP " + refused.status);
  }

  // ── Unarchived from the administrator's own screen ──────────────────────────
  await owner.loginWithRememberToken(admin.remember);
  await owner.goto("/chat/c/" + channelId);
  await t.must("the administrator has the channel open", Boolean(await owner.waitFor("document.querySelector('.ChatChannel-title') !== null", 20000)));
  await t.check(
    "the administrator's archived notice offers Unarchive",
    Boolean(await owner.waitFor("!!document.querySelector('.ChatChannel-frozen--archived .Button')", 5000)),
  );

  await t.must("the channel details open", await openDetails());
  await t.check("the details offer Unarchive", await offered("fa-box-open"));
  await t.check("and neither Close nor Reopen nor Archive", !(await offered("fa-lock")) && !(await offered("fa-lock-open")) && !(await offered("fa-box-archive")));

  at = Date.now();
  await owner.clickWhere(stateButton("fa-box-open"));
  await t.check(
    "the administrator's own record is closed and unarchived at once",
    Boolean(await owner.waitFor("(() => { const c = " + record + "; return c && c.isClosed() && !c.archivedDiscussionId() && c.canArchive() && !c.canUnarchive(); })()", 5000, 25)),
  );
  await t.check(
    "B sees the channel leave the archive live, closed",
    Boolean(await browser.waitFor("(() => { const c = " + record + "; return c && c.isClosed() && !c.archivedDiscussionId(); })()", 5000, 25)),
    Date.now() - at + " ms",
  );
  await t.check("B's composer stays away while it is closed", !(await browser.evaluate(composer)) && Boolean(await browser.evaluate(frozen)));
  await t.check("B's notice is the closed one now", !(await browser.evaluate("!!document.querySelector('.ChatChannel-frozen--archived')")));

  // ── Reopen, close, and archive again, all from the details ──────────────────
  await t.must("the details open again", await openDetails());
  await t.check("a closed channel offers Reopen and Archive", (await offered("fa-lock-open")) && (await offered("fa-box-archive")));

  at = Date.now();
  await owner.clickWhere(stateButton("fa-lock-open"));
  await t.check("B's composer comes back after the reopen", Boolean(await browser.waitFor(composer, 5000, 25)), Date.now() - at + " ms");

  await t.must("the details open after reopening", await openDetails());
  await t.check("an open channel offers Close and not Archive", (await offered("fa-lock")) && !(await offered("fa-box-archive")));
  await owner.clickWhere(stateButton("fa-lock"));
  await browser.waitFor("!(" + composer + ")", 5000, 25);
  await t.check("B's composer goes again on the close", !(await browser.evaluate(composer)));

  await t.must("the details open after closing", await openDetails());
  await t.check("the Archive action is offered again", await offered("fa-box-archive"));

  at = Date.now();
  await owner.clickWhere(stateButton("fa-box-archive"));
  await t.check(
    "archiving a second time works, and B sees it live",
    Boolean(await browser.waitFor("(() => { const c = " + record + "; return c && c.isArchived() && !!c.archivedDiscussionId(); })()", 15000, 25)),
    Date.now() - at + " ms",
  );
  const again = await api(admin.token, "GET", "/chat-channels/" + channelId);
  const secondArchive = Number(again.json?.data?.attributes?.archivedDiscussionId ?? 0) || null;
  if (secondArchive) archives.add(secondArchive);
  await t.check("into a new transcript", Boolean(secondArchive) && secondArchive !== firstArchive);
  await t.check("no error alert on the administrator's screen", (await owner.evaluate(errorAlerts)) === 0);

  await browser.screenshot("channel-status-02");

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
  await owner.close();

  for (const id of archives) {
    const gone = await api(admin.token, "DELETE", "/discussions/" + id);
    log("cleanup archive discussion " + id + " HTTP " + gone.status);
  }

  const removed = await deleteChannel(admin.token, channelId);
  log("cleanup channel " + channelId + " HTTP " + removed.status);
}

await sleep(100);
process.exit(t.summary());
