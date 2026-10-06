#!/usr/bin/env node
/**
 * Channel ranks, seen by somebody else, live.
 *
 * C sits in a channel and never reloads. The administrator, who created it and
 * so shows as its owner, works through the API and then through the ranks
 * editor on their own screen:
 *
 *   - creates "Veteran" and gives it to B: B's next message reaches C with the
 *     tag before the name, in the rank's colour, with readable text on it
 *   - the administrator's own lines carry the built-in owner tag
 *   - turns the tag off: on C's screen B's name takes the rank's colour
 *     instead, the darkened one in the light theme and the lightened one in
 *     the dark theme, and no tag is left
 *   - a second rank given to B, then moved above the first: C's screen
 *     follows the new priority; deleting it falls back; deleting the last
 *     leaves B with no rank at all
 *   - when members run their channels, making B a moderator puts the built-in
 *     moderator tag on B's line, and taking it back restores the custom rank
 *   - in the editor: a rank created through the form, with its light and dark
 *     preview, and given out from the members tab
 *
 * Screenshots in both themes go to screenshots/ranks-*.png.
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php) and Edge.
 */
import { api, createChannel, deleteChannel, joinChannel, sendMessage } from "./lib/api.mjs";
import { harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const t = harness("ranks");
const { admin, b, c } = await loadTokens();
const stamp = Date.now().toString(36);

const created = await createChannel(admin.token, {
  name: "E2E ranks " + stamp,
  description: "created by tests/E2E/ranks.mjs",
  isPrivate: false,
});
await t.must("admin creates the channel", created.status === 201, "HTTP " + created.status);
const R = Number(created.json.data.id);
await t.must("B joins", (await joinChannel(b.token, R)).status === 200);
await t.must("C joins", (await joinChannel(c.token, R)).status === 200);

const rankCall = (path, attributes) =>
  api(admin.token, "POST", "/chat-channels/" + R + "/ranks" + path, { data: { attributes } });
const bookOf = (response) => response.json?.data?.attributes?.rankBook;

const rowOf = (id) => "[...document.querySelectorAll('.ChatMessage')].find((e) => e.dataset.id === '" + id + "')";
const tagOn = (id) => "(" + rowOf(id) + ")?.querySelector('.ChatMessage-rank')";
const authorOn = (id) => "(" + rowOf(id) + ")?.querySelector('.ChatMessage-author')";
const rgb = (hex) =>
  "rgb(" + [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)).join(", ") + ")";

const watcher = await launchBrowser({ label: "C" });
const owner = await launchBrowser({ label: "admin", width: 1360, height: 1000 });

