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
 * Reports against chat messages.
 *
 * Deliberately a table of this extension's own rather than a row in `flags`.
 * flarum/flags declares `flags_post_id_foreign` as a non-nullable foreign key into
 * `posts`, so a chat message id can never be stored there — the database refuses
 * it. Reusing that table would mean altering another extension's schema, which the
 * next update of it would undo.
 */
return [
    'up' => function (Builder $schema) {
        $schema->create('chat_message_flags', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('message_id');
            $table->unsignedInteger('user_id')->nullable();

            // One of the keys MessageFlag::REASONS lists, kept as a string rather
            // than an enum so a later release can add one without a schema change.
            $table->string('reason', 30);

            // The reporter's own words. Optional for every reason but `other`,
            // where the reason alone says nothing.
            $table->text('detail')->nullable();

            // Set when a moderator resolves the report. Kept rather than deleted:
            // a message reported, cleared, and reported again is a different thing
            // from one reported once, and only a history shows that.
            $table->dateTime('resolved_at')->nullable();
            $table->unsignedInteger('resolved_by_id')->nullable();

            $table->dateTime('created_at')->nullable();

            // One report per person per message. Reporting twice is not a stronger
            // signal, and without this a single user could inflate the count.
            $table->unique(['message_id', 'user_id']);

            // The queue's own query: open reports, newest first.
            $table->index(['resolved_at', 'created_at']);

            $table->foreign('message_id')->references('id')->on('chat_messages')->cascadeOnDelete();

            // The report outlives the account that made it. Losing the row when a
            // reporter deletes their account would let someone erase the evidence
            // against a message by leaving.
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by_id')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_message_flags');
    },
];
