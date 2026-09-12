#!/usr/bin/env node
/**
 * Invitations, end to end against the local forum, with the realtime channel
 * of every party open so each push is proved to arrive live.
 *
 *   admin invites B into a private channel
 *     -> B gets the invitation (notification + websocket), cannot see the channel
 *     -> B declines: owner is told (notification + websocket), channel stays hidden
 *   admin invites B again
 *     -> B accepts over the invite route: membership, capability flags, sidebar push
 *     -> the room hears "B joined, invited by admin" over the websocket
 *   admin invites C and withdraws it
 *     -> C's notification is gone, C cannot accept
 *   the join endpoint answers with the channel (the "looks closed" regression)
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php).
 */
import {
  acceptInvite,
  api,
  cancelInvite,
  createChannel,
  declineInvite,
  deleteChannel,
  forum,
  inviteMembers,
  joinChannel,
  notifications,
  notificationsAbout,
  showChannel,
} from "./lib/api.mjs";
import { harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { subscribeUser } from "./lib/realtime.mjs";

const t = harness("invite-flow");
const tokens = await loadTokens();
const { admin, b, c } = tokens;

const forumDoc = await forum(admin.token);
await t.must("forum reachable", forumDoc.status === 200, "HTTP " + forumDoc.status);

const attrs = forumDoc.json.data.attributes;
const websocket = {
  key: attrs["websocket.key"],
  host: attrs["websocket.host"],
  port: attrs["websocket.port"],
  secure: attrs["websocket.secure"] === "1" || attrs["websocket.secure"] === true,
};

await t.must("websocket settings present", websocket.key && websocket.host, JSON.stringify(websocket));

const sockets = {};

try {
  sockets.admin = await subscribeUser({ websocket, token: admin.token, userId: admin.id, label: "admin" });
  sockets.b = await subscribeUser({ websocket, token: b.token, userId: b.id, label: "B" });
  sockets.c = await subscribeUser({ websocket, token: c.token, userId: c.id, label: "C" });
} catch (e) {
  await t.fail("websocket subscriptions", String(e.message));
  process.exit(t.summary());
}

const stamp = Date.now().toString(36);
let channelId = null;

try {
  // ── A private channel owned by the admin ────────────────────────────────────
  const created = await createChannel(admin.token, {
    name: "E2E invites " + stamp,
    description: "created by tests/E2E/invite-flow.mjs",
    isPrivate: true,
  });

  await t.must("admin creates a private channel", created.status === 201, "HTTP " + created.status + " " + created.text.slice(0, 200));

  channelId = Number(created.json.data.id);
  log("channel " + channelId);

  const hiddenBefore = await showChannel(b.token, channelId);
  await t.check("B cannot see the private channel before any invite", hiddenBefore.status === 404, "HTTP " + hiddenBefore.status);

  // ── Invite B ────────────────────────────────────────────────────────────────
  const invited = await inviteMembers(admin.token, channelId, [b.id]);
  await t.must("admin invites B", invited.status === 200, "HTTP " + invited.status + " " + invited.text.slice(0, 200));

  const pending = (invited.json.data.relationships?.invitedUsers?.data ?? []).map((r) => Number(r.id));
  await t.check("the response lists B as pending", pending.includes(b.id), JSON.stringify(pending));

  const members = (invited.json.data.relationships?.participants?.data ?? []).map((r) => Number(r.id));
  await t.check("B is not a member yet", !members.includes(b.id), JSON.stringify(members));

  const invitedPush = await sockets.b.waitFor(
    "ramonChat.membership",
    (d) => d.channelId === channelId && d.action === "invited",
  );
  await t.check("B receives the invitation over the websocket", Boolean(invitedPush), invitedPush ? "actor " + invitedPush.data.actorName : "no push within 8s");

  const notifyPush = await sockets.b.waitFor("notification", () => true, 8000);
  await t.check(
    "B receives the notification toast over the websocket",
    Boolean(notifyPush) && JSON.stringify(notifyPush.data).includes("chatChannelInvite"),
    notifyPush ? "type in payload" : "no notification push within 8s",
  );

  const bNotifications = await notifications(b.token);
  const inviteRows = notificationsAbout(bNotifications.json, "chatChannelInvite", channelId);
  await t.check("B has the invitation in the bell", inviteRows.length === 1, inviteRows.length + " rows (HTTP " + bNotifications.status + ")");
  await t.check("the invitation names the channel", inviteRows[0]?.attributes?.content?.channelName === "E2E invites " + stamp);

  const seen = await showChannel(b.token, channelId, "lastMessage");
  await t.check("B sees the invited channel's row", seen.status === 200, "HTTP " + seen.status);

  const seenAttrs = seen.json?.data?.attributes ?? {};
  await t.check(
    "the row says B is invited and may not post yet",
    seenAttrs.isInvited === true && seenAttrs.canPostMessage === false && seenAttrs.invitedById === admin.id,
    JSON.stringify({ isInvited: seenAttrs.isInvited, canPostMessage: seenAttrs.canPostMessage, invitedById: seenAttrs.invitedById }),
  );

  const stream = await api(b.token, "GET", "/chat-messages?filter[channel]=" + channelId);
  await t.check("B cannot read the private channel's messages while invited", stream.status === 200 && (stream.json?.data ?? []).length === 0, "HTTP " + stream.status + " rows " + (stream.json?.data ?? []).length);

  // ── B declines ──────────────────────────────────────────────────────────────
  const declined = await declineInvite(b.token, channelId);
  await t.must("B declines", declined.status === 204, "HTTP " + declined.status + " " + declined.text.slice(0, 200));

  const declinedPush = await sockets.admin.waitFor(
    "ramonChat.membership",
    (d) => d.channelId === channelId && d.action === "invite_declined",
  );
  await t.check("the inviter hears the refusal over the websocket", Boolean(declinedPush), declinedPush ? "user " + declinedPush.data.username : "no push within 8s");

  const adminNotifications = await notifications(admin.token);
  const declinedRows = notificationsAbout(adminNotifications.json, "chatChannelInviteDeclined", channelId);
  await t.check("the owner is notified of the refusal", declinedRows.length === 1, declinedRows.length + " rows");

  const bAfterDecline = notificationsAbout((await notifications(b.token)).json, "chatChannelInvite", channelId);
  await t.check("the invitation left B's bell", bAfterDecline.length === 0, bAfterDecline.length + " rows");

  const hiddenAfter = await showChannel(b.token, channelId);
  await t.check("the channel stays hidden from B", hiddenAfter.status === 404, "HTTP " + hiddenAfter.status);

  const acceptGone = await acceptInvite(b.token, channelId);
  await t.check("a declined invitation cannot be accepted", acceptGone.status === 404, "HTTP " + acceptGone.status);

  // ── Invite B again, B accepts ───────────────────────────────────────────────
  const again = await inviteMembers(admin.token, channelId, [b.id]);
  await t.must("admin invites B again", again.status === 200, "HTTP " + again.status);

  await sockets.b.waitFor(
    "ramonChat.membership",
    (d) => d.channelId === channelId && d.action === "invited" && d.at !== invitedPush?.at,
    5000,
  );

  const accepted = await acceptInvite(b.token, channelId);
  await t.must("B accepts", accepted.status === 200, "HTTP " + accepted.status + " " + accepted.text.slice(0, 200));

  const a = accepted.json.data.attributes;
  await t.check("the accept answers with the channel as a member sees it", a.isFollowing === true && a.canPostMessage === true && a.isInvited === false, JSON.stringify({ isFollowing: a.isFollowing, canPostMessage: a.canPostMessage, isInvited: a.isInvited }));

  const joinedPushB = await sockets.b.waitFor(
    "ramonChat.membership",
    (d) => d.channelId === channelId && d.action === "joined" && d.userId === b.id,
  );
  await t.check("B's other tabs hear the join", Boolean(joinedPushB), joinedPushB ? "" : "no push within 8s");

  const joinedPushAdmin = await sockets.admin.waitFor(
    "ramonChat.membership",
    (d) => d.channelId === channelId && d.action === "joined" && d.userId === b.id,
  );
  await t.check("the room hears the join", Boolean(joinedPushAdmin), joinedPushAdmin ? "userCount " + joinedPushAdmin.data.userCount : "no push within 8s");

  const systemPush = await sockets.admin.waitFor(
    "ramonChat.message",
    (d) => d.channelId === channelId && d.systemKey === "user_accepted_invite",
  );
  await t.check("the acceptance is narrated live in the channel", Boolean(systemPush), systemPush ? JSON.stringify(systemPush.data.systemData) : "no system message within 8s");

  const visible = await showChannel(b.token, channelId, "participants");
  await t.check("B can now read the channel", visible.status === 200, "HTTP " + visible.status);

  const bAfterAccept = notificationsAbout((await notifications(b.token)).json, "chatChannelInvite", channelId);
  await t.check("the accepted invitation left B's bell", bAfterAccept.length === 0, bAfterAccept.length + " rows");

  const reinvite = await inviteMembers(admin.token, channelId, [b.id]);
  await t.check("inviting a member is a no-op", reinvite.status === 200 && !(reinvite.json.data.relationships?.invitedUsers?.data ?? []).some((r) => Number(r.id) === b.id));

  // ── Invite C, withdraw it ───────────────────────────────────────────────────
  const inviteC = await inviteMembers(admin.token, channelId, [c.id]);
  await t.must("admin invites C", inviteC.status === 200, "HTTP " + inviteC.status);

  await sockets.c.waitFor("ramonChat.membership", (d) => d.channelId === channelId && d.action === "invited");

  const withdrawn = await cancelInvite(admin.token, channelId, c.id);
  await t.check("admin withdraws C's invitation", withdrawn.status === 200, "HTTP " + withdrawn.status + " " + withdrawn.text.slice(0, 200));

  const cancelledPush = await sockets.c.waitFor(
    "ramonChat.membership",
    (d) => d.channelId === channelId && d.action === "invite_cancelled",
  );
  await t.check("C hears the withdrawal over the websocket", Boolean(cancelledPush), cancelledPush ? "" : "no push within 8s");

  const cRows = notificationsAbout((await notifications(c.token)).json, "chatChannelInvite", channelId);
  await t.check("the withdrawn invitation left C's bell", cRows.length === 0, cRows.length + " rows");

  const cAccept = await acceptInvite(c.token, channelId);
  await t.check("C cannot accept a withdrawn invitation", cAccept.status === 404, "HTTP " + cAccept.status);

  const cInvite = await inviteMembers(c.token, channelId, [b.id]);
  await t.check("a non-manager cannot invite", cInvite.status === 403 || cInvite.status === 404, "HTTP " + cInvite.status);

  // ── The join endpoint on a public channel answers with the record ───────────
  const pub = await createChannel(admin.token, {
    name: "E2E join " + stamp,
    description: "created by tests/E2E/invite-flow.mjs",
    isPrivate: false,
  });

  await t.must("admin creates a public channel", pub.status === 201, "HTTP " + pub.status);

  const pubId = Number(pub.json.data.id);

  try {
    const before = await showChannel(c.token, pubId);
    await t.check("a non-member reads canPostMessage=false", before.status === 200 && before.json.data.attributes.canPostMessage === false);

    const joined = await joinChannel(c.token, pubId);
    await t.check("join answers 200 with the channel", joined.status === 200 && joined.json?.data?.id === String(pubId), "HTTP " + joined.status);
    await t.check("the join response already carries canPostMessage=true", joined.json?.data?.attributes?.canPostMessage === true, JSON.stringify(joined.json?.data?.attributes?.canPostMessage));

    const joinedPushC = await sockets.c.waitFor(
      "ramonChat.membership",
      (d) => d.channelId === pubId && d.action === "joined" && d.userId === c.id,
    );
    await t.check("C's sidebar push arrives for the public join", Boolean(joinedPushC));
  } finally {
    await deleteChannel(admin.token, pubId);
  }
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
} finally {
  if (channelId) {
    const removed = await deleteChannel(admin.token, channelId);
    log("cleanup channel " + channelId + " HTTP " + removed.status);
  }

  for (const socket of Object.values(sockets)) socket.close();
}

await sleep(100);
process.exit(t.summary());
