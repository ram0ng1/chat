<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Webhook deliveries used to be stored as authorless text messages, which the
 * stream draws as a deleted account. They are bot messages: the client draws
 * those with the bot's name and avatar.
 */
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()
            ->table('chat_messages')
            ->whereNotNull('webhook_id')
            ->whereNull('user_id')
            ->where('type', 'text')
            ->update(['type' => 'bot']);
    },

    'down' => function (Builder $schema) {
        $schema->getConnection()
            ->table('chat_messages')
            ->whereNotNull('webhook_id')
            ->whereNull('user_id')
            ->where('type', 'bot')
            ->whereNull('system_key')
            ->update(['type' => 'text']);
    },
];
