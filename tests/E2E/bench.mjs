#!/usr/bin/env node
/**
 * Response times of the chat's hot endpoints, as a member sees them.
 *
 * Not a pass/fail suite: prints the median and p90 of each request over a few
 * runs so an optimisation can be measured before and after. Uses a throwaway
 * channel with a full page of history.
 *
 *   node tests/E2E/bench.mjs [runs]
 */
import { api, createChannel, deleteChannel, joinChannel, sendMessage } from "./lib/api.mjs";
import { loadTokens, BASE } from "./lib/env.mjs";

const runs = Number(process.argv[2] ?? 15);
const { admin, b } = await loadTokens();

const created = await createChannel(admin.token, { name: "E2E bench " + Date.now().toString(36), isPrivate: false });
const channelId = Number(created.json.data.id);

await joinChannel(b.token, channelId);

for (let i = 0; i < 60; i += 10) {
  await Promise.all(Array.from({ length: 10 }, (_, k) => sendMessage(admin.token, channelId, "bench " + (i + k))));
}

const timed = async (fn) => {
  const start = performance.now();
  const result = await fn();
  return [performance.now() - start, result];
};

const cases = {
  "GET channel list (sidebar)": () =>
    api(b.token, "GET", "/chat-channels?filter[following]=true&sort=-lastMessageAt&page[limit]=50"),
  "GET channel": () => api(b.token, "GET", "/chat-channels/" + channelId),
  "GET newest 50 messages": () =>
    api(b.token, "GET", "/chat-messages?filter[channel]=" + channelId + "&sort=-id&page[limit]=50"),
  "GET poll (nothing new)": () =>
    api(b.token, "GET", "/chat-messages?filter[channel]=" + channelId + "&filter[greaterThan]=999999999&sort=id&page[limit]=50"),
  "POST message (member)": () => sendMessage(b.token, channelId, "bench send " + Math.random()),
  "POST read marker": () => api(b.token, "POST", "/chat-channels/" + channelId + "/read"),
  "GET /chat/c/{id} page": async () => {
    const r = await fetch(BASE + "/chat/c/" + channelId, { headers: { Cookie: "flarum_remember=" + b.remember } });
    await r.text();
    return { status: r.status };
  },
};

const pct = (sorted, p) => sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * p))];

try {
  for (const [name, fn] of Object.entries(cases)) {
    await fn();

    const times = [];
    let status = 0;

    for (let i = 0; i < runs; i++) {
      const [ms, result] = await timed(fn);
      times.push(ms);
      status = result.status;
      if (name.startsWith("POST message")) await new Promise((r) => setTimeout(r, 550));
    }

    times.sort((a, b) => a - b);
    console.log(
      name.padEnd(30) + " HTTP " + status + "  median " + pct(times, 0.5).toFixed(0).padStart(5) +
        " ms   p90 " + pct(times, 0.9).toFixed(0).padStart(5) + " ms",
    );
  }
} finally {
  await deleteChannel(admin.token, channelId);
}
