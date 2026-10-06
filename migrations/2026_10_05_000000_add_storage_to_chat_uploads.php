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
 * Where an attachment's bytes are kept.
 *
 * `storage` is `local` for the chat's own disks and `fof` for a file written
 * through fof/upload's storage adapters. `storage_adapter` is the fof/upload
 * adapter key it was written with, so deleting it later reaches the same
 * backend even after the admin switches adapter. `remote_url` is the public
 * address the adapter returned. Every existing row is local, which is what the
 * default makes it.
 */
return [
    // Flarum's migrator passes a schema Builder, never a ConnectionInterface;
    // typing it as the latter is a TypeError that aborts the whole extension's
    // migration run.
    'up' => function (Builder $schema) {
        $columns = [
            'storage'         => fn (Blueprint $table) => $table->string('storage', 16)->default('local')->after('is_private'),
            'storage_adapter' => fn (Blueprint $table) => $table->string('storage_adapter', 64)->nullable()->after('storage'),
            'remote_url'      => fn (Blueprint $table) => $table->text('remote_url')->nullable()->after('storage_adapter'),
        ];

        // One column at a time, each behind its own guard, so a run that died
        // half way through can be repeated.
        foreach ($columns as $name => $define) {
            if ($schema->hasColumn('chat_uploads', $name)) {
                continue;
            }

            $schema->table('chat_uploads', function (Blueprint $table) use ($define) {
                $define($table);
            });
        }
    },

    'down' => function (Builder $schema) {
        foreach (['remote_url', 'storage_adapter', 'storage'] as $name) {
            if (! $schema->hasColumn('chat_uploads', $name)) {
                continue;
            }

            $schema->table('chat_uploads', function (Blueprint $table) use ($name) {
                $table->dropColumn($name);
            });
        }
    },
];
