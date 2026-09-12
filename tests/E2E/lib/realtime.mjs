import { API, BASE, log } from "./env.mjs";

/**
 * A minimal Pusher-protocol client for one user's private channel.
 *
 * flarum/realtime speaks the Pusher wire protocol: connect to
 * `/app/<key>?protocol=7`, wait for `pusher:connection_established`, ask the
 * forum to sign the subscription at `/api/websocket/auth`, then subscribe.
 * Everything the chat pushes to this user afterwards arrives as an event on
 * this socket, which is what the tests wait on to prove a change was delivered
 * live rather than found on the next fetch.
 */
export async function subscribeUser({ websocket, token, userId, label }) {
  const scheme = websocket.secure ? "wss" : "ws";
  const url =
    scheme +
    "://" +
    websocket.host +
    ":" +
    websocket.port +
    "/app/" +
    websocket.key +
    "?protocol=7&client=js&version=8.4.0&flash=false";

  // The daemon admits browsers from the forum's own origin only, and Node
  // sends none by default. undici's WebSocket takes extra headers; the
  // browser it stands in for would send exactly this one.
  const ws = new WebSocket(url, { headers: { Origin: BASE } });
  const events = [];
  const waiters = new Set();
  const name = label || "user " + userId;

  let socketId = null;
  let resolveConnected;
  let resolveSubscribed;

  const connected = new Promise((resolve) => (resolveConnected = resolve));
  const subscribed = new Promise((resolve) => (resolveSubscribed = resolve));

  ws.addEventListener("message", (message) => {
    let frame;

    try {
      frame = JSON.parse(message.data);
    } catch {
      return;
    }

    let data = frame.data;

    if (typeof data === "string") {
      try {
        data = JSON.parse(data);
      } catch {
        // Some frames carry a plain string; keep it.
      }
    }

    if (frame.event === "pusher:connection_established") {
      socketId = data.socket_id;
      resolveConnected();

      return;
    }

    if (frame.event === "pusher:ping") {
      ws.send(JSON.stringify({ event: "pusher:pong", data: {} }));

      return;
    }

    if (frame.event === "pusher_internal:subscription_succeeded") {
      resolveSubscribed();

      return;
    }

    if (frame.event === "pusher:error") {
      log(name + " pusher:error", JSON.stringify(data));

      return;
    }

    const entry = { event: frame.event, channel: frame.channel, data, at: Date.now() };

    events.push(entry);

    for (const waiter of waiters) waiter(entry);
  });

  await new Promise((resolve, reject) => {
    ws.addEventListener("open", resolve, { once: true });
    ws.addEventListener(
      "error",
      () => reject(new Error("websocket connection failed: " + url)),
      { once: true },
    );
  });

  await withTimeout(connected, 10000, name + " never got a socket id");

  const channel = "private-user=" + userId;

  const auth = await fetch(API + "/websocket/auth", {
    method: "POST",
    headers: {
      Authorization: "Token " + token,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ socket_id: socketId, channel_name: channel }),
  });

  if (!auth.ok) {
    throw new Error(
      name + " websocket auth refused: HTTP " + auth.status + " " + (await auth.text()),
    );
  }

  const signature = (await auth.json()).auth;

  ws.send(
    JSON.stringify({
      event: "pusher:subscribe",
      data: { channel, auth: signature },
    }),
  );

  await withTimeout(subscribed, 10000, name + " subscription never succeeded");

  log(name + " subscribed to " + channel);

  return {
    events,

    /**
     * Resolves with the first matching event, already received or still to
     * come, or null after the timeout. Never throws: a missed push is a test
     * failure to report, not a crash.
     */
    waitFor(event, predicate = () => true, timeoutMs = 8000) {
      const found = events.find((e) => e.event === event && predicate(e.data));

      if (found) return Promise.resolve(found);

      return new Promise((resolve) => {
        const timer = setTimeout(() => {
          waiters.delete(waiter);
          resolve(null);
        }, timeoutMs);

        const waiter = (entry) => {
          if (entry.event !== event || !predicate(entry.data)) return;

          clearTimeout(timer);
          waiters.delete(waiter);
          resolve(entry);
        };

        waiters.add(waiter);
      });
    },

    close() {
      try {
        ws.close();
      } catch {
        // Already gone.
      }
    },
  };
}

function withTimeout(promise, ms, message) {
  return Promise.race([
    promise,
    new Promise((_, reject) => setTimeout(() => reject(new Error(message)), ms)),
  ]);
}
