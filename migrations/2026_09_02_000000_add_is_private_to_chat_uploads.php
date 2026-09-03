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
 * Which disk an attachment lives on.
 *
 * False is the public `chat` disk under the webroot; true is `chat-private`
 * under storage, reachable only through ServeUploadController. The flag is what
 * Upload::url() and every delete path read, so a row and its file can never
 * disagree about where the file is.
 */
return [
    // Flarum's migrator passes a schema Builder, never a ConnectionInterface;
    // typing it as the latter is a TypeError that aborts the whole extension's
    // migration run.
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('chat_uploads', 'is_private')) {
            return;
        }

        $schema->table('chat_uploads', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('path');
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('chat_uploads', 'is_private')) {
            return;
        }

        $schema->table('chat_uploads', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    },
];
