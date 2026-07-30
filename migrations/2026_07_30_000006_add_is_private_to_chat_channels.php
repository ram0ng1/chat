<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Public and private category channels.
 *
 * A public channel is discoverable: it appears in Browse for anyone the category's
 * permissions already admit, and they may join it themselves. A private one is
 * invitation-only — it is visible solely to people who are already members, so it
 * does not appear in Browse, cannot be joined from there, and its existence is not
 * advertised to the rest of the forum.
 *
 * This composes with, rather than replaces, the category binding: a private channel
 * bound to a tag is restricted twice over, and one with no tag is restricted purely
 * by its membership. Direct channels are unaffected — they are private by
 * construction and never carry this flag.
 *
 * Defaults to false so every existing channel stays exactly as discoverable as it
 * was before the column existed.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('status');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    },
];
