#!/usr/bin/env node
/**
 * Runs every E2E suite in order and exits non-zero if any of them failed.
 *
 *   node tests/E2E/run.mjs            all suites
 *   node tests/E2E/run.mjs invite     only suites whose name contains "invite"
 */
import { spawn } from "node:child_process";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const filter = process.argv[2] ?? "";

const suites = [
  "invite-flow.mjs",
  "join-composer.mjs",
  "invite-modal.mjs",
  "membership-live.mjs",
  "admin-page.mjs",
].filter((name) =>
  name.includes(filter),
);

let failed = 0;

for (const suite of suites) {
  console.log("\n#################### " + suite + " ####################\n");

  const code = await new Promise((resolve) => {
    const child = spawn(process.execPath, [join(here, suite)], {
      stdio: "inherit",
      env: process.env,
    });

    child.on("exit", (status) => resolve(status ?? 1));
  });

  if (code !== 0) failed++;
}

console.log(
  "\n==================== " +
    (failed === 0 ? "ALL SUITES PASSED" : failed + " SUITE(S) FAILED") +
    " ====================",
);

process.exit(failed === 0 ? 0 : 1);
