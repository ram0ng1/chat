/**
 * Shared configuration and the tiny assertion harness every E2E script uses.
 *
 * Env:
 *   CHAT_E2E_BASE   forum URL (default https://alegatest.alega.com.br)
 *   CHAT_E2E_TOKENS path to the tokens file (default tests/E2E/.tokens.json,
 *                   written by tests/E2E/mint-tokens.php)
 */
import { readFile, mkdir, appendFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

// The local forum runs on a self-signed certificate.
process.env.NODE_TLS_REJECT_UNAUTHORIZED = "0";

export const E2E_DIR = dirname(dirname(fileURLToPath(import.meta.url)));
export const BASE = (
  process.env.CHAT_E2E_BASE || "https://alegatest.alega.com.br"
).replace(/\/$/, "");
export const API = BASE + "/api";
export const RESULTS_DIR = join(E2E_DIR, "results");
export const SHOTS_DIR = join(E2E_DIR, "screenshots");

export const log = (...args) =>
  console.log("[" + new Date().toISOString().slice(11, 23) + "]", ...args);

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export async function loadTokens() {
  const path = process.env.CHAT_E2E_TOKENS || join(E2E_DIR, ".tokens.json");

  try {
    return JSON.parse(await readFile(path, "utf8"));
  } catch (e) {
    throw new Error(
      "tokens file missing (" +
        path +
        "). Run: php tests/E2E/mint-tokens.php (from the forum root, PHP 8.4+)",
    );
  }
}

/**
 * One result line per check, appended to results/<suite>.jsonl so a run leaves
 * a record that can be diffed against the previous one.
 */
export function harness(suite) {
  const results = [];
  let pass = 0;
  let fail = 0;

  const record = async (ok, name, detail) => {
    ok ? pass++ : fail++;
    results.push({ suite, name, ok, detail, at: new Date().toISOString() });
    log((ok ? "PASS" : "FAIL") + "  " + name + (detail ? "  " + detail : ""));

    await mkdir(RESULTS_DIR, { recursive: true });
    await appendFile(
      join(RESULTS_DIR, suite + ".jsonl"),
      JSON.stringify(results[results.length - 1]) + "\n",
    );
  };

  return {
    async check(name, condition, detail = "") {
      await record(Boolean(condition), name, detail);

      return Boolean(condition);
    },

    async must(name, condition, detail = "") {
      await record(Boolean(condition), name, detail);

      if (!condition) {
        throw new Error("aborting " + suite + ": " + name + " " + detail);
      }
    },

    async fail(name, detail = "") {
      await record(false, name, detail);
    },

    summary() {
      log(
        "========== " +
          suite +
          " ==========  pass=" +
          pass +
          " fail=" +
          fail,
      );

      return fail === 0 ? 0 : 1;
    },
  };
}
