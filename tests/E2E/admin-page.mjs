#!/usr/bin/env node
/**
 * The admin page, in a headless Edge as the administrator.
 *
 *   - the tab bar is drawn, every tab holds the titled cards its count says,
 *     and the search box finds a card in another tab
 *   - the save bar stays in view, and the record panels (announcer, webhooks)
 *     line up with the tab bar, the settings cards and the save bar
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

  // The page is a bar of tabs over titled cards (the Avocado layout). Every
  // tab is opened in turn and its cards collected, so "all the cards" still
  // means all of them, not just the first tab's.
  const tabs = await browser.waitFor(
    "document.querySelectorAll('.ChatAdmin-tab').length >= 4 && document.querySelectorAll('.ChatAdmin-section').length > 0 ? [...document.querySelectorAll('.ChatAdmin-tab')].map(b => ({ group: b.dataset.group, count: Number(b.querySelector('.ChatAdmin-tabCount')?.textContent ?? 0) })) : null",
    25000,
  );
  await t.check("the settings page draws its tab bar", Array.isArray(tabs) && tabs.length === 4, JSON.stringify(tabs));

  const titles = [];

  for (const tab of tabs ?? []) {
    await browser.click(".ChatAdmin-tab[data-group=" + tab.group + "]");
    const drawn = await browser.waitFor(
      "document.querySelector('.ChatAdmin-tab.is-active')?.dataset.group === " + JSON.stringify(tab.group) +
        " ? [...document.querySelectorAll('.ChatAdmin-sectionTitle')].map(el => el.textContent.trim()) : null",
      5000,
    );
    await t.check("the " + tab.group + " tab draws as many cards as its count says", Array.isArray(drawn) && drawn.length === tab.count, JSON.stringify(drawn));
    titles.push(...(drawn ?? []));
  }

  // The queue switch is offered only on a continuous worker; under the sync
  // or database queue the realtime card says why pushes stay in the request.
  await browser.click(".ChatAdmin-tab[data-group=delivery]");
  const queue = await browser.waitFor(`(() => {
    const card = document.querySelector('.ChatAdmin-section--realtime');
    if (!card) return null;
    return {
      kind: app.data.ramonChatQueueKind ?? null,
      toggle: card.querySelectorAll('.ChatAdmin-fields .Checkbox').length,
      note: card.querySelector('.ChatAdmin-note') !== null,
    };
  })()`, 5000);
  const queueOk =
    queue !== null &&
    ["sync", "database", "other"].includes(queue.kind) &&
    (queue.kind === "other" ? queue.toggle === 1 && !queue.note : queue.toggle === 0 && queue.note);
  await t.check("the realtime card offers the queue switch only on a continuous worker", queueOk, JSON.stringify(queue));

  await t.check("the tabs hold the titled cards between them", titles.length >= 8, titles.length + " cards");
  await t.check("every card has a title", titles.length > 0 && titles.every((s) => s.length > 0), JSON.stringify(titles));

  // The search box reaches across tabs: a word from the webhooks card brings
  // it up whichever tab is open, labelled with the tab it lives in.
  await browser.click(".ChatAdmin-tab[data-group=general]");
  await browser.evaluate(`(() => {
    const input = document.querySelector('.ChatAdmin-searchInput');
    input.value = 'webhook';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
  })()`);
  const found = await browser.waitFor(
    "document.querySelector('.ChatAdmin-section--webhooks .ChatAdmin-sectionGroup') ? document.querySelectorAll('.ChatAdmin-section').length : 0",
    5000,
  );
  await t.check("the search finds the webhooks card from another tab", Boolean(found), String(found));
  await browser.click(".ChatAdmin-searchClear");

  // Alignment, on the integrations tab: the announcer and webhook panels
  // start at the same left edge as the card grid and the save bar, and span
  // the same width. Measured with the save bar at its place in the flow (the
  // page scrolled past the form), not pinned to the window.
  await browser.click(".ChatAdmin-tab[data-group=integrations]");
  await browser.waitFor("document.querySelector('.ChatAdmin-section--webhooks') !== null", 5000);
  await browser.evaluate("window.scrollTo(0, 0)");
  await sleep(200);

  const pinned = await browser.evaluate(`(() => {
    const r = document.querySelector('.ChatAdmin-controls').getBoundingClientRect();
    return { top: Math.round(r.top), bottom: Math.round(r.bottom), viewport: window.innerHeight };
  })()`);
  await t.check("the save bar is in view from the top of the page", pinned.bottom <= pinned.viewport && pinned.top >= 0, JSON.stringify(pinned));

  await browser.evaluate("document.querySelector('.ExtensionPage-permissions')?.scrollIntoView({ block: 'start' })");
  await sleep(200);

  const geometry = await browser.evaluate(`(() => {
    const rect = (el) => { const r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom) }; };
    const grid = rect(document.querySelector('.ChatAdmin-grid'));
    const controls = rect(document.querySelector('.ChatAdmin-controls'));
    const toolbar = rect(document.querySelector('.ChatAdmin-toolbar'));
    const announcer = rect(document.querySelector('.ChatAdmin-section--announcer'));
    const webhooks = rect(document.querySelector('.ChatAdmin-section--webhooks'));
    const toggle = document.querySelector('.ChatAdmin-section--webhooks .ChatAdmin-switch .Checkbox') !== null;
    return { grid, controls, toolbar, announcer, webhooks, toggle };
  })()`);

  const aligned =
    Math.abs(geometry.announcer.left - geometry.grid.left) <= 2 &&
    Math.abs(geometry.announcer.right - geometry.grid.right) <= 2 &&
    Math.abs(geometry.webhooks.left - geometry.grid.left) <= 2 &&
    Math.abs(geometry.controls.left - geometry.grid.left) <= 2 &&
    Math.abs(geometry.controls.right - geometry.grid.right) <= 2 &&
    Math.abs(geometry.toolbar.left - geometry.grid.left) <= 2;
  await t.check("the record panels line up with the tab bar, the settings cards and the save bar", aligned, JSON.stringify(geometry));

  const ordered = geometry.webhooks.bottom <= geometry.controls.top && geometry.announcer.bottom <= geometry.controls.top;
  await t.check("the announcer and webhook cards sit above the save bar", ordered, JSON.stringify({ webhooksBottom: geometry.webhooks.bottom, controlsTop: geometry.controls.top }));
  await t.check("the webhooks card carries the feature switch", geometry.toggle === true);

  await browser.evaluate("window.scrollTo(0, 0)");
  await sleep(200);
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
