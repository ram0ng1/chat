#!/usr/bin/env node
/**
 * Promotion notices, handing a channel over, and succession when its owner
 * leaves, against the live forum.
 *
 * B creates a channel (owner); A and C join. B promotes A: A is notified, with
 * ids only. B offers the channel to C: the code is mailed (the suite sets a
 * known code through lib/transfer-helper.php, since no HTTP route reveals it),
 * a wrong code is refused, the right one turns the offer into C's
 * notification, and C accepts: C owns it, B becomes a moderator, and B's open
 * members tab moves both badges without a reload. Then C leaves: A, the
 * oldest moderator, inherits it and is told so, live. Finally A offers it to
 * B, who declines (A is told), and A offers and cancels.
 *
 * Requires tests/E2E/.tokens.json, flarum/realtime, Edge, and a forum where
 * members run their channels. Skips otherwise.
 */
import { spawn } from "node:child_process";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

import {
  api,
  createChannel,
  deleteChannel,
  forum,
  joinChannel,
  leaveChannel,
  notifications,
  showChannel,
} from "./lib/api.mjs";
import { harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { subscribeUser } from "./lib/realtime.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const here = dirname(fileURLToPath(import.meta.url));
const PHP =
  process.env.CHAT_E2E_PHP ||
  "D:/laragon/bin/php/php-8.5.10-nts-Win32-vs17-x64/php.exe";

const t = harness("ownership-transfer");
const { admin, a, b, c } = await loadTokens();

function helper(...args) {
  return new Promise((resolve) => {
    const child = spawn(PHP, [join(here, "lib", "transfer-helper.php"), ...args.map(String)], {
      cwd: join(here, "..", "..", "..", ".."),
    });
    let out = "";

    child.stdout.on("data", (d) => (out += d));
    child.stderr.on("data", (d) => (out += d));
    child.on("error", (e) => resolve({ code: 1, out: "php failed: " + e.message }));
    child.on("exit", (code) => resolve({ code, out: out.trim() }));
  });
}

const transfer = (token, id, step = "", attributes = {}) =>
  api(token, "POST", "/chat-channels/" + id + "/transfer" + step, { data: { attributes } });

const attributesOf = (response) => response.json?.data?.attributes ?? {};

const notificationsOf = async (token, type, channelId) =>
  ((await notifications(token)).json?.data ?? []).filter(
    (row) => row.attributes?.contentType === type && Number(row.attributes?.content?.channelId) === Number(channelId),
  );

const reset = await helper("reset", a.id, b.id, c.id);
log("throttle reset: " + reset.out);

const created = await createChannel(b.token, {
  name: "E2E handover " + Date.now().toString(36),
  description: "created by tests/E2E/ownership-transfer.mjs",
  isPrivate: false,
});

if (created.status !== 201) {
  log("members cannot create channels here (HTTP " + created.status + "); skipping");
  process.exit(t.summary());
}

const channelId = Number(created.json.data.id);
const sockets = {};
let browser = null;

try {
  if (!attributesOf(created).canTransferOwnership) {
    log("channels are not run by their members here; skipping");
    throw new Error("skip");
  }

  await t.must("A joins", (await joinChannel(a.token, channelId)).status === 200);
  await t.must("C joins", (await joinChannel(c.token, channelId)).status === 200);

  const attrs = (await forum(admin.token)).json.data.attributes;
  const websocket = {
    key: attrs["websocket.key"],
    host: attrs["websocket.host"],
    port: attrs["websocket.port"],
    secure: attrs["websocket.secure"] === "1" || attrs["websocket.secure"] === true,
  };

  sockets.a = await subscribeUser({ websocket, token: a.token, userId: a.id, label: "A" });
  sockets.b = await subscribeUser({ websocket, token: b.token, userId: b.id, label: "B" });
  sockets.c = await subscribeUser({ websocket, token: c.token, userId: c.id, label: "C" });

  // ── Promotion notice ─────────────────────────────────────────────────────────
  const promoted = await api(b.token, "POST", "/chat-channels/" + channelId + "/moderators", {
    data: { attributes: { userId: a.id } },
  });
  await t.must("B makes A a moderator", promoted.status === 200, "HTTP " + promoted.status);

  const promotedPush = await sockets.a.waitFor("notification", (d) => JSON.stringify(d).includes("chatModeratorPromoted"), 8000);
  await t.check("A is told live", Boolean(promotedPush));

  const promotedRows = await notificationsOf(a.token, "chatModeratorPromoted", channelId);
  await t.check("A has the promotion in the bell", promotedRows.length === 1, promotedRows.length + " rows");
  await t.check(
    "its data is ids only",
    JSON.stringify(Object.keys(promotedRows[0]?.attributes?.content ?? {}).sort()) === JSON.stringify(["channelId", "userId"]),
    JSON.stringify(promotedRows[0]?.attributes?.content),
  );
  await t.check("C was not told", (await notificationsOf(c.token, "chatModeratorPromoted", channelId)).length === 0);

  await sleep(1100);

  // ── B offers the channel to C ────────────────────────────────────────────────
  const started = await transfer(b.token, channelId, "", { userId: c.id });

  if (started.status === 422) {
    log("C may not own channels here (" + started.text.slice(0, 160) + "); skipping");
    throw new Error("skip");
  }

  await t.must("B starts the transfer", started.status === 200, "HTTP " + started.status + " " + started.text.slice(0, 200));
  await t.check(
    "it waits for B's code",
    attributesOf(started).ownershipTransfer?.toUserId === c.id && attributesOf(started).ownershipTransfer?.confirmed === false,
    JSON.stringify(attributesOf(started).ownershipTransfer),
  );
  await t.check("no code in the response", !/\b\d{6}\b/.test(JSON.stringify(attributesOf(started).ownershipTransfer)));
  await t.check(
    "C cannot see it before the code",
    attributesOf(await showChannel(c.token, channelId, "participants")).ownershipTransfer == null,
  );

  const wrong = await transfer(b.token, channelId, "/confirm", { code: "000000" });
  await t.check("a wrong code is refused with a reason", wrong.status === 422 && /attempt|tentativa/i.test(wrong.text), "HTTP " + wrong.status);

  const set = await helper("code", channelId, "246810");
  await t.must("the suite sets a known code", set.code === 0, set.out);

  const confirmed = await transfer(b.token, channelId, "/confirm", { code: "246810" });
  await t.must("the right code is accepted", confirmed.status === 200, "HTTP " + confirmed.status + " " + confirmed.text.slice(0, 200));
  await t.check("the offer is now confirmed", attributesOf(confirmed).ownershipTransfer?.confirmed === true);

  const offerPush = await sockets.c.waitFor("notification", (d) => JSON.stringify(d).includes("chatOwnershipTransfer"), 8000);
  await t.check("C is asked live", Boolean(offerPush));
  await t.check(
    "C sees the offer on the channel",
    attributesOf(await showChannel(c.token, channelId, "participants")).ownershipTransfer?.incoming === true,
  );

  // ── B watches the members tab while C accepts ────────────────────────────────
  browser = await launchBrowser({ label: "B" });
  await browser.loginWithRememberToken(b.remember);
  await browser.goto("/chat/c/" + channelId);
  await t.must("B opens the channel", Boolean(await browser.waitFor("document.querySelector('.ChatChannel-title') !== null", 20000)));
  await browser.click(".ChatChannel-title");
  await t.must("the details open", Boolean(await browser.waitFor("document.querySelector('.ChatChannelInfo-tab') !== null", 10000)));
  await browser.clickWhere("[...document.querySelectorAll('.ChatChannelInfo-tab')][1]");

  const row = (user) =>
    "[...document.querySelectorAll('.ChatChannelInfo-member')].find((r) => r.textContent.includes(" + JSON.stringify(user.username) + "))";
  const owns = (user) => "!!(" + row(user) + ")?.querySelector('[data-rank=owner]')";
  const moderates = (user) => "!!(" + row(user) + ")?.querySelector('[data-rank=moderator]')";

  await t.check("B's tab shows the pending offer", Boolean(await browser.waitFor("!!document.querySelector('.ChatChannelInfo-transfer')", 10000)));
  await t.check("B is drawn as owner", Boolean(await browser.waitFor(owns(b), 5000)));
  await t.must(
    "B's page is on the websocket",
    Boolean(await browser.waitFor("flarum.extensions['ramon-chat'].realtimeLive() === true", 15000)),
  );

  let at = Date.now();
  const accepted = await transfer(c.token, channelId, "/accept");
  await t.must("C accepts", accepted.status === 200, "HTTP " + accepted.status + " " + accepted.text.slice(0, 200));
  await t.check("C owns it", attributesOf(accepted).creatorId === c.id && attributesOf(accepted).canTransferOwnership === true);
  await t.check("B is now a moderator", (attributesOf(accepted).moderatorIds ?? []).includes(b.id));

  const ownerPush = await sockets.b.waitFor("ramonChat.membership", (d) => d.channelId === channelId && d.action === "owner_changed", 8000);
  await t.check("B hears the change over the websocket", Boolean(ownerPush));
  await t.check(
    "B's tab moves the owner badge to C live",
    Boolean(await browser.waitFor(owns(c) + " && !" + owns(b), 8000, 50)),
    Date.now() - at +
      " ms; store creatorId " +
      (await browser.evaluate("String(app.store.getById('chat-channels', '" + channelId + "')?.creatorId())")) +
      "; live " +
      (await browser.evaluate("String(flarum.extensions['ramon-chat'].realtimeLive())")),
  );
  await t.check("and draws B as a moderator", Boolean(await browser.waitFor(moderates(b), 5000)));
  await t.check("the offer is gone from B's tab", Boolean(await browser.waitFor("!document.querySelector('.ChatChannelInfo-transfer')", 5000)));
  await t.check("B may no longer hand it over", attributesOf(await showChannel(b.token, channelId)).canTransferOwnership === false);

  // ── C leaves: the oldest moderator, A, inherits ──────────────────────────────
  at = Date.now();
  await t.must("C leaves", (await leaveChannel(c.token, channelId)).status === 204);

  const inherited = attributesOf(await showChannel(a.token, channelId));
  await t.check("A, moderator longest, owns it", inherited.creatorId === a.id && inherited.canTransferOwnership === true, "creator " + inherited.creatorId);

  const inheritPush = await sockets.a.waitFor("notification", (d) => JSON.stringify(d).includes("chatOwnershipInherited"), 8000);
  await t.check("A is told live", Boolean(inheritPush));
  await t.check("A has it in the bell", (await notificationsOf(a.token, "chatOwnershipInherited", channelId)).length === 1);
  await t.check(
    "B's tab moves the owner badge to A live",
    Boolean(await browser.waitFor(owns(a), 8000, 50)),
    Date.now() - at + " ms",
  );

  // ── A offers it to B, who declines ───────────────────────────────────────────
  await t.must("A starts a transfer to B", (await transfer(a.token, channelId, "", { userId: b.id })).status === 200);
  await t.must("the suite sets a known code", (await helper("code", channelId, "135790")).code === 0);
  await t.must("A confirms", (await transfer(a.token, channelId, "/confirm", { code: "135790" })).status === 200);

  const declined = await transfer(b.token, channelId, "/decline");
  await t.must("B declines", declined.status === 200, "HTTP " + declined.status);
  await t.check("A still owns it", attributesOf(declined).creatorId === a.id);

  const declinedPush = await sockets.a.waitFor("notification", (d) => JSON.stringify(d).includes("chatOwnershipTransferDeclined"), 8000);
  await t.check("A is told of the refusal live", Boolean(declinedPush));
  await t.check(
    "B's offer leaves the bell",
    (await notificationsOf(b.token, "chatOwnershipTransfer", channelId)).length === 0,
  );

  // ── A offers and takes it back ───────────────────────────────────────────────
  await t.must("A starts again", (await transfer(a.token, channelId, "", { userId: b.id })).status === 200);
  const cancelled = await transfer(a.token, channelId, "/cancel");
  await t.check("A cancels", cancelled.status === 200 && attributesOf(cancelled).ownershipTransfer == null, "HTTP " + cancelled.status);

  const errors = browser.consoleErrors.filter((e) => !/favicon|manifest/i.test(e));
  await t.check("no uncaught page exceptions", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (e) {
  if (String(e?.message) !== "skip") {
    await t.fail("unexpected error", String(e?.stack ?? e));

    try {
      await browser?.screenshot("ownership-transfer-99");
    } catch {
      // Nothing more to capture.
    }
  }
} finally {
  await browser?.close();

  for (const socket of Object.values(sockets)) socket.close();

  const removed = await deleteChannel(admin.token, channelId);
  log("cleanup channel " + channelId + " HTTP " + removed.status);
}

await sleep(100);
process.exit(t.summary());
