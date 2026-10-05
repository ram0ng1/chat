#!/usr/bin/env node
/**
 * How long the chat takes to show messages, in a headless Edge, as B.
 *
 *   node tests/E2E/bench-open.mjs <channelA> <channelB>
 *
 * Cold: a full page load of /chat/c/A, timed from navigation to the first
 * message row. Switch: clicking B in the sidebar, then A again, timed from the
 * click to B's rows being drawn. Prints medians over a few runs.
 */
import { loadTokens, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const [a, b2] = process.argv.slice(2).map(Number);
const { b } = await loadTokens();
const browser = await launchBrowser({ label: "B" });
const rows = (id) => "document.querySelectorAll('.ChatChannel-stream .ChatMessage[data-id]').length > 0 && location.pathname.includes('/c/" + id + "')";
const median = (xs) => xs.slice().sort((x, y) => x - y)[Math.floor(xs.length / 2)];

try {
  await browser.loginWithRememberToken(b.remember);
  await browser.goto("/chat/c/" + a);
  await browser.waitFor(rows(a), 20000);
  const cold = [];
  for (let i = 0; i < 5; i++) {
    const start = Date.now();
    await browser.evaluate("location.href = '/chat/c/" + a + "', true").catch(() => {});
    await sleep(50);
    await browser.waitFor(rows(a), 20000, 10);
    cold.push(Date.now() - start);
    await sleep(500);
  }
  console.log("cold open        median " + median(cold) + " ms  " + JSON.stringify(cold));

  const timings = { first: [], again: [] };
  for (let i = 0; i < 4; i++) {
    for (const [target, bucket] of [[b2, i === 0 ? "first" : "again"], [a, "again"]]) {
      const start = Date.now();
      await browser.evaluate("m.route.set('/chat/c/" + target + "'), true");
      await browser.waitFor(rows(target) + " && !document.querySelector('.ChatChannel-stream .Skeleton, .ChatChannel-stream .ChatSkeleton')", 20000, 5);
      timings[bucket].push(Date.now() - start);
      await sleep(300);
    }
  }
  console.log("switch (first)   " + JSON.stringify(timings.first));
  console.log("switch (cached)  median " + median(timings.again) + " ms  " + JSON.stringify(timings.again));
} finally {
  await browser.close();
}
process.exit(0);
