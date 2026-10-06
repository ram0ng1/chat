#!/usr/bin/env node
/**
 * Permissions, end to end: every level the chat knows about against every
 * capability it offers, and then isolation between two users of the *same*
 * level — member A trying to reach member B's things by id.
 *
 * Actors (tests/E2E/.tokens.json, from mint-tokens.php):
 *   guest   no token
 *   a, b, c ordinary members (A is the one probing; B owns the things probed)
 *   mod     member of the Moderators group (ramon-chat.moderate, inspect, ...)
 *   admin   user 1
 *   susp    suspended: flarum/suspend demotes them to guest permissions
 *   unconf  unconfirmed email: core gives them guest permissions too
 *
 * Fixtures, all created here and removed in `finally`:
 *   P   public channel owned by the admin, with A, B and C in it
 *   PB  B's invitation-only channel, with C in it (and a pending invite for susp)
 *   D   B's direct conversation with C (startDirect is granted to Members for the
 *       one request that opens it, then put back exactly as it was)
 *   S2  public scratch channel the moderator moves a message into
 *   AC  A's own channel, for the mass-assignment probes
 *
 * Every message in PB carries PRIV, every message in D carries DM and B's drafts
 * carry DRAFT. Each response any actor receives is kept, and at the end each
 * actor's whole transcript is scanned for the markers they must never see, so a
 * leak through an unexpected door (an `included` replyTo, a search, a
 * notification, a websocket push) is caught even where no check was aimed at it.
 *
 * Expectations come from src/Access/*; where the server disagrees the check
 * fails with the response in the detail — that is a finding, not a flaky test.
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
  sendMessage,
} from "./lib/api.mjs";
import { API, BASE, harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { subscribeUser } from "./lib/realtime.mjs";

const here = dirname(fileURLToPath(import.meta.url));
const PHP =
  process.env.CHAT_E2E_PHP ||
  "D:/laragon/bin/php/php-8.5.10-nts-Win32-vs17-x64/php.exe";

const t = harness("permissions");
const tokens = await loadTokens();

for (const key of ["admin", "a", "b", "c", "mod", "susp", "unconf"]) {
  if (!tokens[key]) {
    await t.must("token for " + key, false, "re-run php tests/E2E/mint-tokens.php");
  }
}

const ACTORS = ["guest", "a", "b", "c", "mod", "admin", "susp", "unconf"];
const tokenOf = (who) => (who === "guest" ? null : tokens[who].token);
const idOf = (who) => (who === "guest" ? 0 : tokens[who].id);

const stamp = Date.now().toString(36);
const PRIV = "zqpriv" + stamp;
const DM = "zqdm" + stamp;
const DRAFT = "zqdraft" + stamp;
const MOVED = "zqmoved" + stamp;

/** Every response each actor received: `{ label, text, json }`. */
const transcript = Object.fromEntries(ACTORS.map((who) => [who, []]));

async function as(who, method, path, body) {
  const response = await api(tokenOf(who), method, path, body);

  transcript[who].push({ label: method + " " + path, text: response.text, json: response.json });

  return response;
}

const lastSend = {};

/** Posts a message, staying under the per-second rate limit for non-admins. */
async function post(who, channelId, content, extra = {}) {
  const wait = (lastSend[who] ?? 0) + 650 - Date.now();

  if (wait > 0 && who !== "admin") await sleep(wait);

  const response = await as(who, "POST", "/chat-messages", {
    data: {
      type: "chat-messages",
      attributes: { content, channelId: Number(channelId), ...extra },
    },
  });

  lastSend[who] = Date.now();

  return response;
}

async function multipart(who, path, fields, fileField, file) {
  const form = new FormData();

  for (const [key, value] of Object.entries(fields)) form.append(key, String(value));

  if (fileField) form.append(fileField, new Blob([file.bytes], { type: file.type }), file.name);

  const headers = { Accept: "application/json" };

  if (tokenOf(who)) headers.Authorization = "Token " + tokenOf(who);

  let status = 0;
  let text = "";
  let json = null;

  try {
    const response = await fetch(API + path, { method: "POST", headers, body: form });

    status = response.status;
    text = await response.text();

    try {
      json = text ? JSON.parse(text) : null;
    } catch {
      json = null;
    }
  } catch (e) {
    text = String(e);
  }

  transcript[who].push({ label: "POST " + path, text, json });

  return { status, json, text };
}

const PNG = {
  bytes: Buffer.from(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==",
    "base64",
  ),
  type: "image/png",
  name: "pixel.png",
};

const markersIn = (text, markers) => markers.filter((m) => (text ?? "").includes(m));

const brief = (r) => "HTTP " + r.status + " " + (r.text ?? "").slice(0, 160).replace(/\s+/g, " ");

const idsOf = (r) => {
  const data = r.json?.data;

  if (Array.isArray(data)) return data.map((row) => Number(row.id));
  if (data && data.id != null) return [Number(data.id)];

  return [];
};

/**
 * A guest's write without a session is stopped by the CSRF check before it ever
 * reaches authentication: a 400 `csrf_token_mismatch` is as much a refusal as
 * the 401 behind it, so wherever 401 is expected it is accepted too.
 */
const refusedByCsrf = (r) => r.status === 400 && (r.text ?? "").includes("csrf_token_mismatch");

/** Status in the allowed set and none of the markers anywhere in the body. */
async function deny(name, r, statuses, markers = [PRIV, DM, DRAFT, MOVED]) {
  const leaked = markersIn(r.text, markers);
  const statusOk = statuses.includes(r.status) || (statuses.includes(401) && refusedByCsrf(r));

  return t.check(
    name,
    statusOk && leaked.length === 0,
    brief(r) + (leaked.length ? " LEAKED " + leaked.join(",") : ""),
  );
}

async function allow(name, r, statuses = [200]) {
  return t.check(name, statuses.includes(r.status), brief(r));
}

/** A collection that answered and holds none of the given ids or markers. */
async function excludes(name, r, ids, markers = [PRIV, DM, DRAFT, MOVED], okStatuses = [200]) {
  const present = idsOf(r).filter((id) => ids.includes(id));
  const leaked = markersIn(r.text, markers);
  const statusOk = okStatuses.includes(r.status) || [401, 403, 404, 422].includes(r.status);

  return t.check(
    name,
    statusOk && present.length === 0 && leaked.length === 0,
    "HTTP " + r.status + (present.length ? " ids " + present.join(",") : "") + (leaked.length ? " LEAKED " + leaked.join(",") : ""),
  );
}

/** Reads and writes the group list of one permission through the admin page. */
async function permissionGroups(permission) {
  const response = await fetch(BASE + "/admin", {
    headers: { Cookie: "flarum_remember=" + tokens.admin.remember },
  });
  const html = await response.text();
  const match = html.match(/<script id="flarum-json-payload"[^>]*>([\s\S]*?)<\/script>/);

  if (!match) return null;

  return (JSON.parse(match[1]).permissions?.[permission] ?? []).map(String);
}

async function setPermissionGroups(permission, groupIds) {
  return as("admin", "POST", "/permission", { permission, groupIds: groupIds.map(Number) });
}

function purgeChannels(ids) {
  return new Promise((resolve) => {
    if (ids.length === 0) return resolve("nothing to purge");

    const child = spawn(PHP, [join(here, "lib", "purge-channels.php"), ...ids.map(String)], {
      cwd: join(here, "..", "..", "..", ".."),
    });
    let out = "";

    child.stdout.on("data", (d) => (out += d));
    child.stderr.on("data", (d) => (out += d));
    child.on("error", (e) => resolve("php failed: " + e.message));
    child.on("exit", () => resolve(out.trim()));
  });
}

// ── State and cleanup bookkeeping ──────────────────────────────────────────
const ids = {};
const apiChannels = [];
const purgeOnly = [];
const webhooks = [];
const pendingUploads = [];
let startDirectOriginal = null;
let startDirectChanged = false;
const sockets = {};

const forumDoc = await forum(tokens.admin.token);
await t.must("forum reachable", forumDoc.status === 200, "HTTP " + forumDoc.status);

const fattrs = forumDoc.json.data.attributes;
const websocket = {
  key: fattrs["websocket.key"],
  host: fattrs["websocket.host"],
  port: fattrs["websocket.port"],
  secure: fattrs["websocket.secure"] === "1" || fattrs["websocket.secure"] === true,
};

