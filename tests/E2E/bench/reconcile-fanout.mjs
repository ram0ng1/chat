#!/usr/bin/env node
/**
 * What one pushed message costs the server, and whether the row it draws tells
 * the truth about what its reader may do with it.
 *
 * Three readers (A and B, ordinary members, and the chat moderator) sit in one
 * channel while the administrator sends ten messages: five in a burst, five a
 * second and a half apart. For each reader it counts the GET /api/chat-messages
 * requests the pushes caused and their server time, then compares every
 * capability flag the pushed rows carry against what the API says for that
 * reader — the flags must match exactly, whichever way they were obtained.
 *
 *     node tests/E2E/bench/reconcile-fanout.mjs
 */
import { api, createChannel, deleteChannel, joinChannel, sendMessage } from "../lib/api.mjs";
import { harness, loadTokens, log, sleep } from "../lib/env.mjs";
import { launchBrowser } from "../lib/browser.mjs";

const t = harness("reconcile-fanout");
const tokens = await loadTokens();
const { admin } = tokens;
const readers = ["a", "b", "mod"];
const stamp = Date.now().toString(36);

const FLAGS = [
  "canEdit", "canDelete", "canReact", "canReply", "canCreateThread", "canMove",
  "canPin", "canForceDelete", "canFlag", "isBookmarked", "isFlagged",
];

const created = await createChannel(admin.token, {
  name: "E2E fanout " + stamp,
  description: "created by tests/E2E/bench/reconcile-fanout.mjs",
  isPrivate: false,
  threadingEnabled: true,
});

await t.must("admin creates a public channel", created.status === 201, "HTTP " + created.status);

const channelId = Number(created.json.data.id);

for (const who of readers) {
  await t.must(who + " joins", (await joinChannel(tokens[who].token, channelId)).status === 200);
}

const browsers = {};

try {
  for (const who of readers) {
    const browser = await launchBrowser({ label: who });

    browsers[who] = browser;
    await browser.loginWithRememberToken(tokens[who].remember);
    await browser.goto("/chat/c/" + channelId);
    await t.must(
      who + " opens the channel",
      Boolean(await browser.waitFor("document.querySelector('.ChatChannel-stream') !== null", 20000)),
    );
    await t.must(
      who + " is live on the socket",
      Boolean(await browser.waitFor("flarum.extensions['ramon-chat'].realtimeLive()", 10000, 100)),
    );
  }

  await sleep(1500);

  const from = Date.now();
  const ids = [];

  for (let i = 0; i < 5; i++) {
    const sent = await sendMessage(admin.token, channelId, "burst " + i + " " + stamp);

    ids.push(Number(sent.json?.data?.id));
  }

  for (let i = 0; i < 5; i++) {
    await sleep(1500);
    const sent = await sendMessage(admin.token, channelId, "spaced " + i + " " + stamp);

    ids.push(Number(sent.json?.data?.id));
  }

  await sleep(2500);

  const rows = [];

  for (const who of readers) {
    const browser = browsers[who];
    const reads = browser.requests.filter(
      (r) => r.at >= from && r.method === "GET" && /\/api\/chat-messages(\?|$)/.test(r.url),
    );
    const serverMs = reads.reduce((sum, r) => sum + ((r.done ?? r.at) - r.at), 0);

    rows.push({ who, requests: reads.length, perMessage: (reads.length / ids.length).toFixed(2), totalMs: serverMs });

    const local = await browser.evaluate(
      "(() => { const out = {}; for (const id of " + JSON.stringify(ids) + ") { const m = app.store.getById('chat-messages', String(id)); out[id] = m ? Object.fromEntries(" +
        JSON.stringify(FLAGS) + ".map((f) => [f, Boolean(m.attribute(f))])) : null; } return out; })()",
    );

    const truth = await api(
      tokens[who].token,
      "GET",
      "/chat-messages?filter[channel]=" + channelId + "&filter[greaterThan]=" + (Math.min(...ids) - 1) + "&sort=id&page[limit]=50",
    );

    const byId = Object.fromEntries((truth.json?.data ?? []).map((row) => [row.id, row.attributes]));
    const mismatches = [];

    for (const id of ids) {
      if (!local[id]) {
        mismatches.push(id + ": not on the page");
        continue;
      }

      for (const flag of FLAGS) {
        if (Boolean(byId[id]?.[flag]) !== local[id][flag]) {
          mismatches.push(id + "." + flag + " page=" + local[id][flag] + " api=" + Boolean(byId[id]?.[flag]));
        }
      }
    }

    await t.check(who + ": every pushed row carries the API's flags", mismatches.length === 0, mismatches.slice(0, 6).join(" | "));
  }

  console.table(rows);

  for (const who of readers) {
    const errors = browsers[who].consoleErrors.filter((e) => !/favicon|manifest/i.test(e));

    await t.check(who + ": no uncaught page exceptions", errors.length === 0, errors.slice(0, 3).join(" | "));
  }
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
} finally {
  for (const browser of Object.values(browsers)) await browser.close();

  const removed = await deleteChannel(admin.token, channelId);
  log("cleanup channel " + channelId + " HTTP " + removed.status);
}

await sleep(100);
process.exit(t.summary());
