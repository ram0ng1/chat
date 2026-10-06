#!/usr/bin/env node
/**
 * The state changes that used to wait for a reload, reaching B live.
 *
 * Two browsers. B sits in channel X, with channel Y's stream loaded too, and
 * never reloads. The administrator, who owns both, acts through the API and
 * from their own screen:
 *
 *   - starts a direct conversation with B: it appears in B's list
 *   - renames a thread in X: B's record of it takes the new title
 *   - sets and clears X's picture: B's record follows both
 *   - moves two messages from X to Y: they leave B's X and arrive in B's Y
 *   - makes B a moderator of X from the members tab: the row flips at once
 *     on the administrator's screen, B gains the room's controls without a
 *     reload, and undoing it reverses both
 *   - B reports a message: the administrator's moderation badge moves, and
 *     moves back when the report is closed
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php) and Edge.
 */
import { api, createChannel, deleteChannel, joinChannel, sendMessage } from "./lib/api.mjs";
import { API, harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const t = harness("live-state");
const { admin, b } = await loadTokens();
const stamp = Date.now().toString(36);

const PNG = {
  bytes: Buffer.from(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==",
    "base64",
  ),
  type: "image/png",
  name: "pixel.png",
};

async function upload(token, path, field, file) {
  const form = new FormData();
  form.append(field, new Blob([file.bytes], { type: file.type }), file.name);

  const response = await fetch(API + path, {
    method: "POST",
    headers: { Accept: "application/json", Authorization: "Token " + token },
    body: form,
  });

  return { status: response.status, text: await response.text() };
}

const channels = [];
const make = async (name) => {
  const created = await createChannel(admin.token, {
    name,
    description: "created by tests/E2E/live-state.mjs",
    isPrivate: false,
    threadingEnabled: true,
  });
  await t.must("admin creates " + name, created.status === 201, "HTTP " + created.status);
  const id = Number(created.json.data.id);
  channels.push(id);
  await t.must("B joins " + name, (await joinChannel(b.token, id)).status === 200);
  return id;
};

const X = await make("E2E state X " + stamp);
const Y = await make("E2E state Y " + stamp);

const sent = [];
for (const text of ["moves first", "moves second", "gets reported", "opens a thread"]) {
  const r = await sendMessage(admin.token, X, text + " " + stamp);
  await t.must("admin posts \"" + text + "\"", r.status === 201, "HTTP " + r.status);
  sent.push(Number(r.json.data.id));
  await sleep(150);
}

const state = "flarum.extensions['ramon-chat'].chatState";
const record = (type, id) => "app.store.getById('" + type + "', '" + id + "')";
const streamIds = (channel) =>
  "((" + state + ".streams[" + channel + "]?.messages ?? []).map((m) => Number(m.id())))";

const watcher = await launchBrowser({ label: "B" });
const owner = await launchBrowser({ label: "admin" });
let direct = null;
let flagId = null;

try {
  await watcher.loginWithRememberToken(b.remember);
  await watcher.goto("/chat/c/" + X);
  await t.must("B opens X", Boolean(await watcher.waitFor("document.querySelector('.ChatChannel textarea') !== null", 20000)));
  await watcher.evaluate("(" + state + ".loadChannel(" + Y + "), true)");
  await t.must(
    "B has X's messages and Y's stream loaded",
    Boolean(await watcher.waitFor(streamIds(X) + ".includes(" + sent[0] + ") && !!" + state + ".streams[" + Y + "]?.loadedInitial", 10000)),
  );
  await watcher.waitFor("flarum.extensions['ramon-chat'].realtimeLive()", 8000, 100);
  await sleep(500);

  // ── A direct conversation appears ───────────────────────────────────────────
  let started = await api(admin.token, "POST", "/chat/direct", { data: { attributes: { userIds: [b.id] } } });

  // One left over from an earlier run is reopened rather than created, and
  // only a creation is announced. Remove it and start afresh.
  if (started.status === 200) {
    await deleteChannel(admin.token, started.json.data.id);
    started = await api(admin.token, "POST", "/chat/direct", { data: { attributes: { userIds: [b.id] } } });
  }

  await t.must("admin starts a direct conversation with B", started.status === 201, "HTTP " + started.status);
  direct = Number(started.json.data.id);

  let at = Date.now();
  await t.check(
    "the conversation appears in B's list live",
    Boolean(await watcher.waitFor(state + ".channels.some((c) => Number(c.id()) === " + direct + ")", 6000, 25)),
    Date.now() - at + " ms",
  );

  // ── A thread is renamed ─────────────────────────────────────────────────────
  const reply = await api(admin.token, "POST", "/chat-messages", {
    data: { type: "chat-messages", attributes: { content: "thread reply " + stamp, channelId: X, replyToId: sent[3], createThread: true } },
  });
  await t.must("admin opens a thread", reply.status === 201, "HTTP " + reply.status);
  const threadId = Number(reply.json.data.attributes.threadId);
  await t.must("B hears of the thread", Boolean(await watcher.waitFor("!!" + record("chat-threads", threadId), 5000, 25)));

  at = Date.now();
  const renamed = await api(admin.token, "PATCH", "/chat-threads/" + threadId, {
    data: { type: "chat-threads", id: String(threadId), attributes: { title: "Renamed " + stamp } },
  });
  await t.must("admin renames the thread", renamed.status === 200, "HTTP " + renamed.status);
  await t.check(
    "B's thread takes the new title live",
    Boolean(await watcher.waitFor(record("chat-threads", threadId) + "?.attribute('title') === 'Renamed " + stamp + "'", 5000, 25)),
    Date.now() - at + " ms",
  );

  // ── The channel picture ─────────────────────────────────────────────────────
  at = Date.now();
  const pictured = await upload(admin.token, "/chat/channels/" + X + "/image", "image", PNG);
  await t.must("admin sets X's picture", pictured.status === 200, "HTTP " + pictured.status);
  await t.check(
    "B's record of X shows the picture live",
    Boolean(await watcher.waitFor("!!" + record("chat-channels", X) + "?.imageUrl()", 5000, 25)),
    Date.now() - at + " ms",
  );

  at = Date.now();
  const cleared = await api(admin.token, "DELETE", "/chat/channels/" + X + "/image");
  await t.must("admin clears it", cleared.status === 200, "HTTP " + cleared.status);
  await t.check(
    "and B's record drops it live",
    Boolean(await watcher.waitFor("!" + record("chat-channels", X) + "?.imageUrl()", 5000, 25)),
    Date.now() - at + " ms",
  );

  // ── Messages move ───────────────────────────────────────────────────────────
  at = Date.now();
  const moved = await api(admin.token, "POST", "/chat/messages/move", {
    data: { attributes: { messageIds: [sent[0], sent[1]], channelId: Y } },
  });
  await t.must("admin moves two messages from X to Y", moved.status === 200, "HTTP " + moved.status);
  await t.check(
    "they leave B's X live",
    Boolean(await watcher.waitFor("!" + streamIds(X) + ".includes(" + sent[0] + ") && !" + streamIds(X) + ".includes(" + sent[1] + ")", 5000, 25)),
    Date.now() - at + " ms",
  );
  await t.check(
    "and arrive in B's Y",
    Boolean(await watcher.waitFor(streamIds(Y) + ".includes(" + sent[0] + ") && " + streamIds(Y) + ".includes(" + sent[1] + ")", 5000, 25)),
    Date.now() - at + " ms",
  );
  await t.check("the rest of X stays", Boolean(await watcher.evaluate(streamIds(X) + ".includes(" + sent[2] + ")")));

  // ── B is made a moderator of X, from the administrator's members tab ───────
  await owner.loginWithRememberToken(admin.remember);
  await owner.goto("/chat/c/" + X);
  await t.must("the administrator opens X", Boolean(await owner.waitFor("document.querySelector('.ChatChannel-title') !== null", 20000)));
  await owner.click(".ChatChannel-title");
  await t.must("the details open", Boolean(await owner.waitFor("document.querySelector('.ChatChannelInfo-tab') !== null", 10000)));
  await owner.clickWhere("[...document.querySelectorAll('.ChatChannelInfo-tab')][1]");

  const row =
    "[...document.querySelectorAll('.ChatChannelInfo-member')].find((r) => r.textContent.includes(" +
    JSON.stringify(b.username) +
    "))";
  const roleButton = "(" + row + ")?.querySelector('.ChatChannelInfo-member-role')";
  const isModRow =
    "(() => { const r = " + row + "; return !!r && !!r.querySelector('[data-rank=moderator]') && !!r.querySelector('.ChatChannelInfo-member-role .fa-user-slash'); })()";
  const isPlainRow =
    "(() => { const r = " + row + "; return !!r && !r.querySelector('[data-rank=moderator]') && !!r.querySelector('.ChatChannelInfo-member-role .fa-user-shield'); })()";
  const successAlerts = "document.querySelectorAll('.AlertManager .Alert--success').length";

  await t.must("B's row offers Make moderator", Boolean(await owner.waitFor(isPlainRow, 10000)));
  await t.check("B cannot close X yet", !(await watcher.evaluate(record("chat-channels", X) + ".canClose()")));

  const alertsBefore = await owner.evaluate(successAlerts);
  at = Date.now();
  await owner.clickWhere(roleButton);
  await t.check(
    "the row flips to moderator at once, with the badge and Remove moderator",
    Boolean(await owner.waitFor(isModRow, 5000, 25)),
    Date.now() - at + " ms",
  );
  await t.check("one success alert", (await owner.evaluate(successAlerts)) - alertsBefore === 1);
  await owner.screenshot("live-state-00");
  await t.check(
    "B gains the room's controls live",
    Boolean(await watcher.waitFor(record("chat-channels", X) + ".canClose() === true", 6000, 25)),
    Date.now() - at + " ms",
  );

  at = Date.now();
  await owner.clickWhere(roleButton);
  await t.check("demoting flips the row back at once", Boolean(await owner.waitFor(isPlainRow, 5000, 25)), Date.now() - at + " ms");
  await t.check(
    "and B loses the controls live",
    Boolean(await watcher.waitFor(record("chat-channels", X) + ".canClose() === false", 6000, 25)),
    Date.now() - at + " ms",
  );

  await owner.screenshot("live-state-01");
  await owner.evaluate("(() => { app.modal.close(); return true; })()");

  // ── The moderation badge ────────────────────────────────────────────────────
  const badge = "Number(app.forum.attribute('chatOpenFlagsCount') ?? 0)";
  const before = Number(await owner.evaluate(badge));

  at = Date.now();
  const flagged = await api(b.token, "POST", "/chat-message-flags", {
    data: { type: "chat-message-flags", attributes: { messageId: sent[2], reason: "spam" } },
  });
  await t.must("B reports a message", flagged.status === 201, "HTTP " + flagged.status);
  flagId = flagged.json.data.id;
  await t.check(
    "the administrator's moderation badge rises live",
    Boolean(await owner.waitFor(badge + " === " + (before + 1), 6000, 25)),
    Date.now() - at + " ms",
  );

  at = Date.now();
  const resolved = await api(admin.token, "POST", "/chat-message-flags/" + flagId + "/resolve", { data: { attributes: {} } });
  await t.must("the administrator closes the report", resolved.status === 200, "HTTP " + resolved.status);
  flagId = null;
  await t.check(
    "and the badge falls back live",
    Boolean(await owner.waitFor(badge + " === " + before, 6000, 25)),
    Date.now() - at + " ms",
  );

  for (const [label, page] of [["B", watcher], ["the administrator", owner]]) {
    const errors = page.consoleErrors.filter((e) => !/favicon|manifest/i.test(e));
    await t.check("no uncaught page exceptions for " + label, errors.length === 0, errors.slice(0, 3).join(" | "));
  }
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
  try {
    await watcher.screenshot("live-state-99");
  } catch {
    // Nothing more to capture.
  }
} finally {
  await watcher.close();
  await owner.close();

  if (flagId) await api(admin.token, "POST", "/chat-message-flags/" + flagId + "/resolve", { data: { attributes: {} } });

  for (const id of [...channels, ...(direct ? [direct] : [])]) {
    const removed = await deleteChannel(admin.token, id);
    log("cleanup channel " + id + " HTTP " + removed.status);
  }
}

await sleep(100);
process.exit(t.summary());
