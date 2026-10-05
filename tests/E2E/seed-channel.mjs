#!/usr/bin/env node
/**
 * Creates a public channel with B as a member and N messages from the admin,
 * some of them quoting earlier ones, and prints its id. For query-count.php and
 * manual profiling; delete it afterwards with `node tests/E2E/seed-channel.mjs --delete <id>`.
 */
import { api, createChannel, deleteChannel, joinChannel, sendMessage } from "./lib/api.mjs";
import { loadTokens } from "./lib/env.mjs";

const { admin, b, c } = await loadTokens();

if (process.argv[2] === "--delete") {
  console.log((await deleteChannel(admin.token, Number(process.argv[3]))).status);
  process.exit(0);
}

const count = Number(process.argv[2] ?? 60);
const created = await createChannel(admin.token, { name: "E2E seed " + Date.now().toString(36), isPrivate: false });
const id = Number(created.json.data.id);

await joinChannel(b.token, id);
if (c) await joinChannel(c.token, id);

let previous = null;
for (let i = 0; i < count; i += 10) {
  const sent = await Promise.all(
    Array.from({ length: Math.min(10, count - i) }, (_, k) =>
      api(admin.token, "POST", "/chat-messages", {
        data: {
          type: "chat-messages",
          attributes: { content: "seed **" + (i + k) + "** :smile:", channelId: id, ...(previous && k % 3 === 0 ? { replyToId: previous } : {}) },
        },
      }),
    ),
  );
  previous = Number(sent[0].json?.data?.id ?? previous);
}

for (let k = 0; k < 5; k++) await sendMessage(c.token, id, "from c " + k);

console.log(id);
