<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Withdraws `ramon-chat.view`.
 *
 * It existed to let logged-out visitors read public channels. That is no longer
 * wanted, so the permission goes rather than being left in the admin grid as a
 * row that grants nothing — a permission that does not do what its name says is
 * worse than one that is absent.
 *
 * Every grant is removed, Guest included. That is the point of the change: a
 * forum that had ticked the Guest column keeps working, it simply stops being
 * readable to the internet. The `use` permission, which members hold, is
 * untouched and is what governs the chat now.
 *
 * Irreversible by design: `down` does not restore the grants, because the code
 * that consulted this permission is gone too and recreating the rows would only
 * put a dead entry back in the database.
 */
return [
    // Flarum's migrator passes a schema Builder, never a ConnectionInterface. Typing
    // the parameter as the latter is a TypeError that aborts the *whole extension's*
    // migration run — every later migration silently stops too, and the console
    // reports only "Migrating extension: ramon-chat" before exiting 255. The
    // connection is reached through the builder, which is what the other
    // permission migrations here do.
    'up' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('permission', 'ramon-chat.view')
            ->delete();
    },

    'down' => function (Builder $schema) {
        // Nothing. See above.
    },
];
