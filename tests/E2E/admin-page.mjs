#!/usr/bin/env node
/**
 * The admin page, in a headless Edge as the administrator.
 *
 *   - every titled card is drawn, and the record panels (announcer, webhooks)
 *     line up with the settings cards and the save bar above them
 *   - the permission grid carries the two chat blocks with their explanatory
 *     second line, and the "inspect channels" row
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php) and Edge.
 */
import { api } from "./lib/api.mjs";
import { harness, loadTokens, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const t = harness("admin-page");
const { admin } = await loadTokens();

const browser = await launchBrowser({ label: "admin", width: 1600, height: 1000 });

try {
  await browser.loginWithRememberToken(admin.remember);
  await browser.goto("/admin#/extension/ramon-chat");

  const cards = await browser.waitFor(
    "document.querySelectorAll('.ChatAdmin-section').length >= 8 ? document.querySelectorAll('.ChatAdmin-section').length : 0",
    25000,
  );
  await t.check("the settings page draws its titled cards", cards >= 8, cards + " cards");

  const titles = await browser.evaluate(
    "[...document.querySelectorAll('.ChatAdmin-sectionTitle')].map(el => el.textContent.trim())",
  );
  await t.check("every card has a title", Array.isArray(titles) && titles.every((s) => s.length > 0), JSON.stringify(titles));

  // Alignment: the announcer and webhook panels start at the same left edge
  // as the settings grid and the save bar, and span the same width.
  const geometry = await browser.evaluate(`(() => {
    const rect = (el) => { const r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom) }; };
    const grid = rect(document.querySelector('.ChatAdmin-grid'));
    const controls = rect(document.querySelector('.ChatAdmin-controls'));
    const announcer = rect(document.querySelector('.ChatAdmin-section--announcer'));
    const webhooks = rect(document.querySelector('.ChatAdmin-section--webhooks'));
    const toggle = document.querySelector('.ChatAdmin-section--webhooks .ChatAdmin-switch .Checkbox') !== null;
    return { grid, controls, announcer, webhooks, toggle };
  })()`);

  const aligned =
    Math.abs(geometry.announcer.left - geometry.grid.left) <= 2 &&
    Math.abs(geometry.announcer.right - geometry.grid.right) <= 2 &&
    Math.abs(geometry.webhooks.left - geometry.grid.left) <= 2 &&
    Math.abs(geometry.controls.left - geometry.grid.left) <= 2;
  await t.check("the record panels line up with the settings cards and the save bar", aligned, JSON.stringify(geometry));

  const ordered = geometry.webhooks.bottom <= geometry.controls.top && geometry.announcer.bottom <= geometry.controls.top;
  await t.check("the announcer and webhook cards sit above the save bar", ordered, JSON.stringify({ webhooksBottom: geometry.webhooks.bottom, controlsTop: geometry.controls.top }));
  await t.check("the webhooks card carries the feature switch", geometry.toggle === true);

  await browser.screenshot("admin-01-settings");

  // The grid fetches the groups before it draws; the webhooks panel fetches
  // its records. Both show a spinner first.
  const gridReady = await browser.waitFor(
    "document.querySelectorAll('.PermissionGrid-section').length > 0 && document.querySelector('.ChatWebhooks .LoadingIndicator') === null",
    30000,
  );
  await t.check("the permission grid and the webhooks panel finish loading", Boolean(gridReady));

  const permissions = await browser.evaluate(`(() => {
    const rows = [...document.querySelectorAll('.PermissionGrid-section th')].map(th => th.textContent.trim());
    const headings = document.querySelectorAll('.ChatPermissionHeading').length;
    const helps = [...document.querySelectorAll('.ChatPermissionHeading-help')].map(el => el.textContent.trim()).filter(Boolean).length;
    const inspect = [...document.querySelectorAll('.PermissionGrid-child th')].some(th => /inspe/i.test(th.textContent));
    return { rows, headings, helps, inspect };
  })()`);

  await t.check("the chat permission blocks carry an explanatory line", permissions.headings >= 1 && permissions.helps === permissions.headings, JSON.stringify(permissions));
  await t.check("the inspect-channels permission row is listed", permissions.inspect === true);

  await browser.evaluate("document.querySelector('.ExtensionPage-permissions')?.scrollIntoView()");
  await sleep(300);
  await browser.screenshot("admin-02-permissions");

  const errors = browser.consoleErrors.filter((e) => !/favicon|manifest/i.test(e));
  await t.check("no uncaught page exceptions", errors.length === 0, errors.slice(0, 3).join(" | "));

  // The switch is enforced on the delivery route, not only drawn: with it off,
  // a webhook URL answers 403 before its key is even looked at. Restored
  // afterwards whatever happens in between.
  const setSwitch = (value) =>
    api(admin.token, "POST", "/settings", { "ramon-chat.webhooks_enabled": value });

  try {
    const off = await setSwitch("0");
    await t.check("the webhooks switch can be turned off through the API", off.status === 204, "HTTP " + off.status);

    const refused = await api(null, "POST", "/chat/hooks/not-a-real-key", { text: "probe" });
    await t.check("deliveries are refused while the switch is off", refused.status === 403, "HTTP " + refused.status);
  } finally {
    const on = await setSwitch("1");
    await t.check("the webhooks switch is restored", on.status === 204, "HTTP " + on.status);
  }
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
  try {
    await browser.screenshot("admin-99-failure");
  } catch {
    // Nothing more to capture.
  }
} finally {
  await browser.close();
}

await sleep(100);
process.exit(t.summary());
