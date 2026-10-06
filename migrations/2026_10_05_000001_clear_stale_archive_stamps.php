<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Clears archive stamps from channels that are no longer archived.
 *
 * The status endpoint used to accept "open" and "closed" on an archived
 * channel, which took it out of the archive by status alone and left
 * `archived_at`, `archived_by_id` and `archived_discussion_id` behind. The
 * channel then read as archived to everything that looked at the stamps, and
 * the archive action never came back for it. Unarchiving now clears them, and
 * this puts the rows that went through the old path in the same state.
 *
 * Idempotent: it only touches rows whose status says they are not archived.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('chat_channels')) {
            return;
        }

        $schema->getConnection()->table('chat_channels')
            ->where('status', '!=', 'archived')
            ->where(fn ($query) => $query
                ->whereNotNull('archived_at')
                ->orWhereNotNull('archived_by_id')
                ->orWhereNotNull('archived_discussion_id'))
            ->update([
                'archived_at'            => null,
                'archived_by_id'         => null,
                'archived_discussion_id' => null,
            ]);
    },

    // Nothing to put back: the stamps described an archive that had already
    // been undone.
    'down' => function (Builder $schema) {
    },
];
