<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Foundation\Paths;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Database\Schema\Builder;

// Declared ahead of the `return`: a function inside a condition is not hoisted,
// and a file-level `return` ends the file before anything written below it
// runs — so a helper placed after the array is never defined at all.
if (! function_exists('chat_uploads_relocate')) {
    /**
     * Moves one stored file between the two roots.
     *
     * Paths are server-generated (`Y/m/<random>.<ext>`), never client input, but
     * a row is still refused if it does not look like one: a migration is the
     * wrong place to find out otherwise. A file that is already at its target
     * counts as moved; a file missing from both is left alone and its row
     * unchanged, which is the state that makes the least claims.
     */
    function chat_uploads_relocate(string $path, string $fromRoot, string $toRoot): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return false;
        }

        $source = $fromRoot.'/'.$path;
        $target = $toRoot.'/'.$path;

        if (is_file($target)) {
            if (is_file($source)) {
                @unlink($source);
            }

            return true;
        }

        if (! is_file($source)) {
            return false;
        }

        $dir = dirname($target);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return false;
        }

        return @rename($source, $target);
    }
}

/**
 * Takes the attachments already posted in private and direct channels off the
 * public disk.
 *
 * Before the private disk existed every file went under `public/assets/chat`,
 * so a picture sent in a private room was readable by anyone holding its URL.
 * Flagging the rows alone would leave those files where they are, so this
 * moves each one and flags its row only once the file is where the flag says.
 *
 * Native `rename` rather than the filesystem disks: the extension's extenders,
 * and with them its disks, are not guaranteed to be registered while its own
 * migrations run. The paths come from Paths, the same source the disks use.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('chat_uploads') || ! $schema->hasColumn('chat_uploads', 'is_private')) {
            return;
        }

        $paths = resolve(Paths::class);
        $from = $paths->public.'/assets/chat';
        $to = $paths->storage.'/chat-uploads';

        // Restricted tags count too, when the tags table is there to ask. A
        // channel on a staff-only category is as private as one flagged so.
        $tags = $schema->hasTable('tags');

        $schema->getConnection()->table('chat_uploads')
            ->join('chat_messages', 'chat_messages.id', '=', 'chat_uploads.message_id')
            ->join('chat_channels', 'chat_channels.id', '=', 'chat_messages.channel_id')
            ->when($tags, fn (Query $query) => $query->leftJoin('tags', 'tags.id', '=', 'chat_channels.tag_id'))
            ->where('chat_uploads.is_private', 0)
            ->where(function (Query $query) use ($tags) {
                $query->where('chat_channels.type', 'direct')
                    ->orWhere('chat_channels.is_private', 1)
                    ->when($tags, fn (Query $query) => $query->orWhere('tags.is_restricted', 1));
            })
            ->select(['chat_uploads.id', 'chat_uploads.path'])
            ->orderBy('chat_uploads.id')
            ->chunkById(200, function ($uploads) use ($schema, $from, $to) {
                foreach ($uploads as $upload) {
                    if (! chat_uploads_relocate($upload->path, $from, $to)) {
                        continue;
                    }

                    $schema->getConnection()->table('chat_uploads')
                        ->where('id', $upload->id)
                        ->update(['is_private' => 1]);
                }
            }, 'chat_uploads.id', 'id');
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasTable('chat_uploads') || ! $schema->hasColumn('chat_uploads', 'is_private')) {
            return;
        }

        $paths = resolve(Paths::class);
        $from = $paths->storage.'/chat-uploads';
        $to = $paths->public.'/assets/chat';

        $schema->getConnection()->table('chat_uploads')
            ->where('is_private', 1)
            ->select(['id', 'path'])
            ->orderBy('id')
            ->chunkById(200, function ($uploads) use ($schema, $from, $to) {
                foreach ($uploads as $upload) {
                    if (! chat_uploads_relocate($upload->path, $from, $to)) {
                        continue;
                    }

                    $schema->getConnection()->table('chat_uploads')
                        ->where('id', $upload->id)
                        ->update(['is_private' => 0]);
                }
            });
    },
];
