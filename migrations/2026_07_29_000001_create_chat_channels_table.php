<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('chat_channels', function (Blueprint $table) {
            $table->increments('id');

            // 'category' channels are bound to a tag and inherit its permissions.
            // 'direct' channels are 1:1 or group DMs with an explicit member list.
            $table->string('type', 20)->default('category');

            $table->string('name', 100)->nullable();
            $table->string('slug', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('emoji', 60)->nullable();

            // Bound tag (flarum/tags). Null for direct channels and for
            // channels created while tags is disabled.
            $table->unsignedInteger('tag_id')->nullable();

            // open | closed | archived
            $table->string('status', 20)->default('open');

            $table->boolean('threading_enabled')->default(false);

            // Auto-join every existing and future user (Discourse "default channel").
            $table->boolean('auto_join')->default(false);

            // Channel-wide mute of @here/@all for noisy channels.
            $table->boolean('allow_channel_wide_mentions')->default(true);

            $table->unsignedInteger('creator_id')->nullable();

            // Denormalised counters, maintained by the message pipeline so the
            // channel list never needs an aggregate per row.
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('user_count')->default(0);
            $table->unsignedInteger('last_message_id')->nullable();
            $table->dateTime('last_message_at')->nullable();

            // Archival target: the discussion the transcript was posted to.
            $table->unsignedInteger('archived_discussion_id')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->unsignedInteger('archived_by_id')->nullable();

            $table->timestamps();
            $table->dateTime('deleted_at')->nullable();
            $table->unsignedInteger('deleted_by_id')->nullable();

            $table->unique('slug');
            $table->index(['type', 'status']);
            $table->index('tag_id');
            $table->index('last_message_at');

            // No foreign key on tag_id on purpose: flarum/tags is an optional
            // dependency, so the `tags` table may not exist at install time.
            // Orphaned bindings are pruned by Listener\PruneOrphanedChannels.
            $table->foreign('creator_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('archived_by_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by_id')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_channels');
    },
];
