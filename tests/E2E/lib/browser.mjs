import { spawn } from "node:child_process";
import { mkdir, writeFile } from "node:fs/promises";
import { join } from "node:path";
import { tmpdir } from "node:os";
import { randomBytes } from "node:crypto";

import { BASE, SHOTS_DIR, log, sleep } from "./env.mjs";

const EDGE_PATH =
  process.env.EDGE_PATH ||
  "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe";

/**
 * A headless Edge driven over the Chrome DevTools Protocol, with no browser
 * automation dependency: Node's own WebSocket and fetch are enough.
 *
 * One page per instance. `evaluate` runs an expression in the page and returns
 * its JSON value; `waitFor` polls one until it is truthy. Logging in is done by
 * planting the forum's remember cookie, which is what a "remember me" login
 * leaves behind, so no form has to be driven.
 */
export async function launchBrowser({ label = "browser", width = 1360, height = 900 } = {}) {
  const port = 9300 + Math.floor(Math.random() * 500);
  const profile = join(tmpdir(), "chat-e2e-" + randomBytes(6).toString("hex"));

  await mkdir(profile, { recursive: true });

  const edge = spawn(
    EDGE_PATH,
    [
      "--headless=new",
      "--disable-gpu",
      "--no-first-run",
      "--no-default-browser-check",
      // A headless window left idle is otherwise treated as occluded: the page
      // reports `document.hidden`, animation frames stop and Mithril never
      // redraws, so a live push looks like it never arrived.
      "--disable-backgrounding-occluded-windows",
      "--disable-renderer-backgrounding",
      "--disable-background-timer-throttling",
      "--remote-debugging-port=" + port,
      "--user-data-dir=" + profile,
      "--window-size=" + width + "," + height,
      "about:blank",
    ],
    { stdio: ["ignore", "ignore", "ignore"] },
  );

  let info = null;

  for (let i = 0; i < 40 && !info; i++) {
    await sleep(250);

    try {
      const response = await fetch("http://127.0.0.1:" + port + "/json/version");

      if (response.ok) info = await response.json();
    } catch {
      // Not up yet.
    }
  }

  if (!info) {
    edge.kill();
    throw new Error("Edge did not expose CDP on port " + port);
  }

  const ws = new WebSocket(info.webSocketDebuggerUrl);

  await new Promise((resolve, reject) => {
    ws.addEventListener("open", resolve, { once: true });
    ws.addEventListener("error", () => reject(new Error("CDP socket failed")), {
      once: true,
    });
  });

  let nextId = 1;
  const pending = new Map();
  const listeners = [];

  ws.addEventListener("message", (message) => {
    const frame = JSON.parse(message.data);

    if (frame.id != null && pending.has(frame.id)) {
      const { resolve, reject } = pending.get(frame.id);

      pending.delete(frame.id);

      if (frame.error) reject(new Error(frame.error.message));
      else resolve(frame.result);

      return;
    }

    if (frame.method) {
      for (const listener of listeners) listener(frame);
    }
  });

  const send = (method, params = {}, sessionId) =>
    new Promise((resolve, reject) => {
      const id = nextId++;

      pending.set(id, { resolve, reject });
      ws.send(JSON.stringify({ id, method, params, sessionId }));
    });

  const { targetInfos } = await send("Target.getTargets");
  const page = targetInfos.find((t) => t.type === "page");
  const { sessionId } = await send("Target.attachToTarget", {
    targetId: page.targetId,
    flatten: true,
  });

  const call = (method, params = {}) => send(method, params, sessionId);

  await call("Page.enable");
  await call("Runtime.enable");
  await call("Network.enable");
  // Keeps the page focused and visible however long it sits idle; see the
  // backgrounding flags above for what goes wrong otherwise.
  await call("Emulation.setFocusEmulationEnabled", { enabled: true }).catch(() => {});

  const consoleErrors = [];
  const requests = [];

  listeners.push((frame) => {
    if (frame.sessionId !== sessionId) return;

    if (frame.method === "Network.requestWillBeSent") {
      requests.push({
        id: frame.params.requestId,
        url: frame.params.request.url,
        method: frame.params.request.method,
        at: Date.now(),
        done: null,
        initiator: (frame.params.initiator?.stack?.callFrames ?? [])
          .slice(0, 6)
          .map((f) => f.functionName + ":" + f.columnNumber)
          .join(" < "),
      });
    }

    if (frame.method === "Network.loadingFinished" || frame.method === "Network.loadingFailed") {
      const request = requests.findLast((r) => r.id === frame.params.requestId);

      if (request) request.done = Date.now();
    }
  });

  listeners.push((frame) => {
    if (frame.sessionId !== sessionId) return;

    if (frame.method === "Runtime.exceptionThrown") {
      consoleErrors.push(
        frame.params.exceptionDetails?.exception?.description ??
          frame.params.exceptionDetails?.text ??
          "exception",
      );
    }
  });

  log(label + " ready on CDP port " + port);

  const browser = {
    consoleErrors,
    /** Every request the page has sent, in order: `{ url, method, at, done }`. */
    requests,

    /**
     * Signs the page in as the holder of a remember token. The cookie is set for
     * the forum's host before the first navigation, the way a returning browser
     * would carry it.
     */
    async loginWithRememberToken(token) {
      const host = new URL(BASE).hostname;

      await call("Network.setCookie", {
        name: "flarum_remember",
        value: token,
        domain: host,
        path: "/",
        secure: BASE.startsWith("https"),
        httpOnly: true,
      });
    },

    async goto(path) {
      const url = path.startsWith("http") ? path : BASE + path;
      const loaded = new Promise((resolve) => {
        const listener = (frame) => {
          if (frame.sessionId === sessionId && frame.method === "Page.loadEventFired") {
            listeners.splice(listeners.indexOf(listener), 1);
            resolve();
          }
        };

        listeners.push(listener);
      });

      await call("Page.navigate", { url });
      await Promise.race([loaded, sleep(20000)]);
    },

    /** Runs an expression in the page and returns its JSON-serialisable value. */
    async evaluate(expression) {
      const { result, exceptionDetails } = await call("Runtime.evaluate", {
        expression,
        returnByValue: true,
        awaitPromise: true,
      });

      if (exceptionDetails) {
        throw new Error(
          "page threw: " +
            (exceptionDetails.exception?.description ?? exceptionDetails.text),
        );
      }

      return result.value;
    },

    /** Polls an expression until it is truthy; returns the value or null. */
    async waitFor(expression, timeoutMs = 15000, every = 250) {
      const until = Date.now() + timeoutMs;

      while (Date.now() < until) {
        let value = null;

        try {
          value = await browser.evaluate(expression);
        } catch {
          value = null;
        }

        if (value) return value;

        await sleep(every);
      }

      return null;
    },

    /** Clicks the first element matching a selector, through the DOM. */
    async click(selector) {
      return browser.evaluate(
        "(() => { const el = document.querySelector(" +
          JSON.stringify(selector) +
          "); if (!el) return false; el.click(); return true; })()",
      );
    },

    /**
     * Clicks like a person: moves the mouse onto the element, presses, holds for
     * `holdMs` and releases. Pointer handlers (hover, press) fire as they would
     * for a real click. Returns the time the button was released.
     */
    async mouseClick(selector, holdMs = 90) {
      const box = await browser.evaluate(
        "(() => { const r = document.querySelector(" + JSON.stringify(selector) +
          ")?.getBoundingClientRect(); return r ? { x: r.x + r.width / 2, y: r.y + r.height / 2 } : null; })()",
      );

      if (!box) return null;

      await call("Input.dispatchMouseEvent", { type: "mouseMoved", x: box.x, y: box.y });
      await call("Input.dispatchMouseEvent", { type: "mousePressed", x: box.x, y: box.y, button: "left", clickCount: 1 });
      await sleep(holdMs);
      await call("Input.dispatchMouseEvent", { type: "mouseReleased", x: box.x, y: box.y, button: "left", clickCount: 1 });

      return Date.now();
    },

    /** Turns `prefers-reduced-motion: reduce` on or off for the page. */
    async emulateReducedMotion(on) {
      await call("Emulation.setEmulatedMedia", {
        features: [{ name: "prefers-reduced-motion", value: on ? "reduce" : "no-preference" }],
      });
    },

    /** Clicks the element matched by a JS expression that returns it. */
    async clickWhere(expression) {
      return browser.evaluate(
        "(() => { const el = (" + expression + "); if (!el) return false; el.click(); return true; })()",
      );
    },

    async screenshot(name) {
      await mkdir(SHOTS_DIR, { recursive: true });

      const { data } = await call("Page.captureScreenshot", { format: "png" });
      const file = join(SHOTS_DIR, name + ".png");

      await writeFile(file, Buffer.from(data, "base64"));
      log("screenshot " + file);

      return file;
    },

    async close() {
      try {
        ws.close();
      } catch {
        // Already closed.
      }

      edge.kill();
    },
  };

  return browser;
}
