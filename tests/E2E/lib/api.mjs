import { API } from "./env.mjs";

/**
 * One JSON:API request as a given user.
 *
 * Flarum 2 expects `Authorization: Token <key>`. The body, when given, is sent
 * as JSON; the response is parsed when it is JSON and left as text otherwise,
 * so a stray HTML error page shows up in the failure detail rather than as a
 * parse exception.
 */
export async function api(token, method, path, body) {
  const url = path.startsWith("http") ? path : API + path;
  const headers = { Accept: "application/json" };

  if (token) headers.Authorization = "Token " + token;
  if (body !== undefined) headers["Content-Type"] = "application/json";

  let status = 0;
  let text = "";
  let json = null;

  try {
    const response = await fetch(url, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });

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

  return { status, json, text };
}

/** The forum document, which carries the websocket settings and the actor. */
export async function forum(token) {
  return api(token, "GET", "/");
}

export async function createChannel(token, attributes) {
  return api(token, "POST", "/chat-channels", {
    data: {
      type: "chat-channels",
      attributes: { type: "category", ...attributes },
    },
  });
}

export async function deleteChannel(token, id) {
  return api(token, "DELETE", "/chat-channels/" + id);
}

export async function inviteMembers(token, channelId, userIds) {
  return api(token, "POST", "/chat-channels/" + channelId + "/members", {
    data: { attributes: { userIds } },
  });
}

export async function acceptInvite(token, channelId) {
  return api(token, "POST", "/chat/invites/" + channelId + "/accept");
}

export async function declineInvite(token, channelId) {
  return api(token, "POST", "/chat/invites/" + channelId + "/decline");
}

export async function cancelInvite(token, channelId, userId) {
  return api(token, "POST", "/chat-channels/" + channelId + "/invites/cancel", {
    data: { attributes: { userId } },
  });
}

export async function joinChannel(token, channelId) {
  return api(token, "POST", "/chat-channels/" + channelId + "/join", {
    data: { attributes: {} },
  });
}

export async function leaveChannel(token, channelId) {
  return api(token, "POST", "/chat-channels/" + channelId + "/leave");
}

export async function showChannel(token, channelId, include) {
  return api(
    token,
    "GET",
    "/chat-channels/" + channelId + (include ? "?include=" + include : ""),
  );
}

export async function sendMessage(token, channelId, content) {
  return api(token, "POST", "/chat-messages", {
    data: {
      type: "chat-messages",
      attributes: { content, channelId: Number(channelId) },
    },
  });
}

export async function notifications(token) {
  return api(token, "GET", "/notifications?page[limit]=50");
}

/** The notifications of one type about one channel, newest first. */
export function notificationsAbout(document, type, channelId) {
  return (document?.data ?? []).filter(
    (row) =>
      row.attributes?.contentType === type &&
      Number(row.attributes?.content?.channelId) === Number(channelId),
  );
}