try {
  for (const who of ["a", "b"]) {
    try {
      sockets[who] = await subscribeUser({ websocket, token: tokenOf(who), userId: idOf(who), label: who.toUpperCase() });
    } catch (e) {
      await t.fail("websocket subscription for " + who, e.message);
    }
  }

  // ══ Setup ═════════════════════════════════════════════════════════════════
  const p = await createChannel(tokens.admin.token, { name: "E2E perm P " + stamp, description: "tests/E2E/permissions.mjs" });
  await t.must("admin creates the public channel P", p.status === 201, brief(p));
  ids.P = Number(p.json.data.id);
  apiChannels.push(ids.P);

  for (const who of ["a", "b", "c"]) {
    const joined = await joinChannel(tokenOf(who), ids.P);
    await t.must(who.toUpperCase() + " joins P", joined.status === 200, brief(joined));
  }

  const pb = await as("b", "POST", "/chat-channels", {
    data: { type: "chat-channels", attributes: { type: "category", name: "E2E perm PB " + PRIV, description: PRIV, isPrivate: true } },
  });
  await t.must("member B creates a private channel PB (members own channels)", pb.status === 201, brief(pb));
  ids.PB = Number(pb.json.data.id);
  apiChannels.push(ids.PB);

  const invC = await as("b", "POST", "/chat-channels/" + ids.PB + "/members", { data: { attributes: { userIds: [idOf("c")] } } });
  await t.must("B (owner) invites C into PB", invC.status === 200, brief(invC));
  const accC = await as("c", "POST", "/chat/invites/" + ids.PB + "/accept");
  await t.must("C accepts the PB invitation", accC.status === 200, brief(accC));

  const s2 = await createChannel(tokens.admin.token, { name: "E2E perm S2 " + stamp });
  await t.must("admin creates scratch channel S2", s2.status === 201, brief(s2));
  ids.S2 = Number(s2.json.data.id);
  apiChannels.push(ids.S2);

  // Direct conversation B↔C. Members do not hold startDirect here, so it is
  // granted for this one request and put back exactly as it was.
  const before = await as("b", "POST", "/chat/direct", { data: { attributes: { userIds: [idOf("c")] } } });
  await deny("[role] member B cannot start a direct conversation without startDirect", before, [403]);

  startDirectOriginal = await permissionGroups("ramon-chat.startDirect");
  await t.must("read startDirect grants from the admin payload", Array.isArray(startDirectOriginal), String(startDirectOriginal));

  const granted = await setPermissionGroups("ramon-chat.startDirect", [...new Set([...startDirectOriginal, "3"])]);
  await t.must("admin grants startDirect to Members (temporarily)", granted.status === 204 || granted.status === 200, brief(granted));
  startDirectChanged = true;

  const d = await as("b", "POST", "/chat/direct", { data: { attributes: { userIds: [idOf("c")] } } });
  await t.check("[role] member B starts a direct conversation once granted startDirect", d.status === 201 || d.status === 200, brief(d));

  const restored = await setPermissionGroups("ramon-chat.startDirect", startDirectOriginal);
  await t.must("startDirect grants restored", restored.status === 204 || restored.status === 200, brief(restored));
  startDirectChanged = false;

  await t.must("direct conversation D exists", d.json?.data?.id, brief(d));
  ids.D = Number(d.json.data.id);
  purgeOnly.push(ids.D);

  const after = await as("b", "POST", "/chat/direct", { data: { attributes: { userIds: [idOf("a")] } } });
  await deny("[role] member B is refused startDirect again once the grant is withdrawn", after, [403]);

  // Messages.
  const send = async (label, who, channel, content, extra) => {
    const r = await post(who, channel, content, extra);
    await t.must("setup: " + label, r.status === 201, brief(r));
    return Number(r.json.data.id);
  };

  ids.msgP_B = await send("B posts in P", "b", ids.P, "B in P " + stamp);
  ids.msgP_C = await send("C posts in P", "c", ids.P, "C in P " + stamp);
  ids.msgP_Cmove = await send("C posts a message the moderator will move", "c", ids.P, "C to move " + stamp);
  ids.msgP_Cdel = await send("C posts a message the moderator will delete", "c", ids.P, "C to delete " + stamp);
  ids.msgP_A = await send("A posts in P", "a", ids.P, "A in P " + stamp);
  ids.msgP_Bdel = await send("B posts a message B will edit and delete", "b", ids.P, "B original " + stamp);
  ids.msgPB_B = await send("B posts in PB", "b", ids.PB, "B in PB " + PRIV);
  ids.msgPB_C = await send("C posts in PB", "c", ids.PB, "C in PB " + PRIV);
  ids.msgPB_C2 = await send("C posts a second message in PB", "c", ids.PB, "C again in PB " + PRIV);
  ids.msgD_B = await send("B posts in D", "b", ids.D, "B in D " + DM);
  ids.msgD_C = await send("C posts in D", "c", ids.D, "C in D " + DM);

  const editOwn = await as("b", "PATCH", "/chat-messages/" + ids.msgP_Bdel, { data: { type: "chat-messages", id: String(ids.msgP_Bdel), attributes: { content: "B edited " + stamp } } });
  await allow("[role] member B edits their own message", editOwn);
  const editOwn2 = await as("b", "PATCH", "/chat-messages/" + ids.msgP_B, { data: { type: "chat-messages", id: String(ids.msgP_B), attributes: { content: "B in P, edited " + stamp } } });
  await allow("[role] member B edits their own message again (revision for A to read)", editOwn2);
  const delOwn = await as("b", "POST", "/chat-messages/" + ids.msgP_Bdel + "/delete");
  await allow("[role] member B deletes their own message", delOwn, [204]);

  // Uploads: one attached in PB, one in D, one in P, one left pending.
  const upload = async (label, channel) => {
    const r = await multipart("b", "/chat/uploads", { channelId: channel }, "file", PNG);
    await t.must("setup: " + label, r.status === 201, brief(r));
    return { id: Number(r.json.data.id), url: r.json.data.attributes.url, isPrivate: r.json.data.attributes.isPrivate };
  };

  const upPB = await upload("B uploads into PB", ids.PB);
  const upD = await upload("B uploads into D", ids.D);
  const upP = await upload("B uploads into P", ids.P);
  const upPending = await upload("B uploads a file it never sends", ids.P);
  pendingUploads.push({ who: "b", id: upPending.id });

  await t.check("an upload into a private channel is private", upPB.isPrivate === true && String(upPB.url).includes("/api/chat/uploads/"), JSON.stringify(upPB));
  await t.check("an upload into a direct conversation is private", upD.isPrivate === true && String(upD.url).includes("/api/chat/uploads/"), JSON.stringify(upD));

  ids.msgPB_up = await send("B posts the PB attachment", "b", ids.PB, "file " + PRIV, { uploadIds: [upPB.id] });
  ids.msgD_up = await send("B posts the D attachment", "b", ids.D, "file " + DM, { uploadIds: [upD.id] });
  ids.msgP_up = await send("B posts the P attachment", "b", ids.P, "file in P " + stamp, { uploadIds: [upP.id] });

  // B's drafts and bookmarks.
  for (const channel of [ids.PB, ids.D]) {
    const r = await as("b", "POST", "/chat/drafts", { data: { attributes: { channelId: channel, content: "draft " + DRAFT } } });
    await t.must("setup: B saves a draft in channel " + channel, r.status === 200, brief(r));
  }

  for (const message of [ids.msgP_C, ids.msgPB_C]) {
    const r = await as("b", "POST", "/chat-messages/" + message + "/bookmark");
    await t.must("setup: B bookmarks message " + message, r.status === 200, brief(r));
  }

  // The admin's view of PB: an unseen membership, a thread and a pin, and a quote
  // of a PB message posted into the public channel.
  const hiddenJoin = await as("admin", "POST", "/chat-channels/" + ids.PB + "/join", { data: { attributes: { hidden: true } } });
  await allow("[role] admin joins PB unseen (inspectChannels)", hiddenJoin);

  const threadRoot = await post("admin", ids.PB, "thread reply " + PRIV, { replyToId: ids.msgPB_B, createThread: true });
  await allow("[role] admin opens a thread in PB (createThread)", threadRoot, [201]);
  ids.TPB = Number(threadRoot.json?.data?.attributes?.threadId ?? threadRoot.json?.data?.relationships?.thread?.data?.id ?? 0);
  await t.check("setup: the PB thread has an id", ids.TPB > 0, String(ids.TPB));

  const pinPB = await as("admin", "POST", "/chat-messages/" + ids.msgPB_B + "/pin");
  await allow("[role] admin pins a PB message", pinPB);

  const crossQuote = await post("admin", ids.P, "quoting a private message", { replyToId: ids.msgPB_B });
  await deny("[role] even the admin cannot reply across channels (PB message quoted from P)", crossQuote, [422]);

  // The one way a public message ends up replying to a private one: its original
  // is moved into a private channel afterwards. C posts in P, C replies to it in
  // P, and the moderator (an unseen member of PB) moves the original into PB.
  ids.msgP_orig = await send("C posts an original in P that will be moved", "c", ids.P, "original " + MOVED);
  ids.msgP_reply = await send("C replies to it in P", "c", ids.P, "reply to the moved one", { replyToId: ids.msgP_orig });
  await allow("setup: moderator joins PB unseen", await as("mod", "POST", "/chat-channels/" + ids.PB + "/join", { data: { attributes: { hidden: true } } }));
  const moveIn = await as("mod", "POST", "/chat/messages/move", { data: { attributes: { messageIds: [ids.msgP_orig], channelId: ids.PB } } });
  await allow("setup: moderator moves the original into PB", moveIn);
  await allow("setup: moderator leaves PB", await as("mod", "POST", "/chat-channels/" + ids.PB + "/leave"), [204]);

  // A pending invitation for someone other than A.
  const invSusp = await as("b", "POST", "/chat-channels/" + ids.PB + "/members", { data: { attributes: { userIds: [idOf("susp")] } } });
  await allow("setup: B invites the suspended user into PB (left pending)", invSusp);

  // B mentions A inside PB, where A is not a member.
  const mention = await post("b", ids.PB, "@chat_e2e_a look " + PRIV);
  await allow("setup: B mentions A inside PB", mention, [201]);

  // ══ Role × capability ═════════════════════════════════════════════════════
  const FLAGS = ["canUseChat", "canCreateChatChannel", "canModerateChat", "canUploadChatFiles", "canStartChatDirect", "canFlagChatMessages"];
  const expectedFlags = {
    guest: [false, false, false, false, false, false],
    a: [true, true, false, true, false, true],
    mod: [true, true, true, true, false, true],
    admin: [true, true, true, true, true, true],
    susp: [false, false, false, false, false, false],
    unconf: [false, false, false, false, false, false],
  };

  for (const [who, expected] of Object.entries(expectedFlags)) {
    const doc = await as(who, "GET", "/");
    const attrs = doc.json?.data?.attributes ?? {};
    const actual = FLAGS.map((f) => attrs[f] === true);
    await t.check(
      "[role] " + who + " forum capability flags",
      doc.status === 200 && JSON.stringify(actual) === JSON.stringify(expected),
      FLAGS.map((f, i) => f + "=" + actual[i] + (actual[i] !== expected[i] ? "(want " + expected[i] + ")" : "")).join(" "),
    );
  }

  // Channel list.
  const listExpect = {
    a: { P: true, PB: false, D: false },
    b: { P: true, PB: true, D: true },
    mod: { P: true, PB: true, D: false },
    admin: { P: true, PB: true, D: false },
  };

  for (const [who, expected] of Object.entries(listExpect)) {
    const r = await as(who, "GET", "/chat-channels?page[limit]=50");
    const listed = idsOf(r);
    const actual = { P: listed.includes(ids.P), PB: listed.includes(ids.PB), D: listed.includes(ids.D) };
    await t.check("[role] " + who + " channel list (P/PB/D)", r.status === 200 && JSON.stringify(actual) === JSON.stringify(expected), "HTTP " + r.status + " " + JSON.stringify(actual));
  }

  await deny("[role] guest cannot list channels", await as("guest", "GET", "/chat-channels"), [401]);

  for (const who of ["susp", "unconf"]) {
    await excludes("[role] " + who + " channel list holds nothing", await as(who, "GET", "/chat-channels"), [ids.P, ids.PB, ids.D, ids.S2]);
    await deny("[role] " + who + " cannot open P", await as(who, "GET", "/chat-channels/" + ids.P), [403, 404]);
    await deny("[role] " + who + " cannot read a P message", await as(who, "GET", "/chat-messages/" + ids.msgP_B), [403, 404]);
    await deny("[role] " + who + " cannot post in P", await post(who, ids.P, "should not land"), [403, 404, 422]);
    await deny("[role] " + who + " cannot save a draft", await as(who, "POST", "/chat/drafts", { data: { attributes: { channelId: ids.P, content: "x" } } }), [403, 404]);
    await deny("[role] " + who + " cannot upload", await multipart(who, "/chat/uploads", { channelId: ids.P }, "file", PNG), [403]);
    await deny("[role] " + who + " cannot ping the realtime channel", await as(who, "POST", "/chat/realtime/ping"), [403]);
    await deny("[role] " + who + " cannot create a channel", await as(who, "POST", "/chat-channels", { data: { type: "chat-channels", attributes: { type: "category", name: "nope" } } }), [403]);
    await deny("[role] " + who + " cannot start a direct conversation", await as(who, "POST", "/chat/direct", { data: { attributes: { userIds: [idOf("a")] } } }), [403]);
    await deny("[role] " + who + " cannot open the moderation queue", await as(who, "GET", "/chat-message-flags"), [403]);
    await deny("[role] " + who + " cannot flag a message", await as(who, "POST", "/chat-message-flags", { data: { type: "chat-message-flags", attributes: { messageId: ids.msgP_B, reason: "spam" } } }), [403, 404]);
  }

  // Private and direct visibility for the privileged levels.
  await allow("[role] moderator opens private PB (moderate bypasses privacy)", await as("mod", "GET", "/chat-channels/" + ids.PB));
  const modPbMsgs = await as("mod", "GET", "/chat-messages?filter[channel]=" + ids.PB);
  await t.check("[role] moderator reads PB's messages", idsOf(modPbMsgs).includes(ids.msgPB_B), "HTTP " + modPbMsgs.status);
  await deny("[role] moderator cannot open B's direct conversation", await as("mod", "GET", "/chat-channels/" + ids.D), [403, 404], [DM, DRAFT]);
  await allow("[role] admin opens private PB", await as("admin", "GET", "/chat-channels/" + ids.PB));
  await deny("[role] admin cannot open a direct conversation they are not in", await as("admin", "GET", "/chat-channels/" + ids.D), [403, 404], [DM, DRAFT]);
  await excludes("[role] admin's message search never reaches D", await as("admin", "GET", "/chat-messages?filter[channel]=" + ids.D), [ids.msgD_B, ids.msgD_C], [DM]);

  // Creating channels.
  await deny("[role] guest cannot create a channel", await as("guest", "POST", "/chat-channels", { data: { type: "chat-channels", attributes: { type: "category", name: "nope" } } }), [401]);
  const modChannel = await as("mod", "POST", "/chat-channels", { data: { type: "chat-channels", attributes: { type: "category", name: "E2E perm mod " + stamp } } });
  await allow("[role] moderator creates a channel", modChannel, [201]);
  if (modChannel.json?.data?.id) apiChannels.push(Number(modChannel.json.data.id));

  // Posting.
  await deny("[role] guest cannot post", await post("guest", ids.P, "x"), [401]);
  await deny("[role] moderator cannot post in P without joining (membership required)", await post("mod", ids.P, "x"), [403]);

  // Uploads.
  await deny("[role] guest cannot upload", await multipart("guest", "/chat/uploads", { channelId: ids.P }, "file", PNG), [401]);
  const modUpload = await multipart("mod", "/chat/uploads", { channelId: ids.P }, "file", PNG);
  await allow("[role] moderator uploads", modUpload, [201]);
  if (modUpload.json?.data?.id) pendingUploads.push({ who: "mod", id: Number(modUpload.json.data.id) });

  // Direct conversations.
  await deny("[role] guest cannot start a direct conversation", await as("guest", "POST", "/chat/direct", { data: { attributes: { userIds: [idOf("a")] } } }), [401]);
  await deny("[role] member A cannot start a direct conversation", await as("a", "POST", "/chat/direct", { data: { attributes: { userIds: [idOf("b")] } } }), [403]);
  await deny("[role] moderator cannot start a direct conversation (not granted to Mods)", await as("mod", "POST", "/chat/direct", { data: { attributes: { userIds: [idOf("a")] } } }), [403]);
  const adminDirect = await as("admin", "POST", "/chat/direct", { data: { attributes: { userIds: [idOf("a")] } } });
  await allow("[role] admin starts a direct conversation", adminDirect, [200, 201]);
  if (adminDirect.json?.data?.id && adminDirect.status === 201) apiChannels.push(Number(adminDirect.json.data.id));

  // Moderation queue.
  await deny("[role] guest cannot open the moderation queue", await as("guest", "GET", "/chat-message-flags"), [401]);
  await deny("[role] member A cannot open the moderation queue", await as("a", "GET", "/chat-message-flags"), [403]);
  await allow("[role] moderator opens the moderation queue", await as("mod", "GET", "/chat-message-flags"));
  await allow("[role] admin opens the moderation queue", await as("admin", "GET", "/chat-message-flags"));

  // Webhooks.
  await deny("[role] guest cannot list webhooks", await as("guest", "GET", "/chat-webhooks"), [401]);
  for (const who of ["a", "mod"]) {
    const list = await as(who, "GET", "/chat-webhooks");
    await t.check("[role] " + who + " cannot list webhooks", [403, 404, 405].includes(list.status) && idsOf(list).length === 0, brief(list));
    const make = await as(who, "POST", "/chat-webhooks", { data: { type: "chat-webhooks", attributes: { name: "nope", channelId: ids.P } } });
    await t.check("[role] " + who + " cannot create a webhook", [403, 404, 405].includes(make.status), brief(make));
    if (make.status === 201) webhooks.push(Number(make.json.data.id));
  }

  const hook = await as("admin", "POST", "/chat-webhooks", { data: { type: "chat-webhooks", attributes: { name: "E2E perm " + stamp, channelId: ids.P } } });
  await allow("[role] admin creates a webhook", hook, [201]);

  if (hook.status === 201) {
    webhooks.push(Number(hook.json.data.id));
    const key = hook.json.data.attributes.key;
    await allow("[role] admin lists webhooks", await as("admin", "GET", "/chat-webhooks"));

    const deliver = await fetch(API + "/chat/hooks/" + key, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ text: "webhook says hi " + stamp }),
    });
    await t.check("[role] anyone holding the webhook key delivers", deliver.status === 201, "HTTP " + deliver.status);

    const forged = await fetch(API + "/chat/hooks/" + "x".repeat(String(key ?? "").length || 32), {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ text: "forged" }),
    });
    await t.check("[role] a wrong webhook key is refused", forged.status === 403, "HTTP " + forged.status);

    const memberRotate = await as("a", "POST", "/chat-webhooks/" + hook.json.data.id + "/rotate");
    await deny("[role] member A cannot rotate a webhook", memberRotate, [403, 404]);
    const memberKey = await as("a", "GET", "/chat-webhooks/" + hook.json.data.id);
    await t.check("[role] member A never sees a webhook key", [403, 404].includes(memberKey.status) && !(memberKey.text ?? "").includes(key), brief(memberKey));
  }

  // Bot avatar: admin only. Never uploaded or deleted as admin here — that would
  // replace the forum's real bot avatar.
  await deny("[role] guest cannot upload the bot avatar", await multipart("guest", "/chat/bot-avatar", {}, "ramon-chat-bot", PNG), [401, 403]);
  for (const who of ["a", "mod"]) {
    await deny("[role] " + who + " cannot upload the bot avatar", await multipart(who, "/chat/bot-avatar", {}, "ramon-chat-bot", PNG), [403]);
    await deny("[role] " + who + " cannot delete the bot avatar", await as(who, "DELETE", "/chat/bot-avatar"), [403]);
  }

  // Realtime ping.
  await deny("[role] guest cannot ping", await as("guest", "POST", "/chat/realtime/ping"), [401]);
  await allow("[role] moderator pings", await as("mod", "POST", "/chat/realtime/ping"), [204]);

  // Inspecting channels unseen.
  const modPb = await as("mod", "GET", "/chat-channels/" + ids.PB);
  await t.check("[role] moderator is offered the unseen join on PB", modPb.json?.data?.attributes?.canJoinHidden === true, JSON.stringify(modPb.json?.data?.attributes?.canJoinHidden));
  const modInspect = await as("mod", "POST", "/chat-channels/" + ids.PB + "/join", { data: { attributes: { hidden: true } } });
  await allow("[role] moderator joins PB unseen (inspectChannels)", modInspect);
  await allow("[role] moderator leaves PB again", await as("mod", "POST", "/chat-channels/" + ids.PB + "/leave"), [204]);
  await deny("[role] member A cannot join P unseen (forged hidden: true)", await as("a", "POST", "/chat-channels/" + ids.P + "/join", { data: { attributes: { hidden: true } } }), [403]);

  // Pinning.
  await deny("[role] member A cannot pin", await as("a", "POST", "/chat-messages/" + ids.msgP_C + "/pin"), [403]);
  await deny("[role] moderator cannot pin (pinMessage is admin-only here)", await as("mod", "POST", "/chat-messages/" + ids.msgP_C + "/pin"), [403]);
  const pin = await as("admin", "POST", "/chat-messages/" + ids.msgP_C + "/pin");
  await t.check("[role] admin pins a message", pin.status === 200 && pin.json?.data?.attributes?.isPinned === true, brief(pin));
  const unpin = await as("admin", "POST", "/chat-messages/" + ids.msgP_C + "/pin");
  await t.check("[role] admin unpins it", unpin.status === 200 && unpin.json?.data?.attributes?.isPinned === false, brief(unpin));

  // Moving.
  await allow("setup: moderator joins S2", await as("mod", "POST", "/chat-channels/" + ids.S2 + "/join", { data: { attributes: {} } }));
  await deny("[role] guest cannot move messages", await as("guest", "POST", "/chat/messages/move", { data: { attributes: { messageIds: [ids.msgP_Cmove], channelId: ids.S2 } } }), [401]);
  await deny("[role] member A cannot move messages", await as("a", "POST", "/chat/messages/move", { data: { attributes: { messageIds: [ids.msgP_A], channelId: ids.S2 } } }), [403]);
  const move = await as("mod", "POST", "/chat/messages/move", { data: { attributes: { messageIds: [ids.msgP_Cmove], channelId: ids.S2 } } });
  await t.check("[role] moderator moves a message", move.status === 200 && move.json?.data?.attributes?.moved === 1, brief(move));

  // Deleting, restoring, purging.
  await deny("[role] member C cannot delete B's message in P", await as("c", "POST", "/chat-messages/" + ids.msgP_B + "/delete"), [403]);
  await allow("[role] B, owner of PB, deletes C's message there (manageOwnChannels)", await as("b", "POST", "/chat-messages/" + ids.msgPB_C2 + "/delete"), [204]);
  await allow("[role] moderator deletes C's message in P", await as("mod", "POST", "/chat-messages/" + ids.msgP_Cdel + "/delete"), [204]);
  await deny("[role] member B cannot restore their own deleted message", await as("b", "POST", "/chat-messages/" + ids.msgP_Bdel + "/restore"), [403]);
  await allow("[role] moderator restores a deleted message", await as("mod", "POST", "/chat-messages/" + ids.msgP_Cdel + "/restore"));
  await allow("[role] moderator deletes it again", await as("mod", "POST", "/chat-messages/" + ids.msgP_Cdel + "/delete"), [204]);
  await deny("[role] member C cannot purge their own deleted message", await as("c", "POST", "/chat-messages/" + ids.msgP_Cdel + "/purge"), [403, 404]);
  await allow("[role] moderator purges a deleted message", await as("mod", "POST", "/chat-messages/" + ids.msgP_Cdel + "/purge"), [204]);
  const editOther = await as("mod", "PATCH", "/chat-messages/" + ids.msgP_C, { data: { type: "chat-messages", id: String(ids.msgP_C), attributes: { content: "rewritten by mod" } } });
  await deny("[role] moderator cannot rewrite someone else's message", editOther, [403]);
  const adminEdit = await as("admin", "PATCH", "/chat-messages/" + ids.msgP_C, { data: { type: "chat-messages", id: String(ids.msgP_C), attributes: { content: "rewritten by admin" } } });
  await deny("[role] admin cannot rewrite someone else's message either", adminEdit, [403]);

  // Channel status and settings.
  const close = async (who, channel, status) =>
    as(who, "POST", "/chat-channels/" + channel + "/status", { data: { attributes: { status } } });
  await allow("[role] B closes their own PB", await close("b", ids.PB, "closed"));
  await allow("[role] B reopens PB", await close("b", ids.PB, "open"));
  await deny("[role] member A cannot close P", await close("a", ids.P, "closed"), [403]);
  await allow("[role] moderator closes P", await close("mod", ids.P, "closed"));
  await allow("[role] moderator reopens P", await close("mod", ids.P, "open"));

  const patchChannel = (who, channel, attributes) =>
    as(who, "PATCH", "/chat-channels/" + channel, { data: { type: "chat-channels", id: String(channel), attributes } });
  await allow("[role] B edits their own PB", await patchChannel("b", ids.PB, { description: "edited " + PRIV }));
  await deny("[role] member C cannot edit PB", await patchChannel("c", ids.PB, { description: "hijacked" }), [403]);
  await deny("[role] member A cannot edit P", await patchChannel("a", ids.P, { description: "hijacked" }), [403]);
  await allow("[role] moderator edits P", await patchChannel("mod", ids.P, { description: "edited by mod" }));

  // Channel picture.
  await deny("[role] guest cannot set a channel picture", await multipart("guest", "/chat/channels/" + ids.P + "/image", {}, "image", PNG), [401]);
  await deny("[role] member A cannot set P's picture", await multipart("a", "/chat/channels/" + ids.P + "/image", {}, "image", PNG), [403]);
  await deny("[role] member C cannot set PB's picture", await multipart("c", "/chat/channels/" + ids.PB + "/image", {}, "image", PNG), [403]);
  await allow("[role] B sets PB's picture", await multipart("b", "/chat/channels/" + ids.PB + "/image", {}, "image", PNG));
  await allow("[role] B clears PB's picture", await as("b", "DELETE", "/chat/channels/" + ids.PB + "/image"));

  // Threads, typing, drafts, invitations.
  await deny("[role] member A cannot open a thread (createThread is admin-only here)", await post("a", ids.P, "branch", { replyToId: ids.msgP_B, createThread: true }), [403]);
  await allow("[role] member A types in P", await as("a", "POST", "/chat/typing", { data: { attributes: { channelId: ids.P } } }), [204]);
  await deny("[role] moderator cannot type in P without membership", await as("mod", "POST", "/chat/typing", { data: { attributes: { channelId: ids.P } } }), [403]);
  await deny("[role] guest cannot type", await as("guest", "POST", "/chat/typing", { data: { attributes: { channelId: ids.P } } }), [401]);
  await allow("[role] member A saves a draft in P", await as("a", "POST", "/chat/drafts", { data: { attributes: { channelId: ids.P, content: "A's own draft" } } }));
  await deny("[role] member C (not the owner) cannot invite into PB", await as("c", "POST", "/chat-channels/" + ids.PB + "/members", { data: { attributes: { userIds: [idOf("a")] } } }), [403]);
  await allow("[role] member A reacts in P", await as("a", "POST", "/chat-messages/" + ids.msgP_C + "/react", { data: { attributes: { emoji: "+1" } } }));
  await deny("[role] moderator cannot react in P without membership", await as("mod", "POST", "/chat-messages/" + ids.msgP_C + "/react", { data: { attributes: { emoji: "+1" } } }), [403]);

  // Flagging and the queue.
  const flag = await as("a", "POST", "/chat-message-flags", { data: { type: "chat-message-flags", attributes: { messageId: ids.msgP_B, reason: "spam" } } });
  await allow("[role] member A flags B's message", flag, [201]);
  ids.flag = Number(flag.json?.data?.id ?? 0);
  await deny("[role] member A cannot flag their own message", await as("a", "POST", "/chat-message-flags", { data: { type: "chat-message-flags", attributes: { messageId: ids.msgP_A, reason: "spam" } } }), [403]);
  await deny("[role] guest cannot flag", await as("guest", "POST", "/chat-message-flags", { data: { type: "chat-message-flags", attributes: { messageId: ids.msgP_B, reason: "spam" } } }), [401]);
  const queue = await as("mod", "GET", "/chat-message-flags");
  await t.check("[role] the moderator's queue lists A's report", idsOf(queue).includes(ids.flag), brief(queue));

  // ══ IDOR: member A against member B's things ═══════════════════════════════
  const visibleToA = await as("a", "GET", "/chat-channels?page[limit]=50");
  await excludes("[idor] A's channel list omits B's direct conversation and private channel", visibleToA, [ids.D, ids.PB]);

  for (const [label, channel, markers] of [["direct conversation D", ids.D, [DM]], ["private channel PB", ids.PB, [PRIV]]]) {
    const m = [...markers, DRAFT];
    await deny("[idor] A GET B's " + label, await as("a", "GET", "/chat-channels/" + channel), [404], m);
    await deny("[idor] A GET B's " + label + " with includes", await as("a", "GET", "/chat-channels/" + channel + "?include=participants,lastMessage,invitedUsers"), [404], m);
    await excludes("[idor] A lists messages of B's " + label, await as("a", "GET", "/chat-messages?filter[channel]=" + channel), [ids.msgD_B, ids.msgD_C, ids.msgPB_B, ids.msgPB_C], m);
    await excludes("[idor] A lists pinned messages of B's " + label, await as("a", "GET", "/chat-messages?filter[channel]=" + channel + "&filter[pinned]=1"), [ids.msgPB_B], m);
    await excludes("[idor] A polls B's " + label + " with updatedSince", await as("a", "GET", "/chat-messages?filter[channel]=" + channel + "&filter[updatedSince]=1"), [ids.msgD_B, ids.msgPB_B], m);
    await excludes("[idor] A polls B's " + label + " with greaterThan", await as("a", "GET", "/chat-messages?filter[channel]=" + channel + "&filter[greaterThan]=0"), [ids.msgD_B, ids.msgPB_B], m);
    await deny("[idor] A marks B's " + label + " read", await as("a", "POST", "/chat-channels/" + channel + "/read", { data: { attributes: {} } }), [403, 404], m);
    await deny("[idor] A joins B's " + label, await as("a", "POST", "/chat-channels/" + channel + "/join", { data: { attributes: {} } }), [403, 404], m);
    await deny("[idor] A leaves B's " + label, await as("a", "POST", "/chat-channels/" + channel + "/leave"), [403, 404], m);
    await deny("[idor] A sets notifications on B's " + label, await as("a", "POST", "/chat-channels/" + channel + "/notifications", { data: { attributes: { muted: true } } }), [403, 404], m);
    await deny("[idor] A types into B's " + label, await as("a", "POST", "/chat/typing", { data: { attributes: { channelId: channel } } }), [403, 404], m);
    await deny("[idor] A posts into B's " + label, await post("a", channel, "intruding"), [403, 404, 422], m);
    await deny("[idor] A saves a draft against B's " + label, await as("a", "POST", "/chat/drafts", { data: { attributes: { channelId: channel, content: "x" } } }), [403, 404], m);
    await deny("[idor] A uploads into B's " + label, await multipart("a", "/chat/uploads", { channelId: channel }, "file", PNG), [403, 404, 422], m);
    await deny("[idor] A edits B's " + label, await patchChannel("a", channel, { name: "hijacked" }), [403, 404], m);
    await deny("[idor] A deletes B's " + label, await as("a", "DELETE", "/chat-channels/" + channel), [403, 404], m);
    await deny("[idor] A closes B's " + label, await close("a", channel, "closed"), [403, 404], m);
    await deny("[idor] A invites people into B's " + label, await as("a", "POST", "/chat-channels/" + channel + "/members", { data: { attributes: { userIds: [idOf("a")] } } }), [403, 404], m);
    await deny("[idor] A removes C from B's " + label, await as("a", "POST", "/chat-channels/" + channel + "/members/remove", { data: { attributes: { userId: idOf("c") } } }), [403, 404], m);
    await deny("[idor] A sets the picture of B's " + label, await multipart("a", "/chat/channels/" + channel + "/image", {}, "image", PNG), [403, 404], m);
    await excludes("[idor] A lists the members of B's " + label + " via users filter[chatChannel]", await as("a", "GET", "/users?filter[chatChannel]=" + channel), [idOf("b"), idOf("c")], m);
    await excludes("[idor] A lists the threads of B's " + label, await as("a", "GET", "/chat-threads?filter[channel]=" + channel), [ids.TPB], m);
  }

  const messageProbe = async (label, message, markers) => {
    const m = [...markers, DRAFT];
    await deny("[idor] A GET " + label, await as("a", "GET", "/chat-messages/" + message), [404], m);
    await deny("[idor] A reads revisions of " + label, await as("a", "GET", "/chat-messages/" + message + "/revisions"), [403, 404], m);
    await deny("[idor] A reacts to " + label, await as("a", "POST", "/chat-messages/" + message + "/react", { data: { attributes: { emoji: "+1" } } }), [403, 404], m);
    await deny("[idor] A bookmarks " + label, await as("a", "POST", "/chat-messages/" + message + "/bookmark"), [403, 404], m);
    await deny("[idor] A flags " + label, await as("a", "POST", "/chat-message-flags", { data: { type: "chat-message-flags", attributes: { messageId: message, reason: "spam" } } }), [403, 404], m);
    await deny("[idor] A quotes " + label + " from P (replyToId across channels)", await post("a", ids.P, "quoting", { replyToId: message }), [403, 404, 422], m);
    await deny("[idor] A opens a thread on " + label, await post("a", ids.P, "branching", { replyToId: message, createThread: true }), [403, 404, 422], m);
    await deny("[idor] A transcribes " + label, await as("a", "POST", "/chat/transcript", { data: { attributes: { messageIds: [message] } } }), [403, 404, 422], m);
    await deny("[idor] A moves " + label + " into P", await as("a", "POST", "/chat/messages/move", { data: { attributes: { messageIds: [message], channelId: ids.P } } }), [403, 404, 422], m);
    await deny("[idor] A pins " + label, await as("a", "POST", "/chat-messages/" + message + "/pin"), [403, 404], m);
    await deny("[idor] A deletes " + label, await as("a", "POST", "/chat-messages/" + message + "/delete"), [403, 404], m);
    await deny("[idor] A edits " + label, await as("a", "PATCH", "/chat-messages/" + message, { data: { type: "chat-messages", id: String(message), attributes: { content: "hijacked" } } }), [403, 404], m);
  };

  await messageProbe("B's DM message", ids.msgD_B, [DM]);
  await messageProbe("B's PB message", ids.msgPB_B, [PRIV]);

  await deny("[idor] A moves their own P message into B's PB", await as("a", "POST", "/chat/messages/move", { data: { attributes: { messageIds: [ids.msgP_A], channelId: ids.PB } } }), [403, 404, 422]);
  await deny("[idor] A moves their own P message into B's D", await as("a", "POST", "/chat/messages/move", { data: { attributes: { messageIds: [ids.msgP_A], channelId: ids.D } } }), [403, 404, 422]);
  await excludes("[idor] A lists messages of the PB thread", await as("a", "GET", "/chat-messages?filter[thread]=" + ids.TPB), [ids.msgPB_B], [PRIV]);
  await deny("[idor] A GET the PB thread", await as("a", "GET", "/chat-threads/" + ids.TPB), [404], [PRIV]);
  await deny("[idor] A marks the PB thread read", await as("a", "POST", "/chat-threads/" + ids.TPB + "/read", { data: { attributes: {} } }), [403, 404], [PRIV]);
  await deny("[idor] A renames the PB thread", await as("a", "PATCH", "/chat-threads/" + ids.TPB, { data: { type: "chat-threads", id: String(ids.TPB), attributes: { title: "hijacked" } } }), [403, 404], [PRIV]);

  // Searches without a channel: nothing of B's private or direct world.
  const everywhere = [ids.msgD_B, ids.msgD_C, ids.msgPB_B, ids.msgPB_C, ids.msgD_up, ids.msgPB_up];
  await excludes("[idor] A searches for the DM text", await as("a", "GET", "/chat-messages?filter[q]=" + DM), everywhere);
  await excludes("[idor] A searches for the PB text", await as("a", "GET", "/chat-messages?filter[q]=" + PRIV), everywhere);
  await excludes("[idor] A lists every pinned message", await as("a", "GET", "/chat-messages?filter[pinned]=1"), everywhere);
  await excludes("[idor] A polls everything with updatedSince", await as("a", "GET", "/chat-messages?filter[updatedSince]=1&page[limit]=100"), everywhere);
  await excludes("[idor] A polls everything with greaterThan", await as("a", "GET", "/chat-messages?filter[greaterThan]=" + (ids.msgP_B - 1) + "&page[limit]=100"), everywhere);
  const bookmarks = await as("a", "GET", "/chat-messages?filter[bookmarked]=1");
  await excludes("[idor] A's bookmark list holds none of B's bookmarks", bookmarks, [ids.msgP_C, ids.msgPB_C]);
  const cMsg = await as("a", "GET", "/chat-messages/" + ids.msgP_C);
  await t.check("[idor] B's bookmark does not show as A's (isBookmarked)", cMsg.status === 200 && cMsg.json?.data?.attributes?.isBookmarked === false, brief(cMsg));

  // A reply in P quoting a PB message: included replyTo must not carry it.
  const quoted = await as("a", "GET", "/chat-messages/" + ids.msgP_reply + "?include=replyTo,replyTo.user,replyTo.channel");
  const includedIds = (quoted.json?.included ?? []).filter((r) => r.type === "chat-messages").map((r) => Number(r.id));
  const includedChannels = (quoted.json?.included ?? []).filter((r) => r.type === "chat-channels").map((r) => Number(r.id));
  await t.check(
    "[idor] include=replyTo on a P reply does not carry an original since moved into PB",
    quoted.status === 200 && !includedIds.includes(ids.msgP_orig) && !includedChannels.includes(ids.PB) && markersIn(quoted.text, [PRIV, MOVED]).length === 0,
    brief(quoted) + " included messages " + JSON.stringify(includedIds) + " channels " + JSON.stringify(includedChannels) + (markersIn(quoted.text, [PRIV, MOVED]).length ? " LEAKED" : ""),
  );
  const pList = await as("a", "GET", "/chat-messages?filter[channel]=" + ids.P + "&page[limit]=100");
  await t.check("[idor] P's message list (default includes) carries nothing from PB or D", pList.status === 200 && markersIn(pList.text, [PRIV, DM, MOVED]).length === 0, "HTTP " + pList.status + (markersIn(pList.text, [PRIV, DM, MOVED]).length ? " LEAKED " + markersIn(pList.text, [PRIV, DM, MOVED]).join(",") : ""));
  await deny("[idor] A GET the moved original by id", await as("a", "GET", "/chat-messages/" + ids.msgP_orig), [404]);

  // Transcript mixing a visible and an invisible message.
  const mixed = await as("a", "POST", "/chat/transcript", { data: { attributes: { messageIds: [ids.msgP_A, ids.msgPB_B, ids.msgD_B] } } });
  await t.check(
    "[idor] a transcript mixing visible and private ids renders only the visible one",
    mixed.status === 200 && mixed.json?.data?.attributes?.count === 1 && markersIn(mixed.text, [PRIV, DM]).length === 0,
    brief(mixed),
  );

  // Invitations that belong to somebody else.
  await deny("[idor] A accepts the suspended user's pending invite to PB", await as("a", "POST", "/chat/invites/" + ids.PB + "/accept"), [403, 404], [PRIV]);
  await deny("[idor] A declines the suspended user's pending invite to PB", await as("a", "POST", "/chat/invites/" + ids.PB + "/decline"), [403, 404], [PRIV]);
  await deny("[idor] A cancels the suspended user's pending invite to PB", await as("a", "POST", "/chat-channels/" + ids.PB + "/invites/cancel", { data: { attributes: { userId: idOf("susp") } } }), [403, 404], [PRIV]);
  await deny("[idor] A accepts an invite to D that does not exist", await as("a", "POST", "/chat/invites/" + ids.D + "/accept"), [403, 404], [DM]);

  // Message-level, on B's public message A *can* see.
  const bMsg = (suffix, method = "POST", body) => as("a", method, "/chat-messages/" + ids.msgP_B + suffix, body);
  await deny("[idor] A edits B's public message", await bMsg("", "PATCH", { data: { type: "chat-messages", id: String(ids.msgP_B), attributes: { content: "hijacked" } } }), [403]);
  await deny("[idor] A deletes B's public message", await bMsg("/delete"), [403]);
  await deny("[idor] A pins B's public message", await bMsg("/pin"), [403]);
  await deny("[idor] A purges B's public message", await bMsg("/purge"), [403]);
  await deny("[idor] A restores B's public message", await bMsg("/restore"), [403]);
  const revisions = await bMsg("/revisions", "GET");
  await t.check(
    "[idor] A reads revisions of B's visible message (allowed: viewRevisions follows view)",
    revisions.status === 200,
    brief(revisions),
  );
  await deny("[idor] A reads revisions of B's deleted message", await as("a", "GET", "/chat-messages/" + ids.msgP_Bdel + "/revisions"), [403, 404]);
  await deny("[idor] A GET B's deleted message", await as("a", "GET", "/chat-messages/" + ids.msgP_Bdel), [404]);
  const bdelInList = await as("a", "GET", "/chat-messages?filter[channel]=" + ids.P + "&page[limit]=100");
  await t.check("[idor] B's deleted message is absent from A's channel list", !idsOf(bdelInList).includes(ids.msgP_Bdel) && !(bdelInList.text ?? "").includes("B original " + stamp), "HTTP " + bdelInList.status);

  // Reacting "as B": the server must attribute the reaction to A whatever the body says.
  const forgedReact = await as("a", "POST", "/chat-messages/" + ids.msgP_B + "/react", { data: { attributes: { emoji: "tada", userId: idOf("b") } } });
  await allow("setup: A reacts to B's message (body claims userId B)", forgedReact);
  const bView = await as("b", "GET", "/chat-messages/" + ids.msgP_B);
  const bReacted = bView.json?.data?.attributes?.reactionSummary?.["tada"]?.reacted;
  await t.check("[idor] a reaction forged with userId B is A's, not B's", bReacted === false, JSON.stringify(bView.json?.data?.attributes?.reactionSummary));
  await as("a", "POST", "/chat-messages/" + ids.msgP_B + "/react", { data: { attributes: { emoji: "tada" } } });

  // Flags.
  await deny("[idor] A resolves the flag they filed", await as("a", "POST", "/chat-message-flags/" + ids.flag + "/resolve"), [403, 404]);
  await deny("[idor] A reads a flag by id", await as("a", "GET", "/chat-message-flags/" + ids.flag), [403, 404]);
  await deny("[idor] A lists unresolved flags", await as("a", "GET", "/chat-message-flags?filter[resolved]=0"), [403]);
  await deny("[idor] B (the reported author) cannot read the queue", await as("b", "GET", "/chat-message-flags"), [403]);
  await allow("[role] moderator resolves A's flag", await as("mod", "POST", "/chat-message-flags/" + ids.flag + "/resolve"));

  // Drafts.
  const aDrafts = await as("a", "GET", "/chat/drafts");
  await t.check("[idor] A's drafts hold none of B's", aDrafts.status === 200 && markersIn(aDrafts.text, [DRAFT]).length === 0, brief(aDrafts));
  const bDrafts = await as("b", "GET", "/chat/drafts");
  await t.check("[idor] B's drafts are untouched by A's draft writes", markersIn(bDrafts.text, [DRAFT]).length === 1 && (bDrafts.json?.data ?? []).length >= 2, brief(bDrafts));

  // Uploads.
  for (const [label, up, status, markers] of [
    ["PB attachment", upPB, [404], [PRIV]],
    ["D attachment", upD, [404], [DM]],
    ["pending upload", upPending, [404], []],
  ]) {
    await deny("[idor] A downloads B's " + label, await as("a", "GET", "/chat/uploads/" + up.id + "/file"), status, markers);
    await deny("[idor] A GET B's " + label + " record", await as("a", "GET", "/chat-uploads/" + up.id), [403, 404], markers);
    await deny("[idor] A deletes B's " + label, await as("a", "DELETE", "/chat-uploads/" + up.id), [403, 404], markers);
    const steal = await post("a", ids.P, "stealing an upload", { uploadIds: [up.id] });
    const carried = (steal.json?.data?.relationships?.uploads?.data ?? []).map((r) => Number(r.id));
    await t.check(
      "[idor] A attaching B's " + label + " to their own message gets nothing attached",
      (steal.status >= 400 && steal.status < 500) || (steal.status === 201 && !carried.includes(up.id)),
      brief(steal) + " uploads " + JSON.stringify(carried),
    );
    await deny("[idor] guest downloads B's " + label, await as("guest", "GET", "/chat/uploads/" + up.id + "/file"), [401, 404], markers);
  }

  const cFile = await as("c", "GET", "/chat/uploads/" + upPB.id + "/file");
  await t.check("[idor] C, a PB member, downloads the PB attachment", cFile.status === 200, "HTTP " + cFile.status);

  const stolen = await as("admin", "GET", "/chat-messages?filter[channel]=" + ids.P + "&filter[q]=stealing&include=uploads");
  const stolenUploads = (stolen.json?.included ?? []).filter((r) => r.type === "chat-uploads").map((r) => Number(r.id));
  await t.check("[idor] no message of A's carries B's uploads", ![upPB.id, upD.id, upPending.id].some((id) => stolenUploads.includes(id)), JSON.stringify(stolenUploads));

  const pAttachment = await as("a", "GET", "/chat/uploads/" + upP.id + "/file");
  await t.check("[role] A downloads the P attachment (public channel)", pAttachment.status === 200, "HTTP " + pAttachment.status);

  // Unread counters and notifications.
  const bUser = await as("a", "GET", "/users/" + idOf("b"));
  const bAttrs = bUser.json?.data?.attributes ?? {};
  await t.check(
    "[idor] A cannot read B's chat unread counters",
    bUser.status === 200 && !("chatUnreadChannelsCount" in bAttrs) && !("chatUnreadMessagesCount" in bAttrs) && !("chatUnreadMentionsCount" in bAttrs),
    JSON.stringify(Object.keys(bAttrs).filter((k) => k.startsWith("chat"))),
  );
  const self = await as("b", "GET", "/users/" + idOf("b"));
  await t.check("[idor] B reads their own unread counters", "chatUnreadChannelsCount" in (self.json?.data?.attributes ?? {}), brief(self));
  await t.check("[idor] A cannot read B's email", !("email" in bAttrs), JSON.stringify(Object.keys(bAttrs)));

  await sleep(1500);
  const aNotifications = await as("a", "GET", "/notifications?page[limit]=50");
  await t.check("[idor] A's notifications hold nothing from PB or D (mention by B in PB included)", markersIn(aNotifications.text, [PRIV, DM]).length === 0, "HTTP " + aNotifications.status);

  // Mass assignment.
  const forgedCreate = await post("a", ids.P, "forged create " + stamp, { userId: idOf("b"), type: "system", systemKey: "x", pinnedAt: new Date().toISOString(), isPinned: true });
  const fc = forgedCreate.json?.data;
  await t.check(
    "[mass] a message created with userId/type/pin fields is A's plain text message",
    forgedCreate.status >= 400 ||
      (Number(fc?.relationships?.user?.data?.id) === idOf("a") && fc?.attributes?.type === "text" && fc?.attributes?.isPinned === false),
    brief(forgedCreate),
  );

  const forgedRel = await as("a", "POST", "/chat-messages", {
    data: {
      type: "chat-messages",
      attributes: { content: "forged rel " + stamp, channelId: ids.P },
      relationships: { user: { data: { type: "users", id: String(idOf("b")) } }, channel: { data: { type: "chat-channels", id: String(ids.PB) } } },
    },
  });
  await sleep(650);
  await t.check(
    "[mass] a message created with user/channel relationships stays A's, in P",
    forgedRel.status >= 400 ||
      (Number(forgedRel.json?.data?.relationships?.user?.data?.id) === idOf("a") && Number(forgedRel.json?.data?.attributes?.channelId) === ids.P),
    brief(forgedRel),
  );

  const forgedPatch = await as("a", "PATCH", "/chat-messages/" + ids.msgP_A, {
    data: {
      type: "chat-messages",
      id: String(ids.msgP_A),
      attributes: { content: "A edited " + stamp, userId: idOf("b"), channelId: ids.PB, pinnedAt: new Date().toISOString(), isPinned: true, type: "system", systemKey: "x" },
      relationships: { user: { data: { type: "users", id: String(idOf("b")) } } },
    },
  });
  const afterPatch = await as("admin", "GET", "/chat-messages/" + ids.msgP_A);
  const ap = afterPatch.json?.data;
  await t.check(
    "[mass] PATCHing own message with userId/channelId/pin/type changes none of them",
    Number(ap?.relationships?.user?.data?.id) === idOf("a") && Number(ap?.attributes?.channelId) === ids.P && ap?.attributes?.isPinned === false && ap?.attributes?.type === "text",
    "patch " + brief(forgedPatch) + " | now user=" + ap?.relationships?.user?.data?.id + " channel=" + ap?.attributes?.channelId + " pinned=" + ap?.attributes?.isPinned + " type=" + ap?.attributes?.type,
  );

  const ac = await as("a", "POST", "/chat-channels", {
    data: { type: "chat-channels", attributes: { type: "category", name: "E2E perm AC " + stamp, creatorId: idOf("b"), messagesCount: 999, userCount: 999 } },
  });
  if (ac.status === 201) apiChannels.push(Number(ac.json.data.id));
  await t.check(
    "[mass] a channel created with creatorId/counters is A's with real counters",
    ac.status >= 400 || (Number(ac.json?.data?.attributes?.creatorId) === idOf("a") && ac.json?.data?.attributes?.messagesCount !== 999),
    brief(ac),
  );

  const acPlain = ac.status === 201 ? ac : await as("a", "POST", "/chat-channels", { data: { type: "chat-channels", attributes: { type: "category", name: "E2E perm AC " + stamp } } });
  if (acPlain !== ac && acPlain.status === 201) apiChannels.push(Number(acPlain.json.data.id));
  ids.AC = Number(acPlain.json?.data?.id ?? 0);

  if (ids.AC) {
    const acPatch = await patchChannel("a", ids.AC, { type: "direct", creatorId: idOf("b"), status: "archived", slug: "hijack" });
    const acNow = await as("admin", "GET", "/chat-channels/" + ids.AC);
    const acAttrs = acNow.json?.data?.attributes ?? {};
    await t.check(
      "[mass] PATCHing own channel with type/creatorId/status changes none of them",
      acAttrs.type === "category" && Number(acAttrs.creatorId) === idOf("a") && acAttrs.status === "open",
      "patch " + brief(acPatch) + " | now type=" + acAttrs.type + " creator=" + acAttrs.creatorId + " status=" + acAttrs.status,
    );
  }

  const forgedDirect = await as("a", "POST", "/chat-channels", {
    data: { type: "chat-channels", attributes: { type: "direct", name: "forged direct " + stamp } },
  });
  if (forgedDirect.json?.data?.id && forgedDirect.status === 201) purgeOnly.push(Number(forgedDirect.json.data.id));
  await t.check(
    "[mass] creating a channel with type=direct through the channel endpoint is refused",
    forgedDirect.status >= 400 && forgedDirect.status < 500,
    brief(forgedDirect) + (forgedDirect.status === 201 ? " type=" + forgedDirect.json?.data?.attributes?.type : ""),
  );

  if (forgedDirect.status === 201) {
    // What a forged direct channel is worth: A holds no startDirect, so A must
    // not be able to pull someone into a private conversation through it.
    const forgedId = Number(forgedDirect.json.data.id);
    const pull = await as("a", "POST", "/chat-channels/" + forgedId + "/members", { data: { attributes: { userIds: [idOf("b")] } } });
    await t.check(
      "[mass] A cannot invite B into the forged direct channel (A lacks startDirect)",
      pull.status === 403 || pull.status === 404,
      brief(pull),
    );
  }

  const forgedType = await as("a", "POST", "/chat-channels", {
    data: { type: "chat-channels", attributes: { type: "bogus", name: "forged type " + stamp } },
  });
  if (forgedType.json?.data?.id && forgedType.status === 201) purgeOnly.push(Number(forgedType.json.data.id));
  await t.check(
    "[mass] creating a channel with an unknown type is refused",
    forgedType.status >= 400 && forgedType.status < 500,
    brief(forgedType),
  );

  // Realtime.
  const wsAuth = (who, channelName) =>
    as(who, "POST", "/websocket/auth", { socket_id: "1234.5678", channel_name: channelName });
  await deny("[idor] A cannot subscribe to B's private websocket channel", await wsAuth("a", "private-user=" + idOf("b")), [403]);
  await allow("[idor] A may subscribe to their own private websocket channel", await wsAuth("a", "private-user=" + idOf("a")));
  await deny("[idor] guest cannot subscribe to B's private websocket channel", await wsAuth("guest", "private-user=" + idOf("b")), [401, 403]);
  await deny("[idor] A cannot subscribe to B's channel with a padded id", await wsAuth("a", "private-user=0" + idOf("b")), [403]);

  if (sockets.a && sockets.b) {
    await sleep(3200);
    const pingAt = Date.now();
    const ping = await as("a", "POST", "/chat/realtime/ping");
    await allow("[role] member A pings", ping, [204]);
    let pong = await sockets.a.waitFor("ramonChat.pong", (d) => true, 4000);

    if (!pong) {
      // The ping is throttled per user for three seconds; one retry past that
      // tells a lost push from a slow one.
      await sleep(3200);
      await as("a", "POST", "/chat/realtime/ping");
      pong = await sockets.a.waitFor("ramonChat.pong", () => true, 4000);
    }

    await t.check("[idor] A's ping is answered on A's socket", Boolean(pong) && pong.at >= pingAt, pong ? "pong" : "no pong; A's socket saw " + sockets.a.events.length + " events: " + [...new Set(sockets.a.events.map((e) => e.event))].join(","));
    await sleep(1000);
    const strayPong = sockets.b.events.find((e) => e.event === "ramonChat.pong" && e.at >= pingAt);
    await t.check("[idor] A's ping is not pushed to B", !strayPong, strayPong ? "B got a pong" : "");

    const aLeak = sockets.a.events.filter((e) => markersIn(JSON.stringify(e.data), [PRIV, DM, DRAFT]).length > 0);
    await t.check("[idor] A's websocket never carried anything from PB or D", aLeak.length === 0, aLeak.map((e) => e.event).join(","));
    const bGot = sockets.b.events.some((e) => JSON.stringify(e.data).includes(String(ids.PB)));
    await t.check("sanity: B's websocket did receive PB traffic", bGot, sockets.b.events.length + " events");
  }

  // ── Guest variants ──────────────────────────────────────────────────────────
  for (const [label, path, method, body] of [
    ["GET D", "/chat-channels/" + ids.D, "GET"],
    ["GET PB", "/chat-channels/" + ids.PB, "GET"],
    ["list D messages", "/chat-messages?filter[channel]=" + ids.D, "GET"],
    ["list PB messages", "/chat-messages?filter[channel]=" + ids.PB, "GET"],
    ["search the PB text", "/chat-messages?filter[q]=" + PRIV, "GET"],
    ["GET a PB message", "/chat-messages/" + ids.msgPB_B, "GET"],
    ["GET a P message", "/chat-messages/" + ids.msgP_B, "GET"],
    ["read revisions of a P message", "/chat-messages/" + ids.msgP_B + "/revisions", "GET"],
    ["GET the PB thread", "/chat-threads/" + ids.TPB, "GET"],
    ["transcribe PB and D", "/chat/transcript", "POST", { data: { attributes: { messageIds: [ids.msgPB_B, ids.msgD_B] } } }],
    ["mark D read", "/chat-channels/" + ids.D + "/read", "POST", { data: { attributes: {} } }],
    ["join P", "/chat-channels/" + ids.P + "/join", "POST", { data: { attributes: {} } }],
    ["accept a PB invite", "/chat/invites/" + ids.PB + "/accept", "POST"],
    ["read drafts", "/chat/drafts", "GET"],
    ["save a draft", "/chat/drafts", "POST", { data: { attributes: { channelId: ids.P, content: "x" } } }],
    ["react", "/chat-messages/" + ids.msgP_B + "/react", "POST", { data: { attributes: { emoji: "+1" } } }],
    ["edit B's message", "/chat-messages/" + ids.msgP_B, "PATCH", { data: { type: "chat-messages", id: String(ids.msgP_B), attributes: { content: "x" } } }],
    ["delete B's message", "/chat-messages/" + ids.msgP_B + "/delete", "POST"],
    ["pin B's message", "/chat-messages/" + ids.msgP_B + "/pin", "POST"],
    ["list users of PB", "/users?filter[chatChannel]=" + ids.PB, "GET"],
    ["GET a pending upload", "/chat-uploads/" + upPending.id, "GET"],
    ["resolve a flag", "/chat-message-flags/" + ids.flag + "/resolve", "POST"],
  ]) {
    const r = await as("guest", method, path, body);
    const leakedIds = idsOf(r).filter((id) => everywhere.includes(id) || id === idOf("b") || id === idOf("c"));
    await t.check(
      "[guest] " + label,
      [401, 403, 404].includes(r.status) || refusedByCsrf(r) || (r.status === 200 && idsOf(r).length === 0 && leakedIds.length === 0),
      brief(r),
    );
  }

  // ── Whole-transcript sweep ──────────────────────────────────────────────────
  const forbidden = {
    a: [PRIV, DM, DRAFT, MOVED],
    guest: [PRIV, DM, DRAFT, MOVED],
    susp: [PRIV, DM, DRAFT, MOVED],
    unconf: [PRIV, DM, DRAFT, MOVED],
    mod: [DM, DRAFT],
    admin: [DM, DRAFT],
    c: [DRAFT],
  };

  for (const [who, markers] of Object.entries(forbidden)) {
    const hits = transcript[who].filter((entry) => markersIn(entry.text, markers).length > 0);
    await t.check(
      "[sweep] no response to " + who + " carried " + markers.map((m) => m.replace(stamp, "")).join("/"),
      hits.length === 0,
      hits.slice(0, 5).map((h) => h.label + " -> " + markersIn(h.text, markers).map((m) => m.replace(stamp, "")).join(",")).join(" | "),
    );
  }

  for (const who of ACTORS) {
    const leaks = [];

    for (const entry of transcript[who]) {
      const rows = [entry.json?.data, ...(entry.json?.included ?? [])].flat().filter(Boolean);

      for (const row of rows) {
        if (row?.type === "users" && Number(row.id) !== idOf(who) && row.attributes && "email" in row.attributes) {
          leaks.push(entry.label + " -> user " + row.id);
        }
      }
    }

    if (who === "admin") continue;

    await t.check("[sweep] no response to " + who + " carried another user's email", leaks.length === 0, leaks.slice(0, 5).join(" | "));
  }
} catch (e) {
  await t.fail("suite aborted", e.stack?.split("\n").slice(0, 3).join(" ") ?? String(e));
} finally {
  if (startDirectChanged && startDirectOriginal) {
    const r = await setPermissionGroups("ramon-chat.startDirect", startDirectOriginal);
    log("startDirect restored in finally: HTTP " + r.status);
  }

  for (const channel of [ids.PB, ids.D].filter(Boolean)) {
    await api(tokens.b.token, "POST", "/chat/drafts", { data: { attributes: { channelId: channel, content: null } } });
  }

  await api(tokens.a.token, "POST", "/chat/drafts", { data: { attributes: { channelId: ids.P, content: null } } });

  for (const { who, id } of pendingUploads) {
    await api(tokens[who].token, "DELETE", "/chat-uploads/" + id);
  }

  if (ids.PB) {
    await api(tokens.b.token, "POST", "/chat-channels/" + ids.PB + "/invites/cancel", { data: { attributes: { userId: tokens.susp.id } } });
  }

  for (const id of webhooks) {
    const r = await api(tokens.admin.token, "DELETE", "/chat-webhooks/" + id);
    log("webhook " + id + " deleted: HTTP " + r.status);
  }

  for (const id of apiChannels) {
    const r = await deleteChannel(tokens.admin.token, id);
    log("channel " + id + " deleted: HTTP " + r.status);

    if (r.status !== 204) purgeOnly.push(id);
  }

  log(await purgeChannels(purgeOnly));

  for (const socket of Object.values(sockets)) socket.close();
}

process.exit(t.summary());
