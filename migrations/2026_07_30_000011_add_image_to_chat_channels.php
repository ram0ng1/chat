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
 * A picture for the channel, as an alternative to its emoji.
 *
 * Stored as a path on the `flarum-assets` disk rather than a URL, matching how core
 * keeps the logo and favicon: the forum can move host without every channel icon
 * turning into a dead link.
 *
 * It sits beside `emoji` instead of replacing it. An emoji costs nothing to set and
 * suits most channels; an uploaded picture is for the few that want a real mark. The
 * client prefers the image when both are present, so switching back is a matter of
 * removing the upload rather than re-picking an emoji.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->string('image_path', 100)->nullable()->after('emoji');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    },
];
