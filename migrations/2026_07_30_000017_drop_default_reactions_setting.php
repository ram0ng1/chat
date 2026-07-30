<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Removes `ramon-chat.default_reactions`.
 *
 * The setting was serialised to every forum payload and read by nothing: the
 * reaction bar draws its own set, and the only remaining mention of the key was a
 * docblock. Deleting the extender alone would have left the row behind, still
 * shipped on every page load and still shown in any tool that lists settings.
 *
 * No down. Restoring a value nobody reads is not a rollback of anything.
 */
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()
            ->table('settings')
            ->where('key', 'ramon-chat.default_reactions')
            ->delete();
    },

    'down' => fn () => null,
];