try {
  await watcher.loginWithRememberToken(c.remember);
  await watcher.goto("/chat/c/" + R);
  await t.must("C opens the channel", Boolean(await watcher.waitFor("document.querySelector('.ChatChannel textarea') !== null", 20000)));
  await watcher.waitFor("flarum.extensions['ramon-chat'].realtimeLive()", 8000, 100);
  await sleep(400);

  // ── A custom rank, given to B ───────────────────────────────────────────────
  const veteran = await rankCall("", { name: "Veteran", color: "#f1c40f", icon: "fas fa-star" });
  await t.must("the owner creates Veteran", veteran.status === 200, "HTTP " + veteran.status);
  const veteranRank = bookOf(veteran).ranks.find((rank) => rank.name === "Veteran");
  const veteranId = veteranRank.id;

  const given = await rankCall("/assign", { userId: b.id, rankIds: [veteranId] });
  await t.must("and gives it to B", given.status === 200, "HTTP " + given.status);

  let at = Date.now();
  const fromB = await sendMessage(b.token, R, "B speaks " + stamp);
  await t.must("B sends a message", fromB.status === 201, "HTTP " + fromB.status);
  const bMessage = Number(fromB.json.data.id);
  await t.check(
    "it reaches C with the Veteran tag before the name",
    Boolean(await watcher.waitFor(tagOn(bMessage) + "?.textContent.includes('Veteran')", 6000, 25)),
    Date.now() - at + " ms",
  );
  await t.check(
    "the tag comes before the name",
    Boolean(await watcher.evaluate("(() => { const tag = " + tagOn(bMessage) + "; const name = " + authorOn(bMessage) + "; return !!tag && !!name && !!(tag.compareDocumentPosition(name) & Node.DOCUMENT_POSITION_FOLLOWING); })()")),
  );
  const tagColours = await watcher.evaluate("(() => { const s = getComputedStyle(" + tagOn(bMessage) + "); return [s.backgroundColor, s.color]; })()");
  await t.check("the tag is the rank's colour", tagColours?.[0] === rgb("#f1c40f"), JSON.stringify(tagColours));
  await t.check("with dark text, which reads on yellow", tagColours?.[1] === rgb(veteranRank.textColor), JSON.stringify(tagColours));
  await t.check("and the icon", Boolean(await watcher.evaluate("!!(" + tagOn(bMessage) + ")?.querySelector('.fa-star')")));

  const fromAdmin = await sendMessage(admin.token, R, "owner speaks " + stamp);
  const adminMessage = Number(fromAdmin.json.data.id);
  await t.check(
    "the owner's line carries the built-in owner tag",
    Boolean(await watcher.waitFor("!!(" + rowOf(adminMessage) + ")?.querySelector('.ChatMessage-rank[data-rank=owner]')", 6000, 25)),
  );

  await watcher.screenshot("ranks-01-tags-light");
  await watcher.evaluate("(app.setColorScheme('dark'), m.redraw.sync(), true)");
  await sleep(300);
  await watcher.screenshot("ranks-02-tags-dark");

  // ── Shown without its tag: the name takes the colour ────────────────────────
  at = Date.now();
  const quiet = await rankCall("/update", { rankId: veteranId, showBadge: false });
  await t.must("the owner turns Veteran's tag off", quiet.status === 200, "HTTP " + quiet.status);
  const quietRank = bookOf(quiet).ranks.find((rank) => rank.id === veteranId);
  await t.check(
    "C's view loses the tag live",
    Boolean(await watcher.waitFor("!" + tagOn(bMessage) + " && (" + authorOn(bMessage) + ")?.classList.contains('ChatRankName')", 6000, 25)),
    Date.now() - at + " ms",
  );
  const nameColour = () => watcher.evaluate("getComputedStyle((" + authorOn(bMessage) + ").querySelector('a, span') ?? " + authorOn(bMessage) + ").color");
  await t.check("in the dark theme the name is the lightened colour", (await nameColour()) === rgb(quietRank.nameDark), (await nameColour()) + " vs " + quietRank.nameDark);
  await watcher.screenshot("ranks-03-name-dark");

  await watcher.evaluate("(app.setColorScheme('light'), m.redraw.sync(), true)");
  await sleep(300);
  await t.check("in the light theme, the darkened one", (await nameColour()) === rgb(quietRank.nameLight), (await nameColour()) + " vs " + quietRank.nameLight);
  await t.check("which is not the raw yellow", quietRank.nameLight !== "#f1c40f");
  await watcher.screenshot("ranks-04-name-light");

  // ── Priority ────────────────────────────────────────────────────────────────
  const helper = await rankCall("", { name: "Helper", color: "#2e86de" });
  await t.must("the owner creates Helper", helper.status === 200, "HTTP " + helper.status);
  const helperId = bookOf(helper).ranks.find((rank) => rank.name === "Helper").id;
  await t.must("and gives B both", (await rankCall("/assign", { userId: b.id, rankIds: [veteranId, helperId] })).status === 200);
  await sleep(300);
  await t.check("Veteran, first in order, still wins", Boolean(await watcher.waitFor("!" + tagOn(bMessage) + " && (" + authorOn(bMessage) + ")?.classList.contains('ChatRankName')", 4000, 25)));

  at = Date.now();
  await t.must("the owner moves Helper above Veteran", (await rankCall("/order", { rankIds: [helperId, veteranId] })).status === 200);
  await t.check(
    "C sees Helper's tag on B live",
    Boolean(await watcher.waitFor(tagOn(bMessage) + "?.textContent.includes('Helper')", 6000, 25)),
    Date.now() - at + " ms",
  );

  at = Date.now();
  await t.must("the owner deletes Helper", (await rankCall("/delete", { rankId: helperId })).status === 200);
  await t.check(
    "B falls back to Veteran's coloured name",
    Boolean(await watcher.waitFor("!" + tagOn(bMessage) + " && (" + authorOn(bMessage) + ")?.classList.contains('ChatRankName')", 6000, 25)),
    Date.now() - at + " ms",
  );

  // ── The moderator tag, when members run their channels ─────────────────────
  const show = await api(admin.token, "GET", "/chat-channels/" + R);

  if (show.json?.data?.attributes?.canManageModerators) {
    at = Date.now();
    const promoted = await api(admin.token, "POST", "/chat-channels/" + R + "/moderators", { data: { attributes: { userId: b.id } } });
    await t.must("the owner makes B a moderator", promoted.status === 200, "HTTP " + promoted.status);
    await t.check(
      "C sees the moderator tag on B live, above Veteran",
      Boolean(await watcher.waitFor("(" + tagOn(bMessage) + ")?.dataset.rank === 'moderator'", 6000, 25)),
      Date.now() - at + " ms",
    );
    await watcher.screenshot("ranks-05-moderator");

    at = Date.now();
    await api(admin.token, "POST", "/chat-channels/" + R + "/moderators/remove", { data: { attributes: { userId: b.id } } });
    await t.check(
      "and Veteran again once it is taken back",
      Boolean(await watcher.waitFor("!" + tagOn(bMessage) + " && (" + authorOn(bMessage) + ")?.classList.contains('ChatRankName')", 6000, 25)),
      Date.now() - at + " ms",
    );
  } else {
    log("skip: channels are in administrators' hands, so there are no channel moderators to tag");
  }

  at = Date.now();
  await t.must("the owner deletes Veteran", (await rankCall("/delete", { rankId: veteranId })).status === 200);
  await t.check(
    "B shows no rank at all",
    Boolean(await watcher.waitFor("!" + tagOn(bMessage) + " && !(" + authorOn(bMessage) + ")?.classList.contains('ChatRankName')", 6000, 25)),
    Date.now() - at + " ms",
  );

  // ── The editor ──────────────────────────────────────────────────────────────
  await owner.loginWithRememberToken(admin.remember);
  await owner.goto("/chat/c/" + R);
  await t.must("the administrator opens the channel", Boolean(await owner.waitFor("document.querySelector('.ChatChannel-title') !== null", 20000)));
  await owner.click(".ChatChannel-title");
  await t.must("the details open", Boolean(await owner.waitFor("document.querySelectorAll('.ChatChannelInfo-tab').length === 3", 10000)));
  await owner.clickWhere("[...document.querySelectorAll('.ChatChannelInfo-tab')][2]");
  await t.must("the Ranks tab lists the built-in ranks", Boolean(await owner.waitFor("document.querySelectorAll('.ChatRanks-row').length === 2", 10000)));

  await owner.clickWhere("document.querySelector('.ChatRanks-footer .Button--primary')");
  await t.must("the form opens", Boolean(await owner.waitFor("!!document.querySelector('.ChatRanks-editor')", 5000)));

  const type = (selector, value) =>
    owner.evaluate(
      "(() => { const el = document.querySelector(" + JSON.stringify(selector) + "); el.value = " + JSON.stringify(value) +
        "; el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()",
    );
  await type(".ChatRanks-editor .ChatRanks-field input.FormControl", "Mentor " + stamp.slice(-3));
  await type(".ChatRanks-hex", "#8e44ad");
  await type(".ChatRanks-icon input.FormControl", "fas fa-graduation-cap");
  await sleep(200);
  await t.check(
    "the preview shows the tag on a light and a dark surface",
    Boolean(await owner.evaluate("document.querySelectorAll('.ChatRanks-sampleLine .ChatRankTag').length === 2")),
  );
  await owner.screenshot("ranks-06-editor-light");
  await owner.evaluate("(app.setColorScheme('dark'), m.redraw.sync(), true)");
  await sleep(300);
  await owner.screenshot("ranks-07-editor-dark");

  await owner.clickWhere("document.querySelector('.ChatRanks-editorActions .Button--primary')");
  await t.check(
    "saving lists the new rank",
    Boolean(await owner.waitFor("document.querySelectorAll('.ChatRanks-row').length === 3 && !document.querySelector('.ChatRanks-editor')", 6000, 50)),
  );
  await owner.screenshot("ranks-08-list-dark");

  await owner.clickWhere("[...document.querySelectorAll('.ChatChannelInfo-tab')][1]");
  const bRow = "[...document.querySelectorAll('.ChatChannelInfo-member')].find((r) => r.textContent.includes(" + JSON.stringify(b.username) + "))";
  await t.must("the members tab lists B", Boolean(await owner.waitFor("!!(" + bRow + ")?.querySelector('.ChatChannelInfo-member-ranks')", 10000)));
  await owner.clickWhere("(" + bRow + ").querySelector('.ChatChannelInfo-member-ranks')");
  await t.must("B's ranks open", Boolean(await owner.waitFor("!!document.querySelector('.ChatChannelInfo-rankOption input')", 5000)));

  at = Date.now();
  await owner.clickWhere("document.querySelector('.ChatChannelInfo-rankOption input')");
  await t.check(
    "ticking a rank shows it on B's row",
    Boolean(await owner.waitFor("!!(" + bRow + ")?.querySelector('.ChatRankTag')", 6000, 25)),
    Date.now() - at + " ms",
  );
  await t.check(
    "and on B's line in C's screen, live",
    Boolean(await watcher.waitFor(tagOn(bMessage) + "?.textContent.includes('Mentor')", 6000, 25)),
    Date.now() - at + " ms",
  );
  await owner.screenshot("ranks-09-members-dark");
  await owner.evaluate("(app.setColorScheme('light'), m.redraw.sync(), true)");
  await sleep(300);
  await owner.screenshot("ranks-10-members-light");
  await watcher.screenshot("ranks-11-mentor-light");

  for (const [label, page] of [["C", watcher], ["the administrator", owner]]) {
    const errors = page.consoleErrors.filter((e) => !/favicon|manifest/i.test(e));
    await t.check("no uncaught page exceptions for " + label, errors.length === 0, errors.slice(0, 3).join(" | "));
  }
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
  try {
    await watcher.screenshot("ranks-99-watcher");
    await owner.screenshot("ranks-99-owner");
  } catch {
    // Nothing more to capture.
  }
} finally {
  await watcher.close();
  await owner.close();

  const removed = await deleteChannel(admin.token, R);
  log("cleanup channel " + R + " HTTP " + removed.status);
}

await sleep(100);
process.exit(t.summary());
