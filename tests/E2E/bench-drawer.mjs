#!/usr/bin/env node
/**
 * Time from clicking the chat button on the forum index to the drawer's channel
 * list, and from clicking a channel in it to its messages, with a fresh browser
 * profile (nothing cached) and then after reloads (local snapshot available).
 * Prints each request's start and finish relative to the channel click.
 *
 *   node tests/E2E/bench-drawer.mjs
 */
import { loadTokens, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const { b } = await loadTokens();
const browser = await launchBrowser({ label: "B" });
const listed = "document.querySelectorAll('.ChatDrawer .ChatChannelRow').length > 0";
const drawn = "document.querySelectorAll('.ChatDrawer .ChatMessage[data-id]').length > 0";
const short = (url) => url.replace(/^https?:\/\/[^/]+/, "").slice(0, 60);

try {
  await browser.loginWithRememberToken(b.remember);

  for (const label of ["cold", "warm 1", "warm 2"]) {
    await browser.goto("/");
    await browser.waitFor("!!document.querySelector('.ChatNavButton')", 15000);
    await sleep(800);

    // A drawer restored open from the last visit would make the button close it.
    if (await browser.evaluate("!!document.querySelector('.ChatDrawer .ChatChannelRow, .ChatDrawer .ChatChannel')")) {
      console.log(label.padEnd(8) + "drawer was restored open");
    } else {
      const start = Date.now();
      await browser.click(".ChatNavButton");
      const ok = await browser.waitFor(listed, 15000, 5);
      console.log(label.padEnd(8) + "list " + (ok ? Date.now() - start + " ms" : "never"));
    }

    if (await browser.evaluate("!!document.querySelector('.ChatDrawer .ChatChannel')")) {
      await browser.evaluate("document.querySelector('.ChatDrawer .ChatDrawer-back, .ChatDrawer [class*=back]')?.click(), true");
      await browser.waitFor(listed, 5000, 5);
    }

    const pressed = Date.now();
    const click = (await browser.mouseClick(".ChatDrawer .ChatChannelRow")) ?? pressed;
    const shown = await browser.waitFor(drawn, 15000, 5);
    console.log("        channel " + (shown ? Date.now() - click + " ms" : "never"));

    for (const r of browser.requests.filter((r) => r.at >= pressed - 3000 && r.url.includes("/api/chat-"))) {
      console.log("          +" + (r.at - click) + " -> " + (r.done ? "+" + (r.done - click) : "?") + "  " + short(r.url) + (process.env.STACKS ? "\n             " + r.initiator : ""));
    }

    await browser.screenshot("drawer-" + label.replace(" ", ""));
    await sleep(2500);
  }
} finally {
  await browser.close();
}

process.exit(0);
