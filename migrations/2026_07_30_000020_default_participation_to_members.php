<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Neutralized. It was a re-seeding migration, and re-seeding is precisely what an
 * extension must not do.
 *
 * The previous version reapplied `ramon-chat.use`, `startDirect`, `upload` and
 * `react` to the Member group to cover old installs that never received the
 * default from `2026_07_29_000013`. The side effect was that any forum that had
 * revoked those permissions on purpose got them back on the next update: the
 * extension silently overriding the admin's decision.
 *
 * Seeding the default on first install is legitimate and stays in
 * `2026_07_29_000013`. Reapplying it afterwards is not: from then on the
 * permission setup belongs to the forum, and the extension only reads it.
 *
 * The file stays in place instead of being deleted: its name is already recorded
 * in the `migrations` table of anyone who upgraded, and removing it undoes
 * nothing, it would only erase the record of why it existed.
 */
return [
    'up'   => fn () => null,
    'down' => fn () => null,
];
