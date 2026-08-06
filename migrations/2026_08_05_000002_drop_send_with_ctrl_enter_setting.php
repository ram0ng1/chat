<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Removes the forum-wide send-key default.
 *
 * Which key sends is now the member's own two-state preference, defaulting to
 * Enter, so the admin switch had no reader left — an operator could flip it and
 * nothing would happen. Members who never chose keep sending on Enter, which is
 * what the shipped default was.
 *
 * Only the setting row goes. The per-user preference stays: `sendKeyPreference()`
 * reads anything that is not "ctrl" as Enter, so the "default" values stored
 * under the old three-state preference resolve correctly without being rewritten.
 */
return [
    // Flarum's migrator always hands the closure a schema Builder — see
    // Migrator::runClosureMigration. Typing this as ConnectionInterface is a
    // TypeError that aborts the whole extension's migration run; the connection
    // comes off the builder instead.
    'up' => function (Builder $schema) {
        $schema->getConnection()
            ->table('settings')
            ->where('key', 'ramon-chat.send_with_ctrl_enter')
            ->delete();
    },

    // No rollback: the setting no longer has a reader, so restoring the row would
    // put a value in the table that nothing consults.
    'down' => fn () => null,
];
