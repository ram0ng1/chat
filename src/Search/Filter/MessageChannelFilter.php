<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

/**
 * `filter[channel]=1` — the message stream for one channel.
 *
 * Thread replies are excluded by default, so the main stream shows a thread as a
 * single root message with a reply indicator instead of inlining every reply.
 * Pass `filter[includeThreadReplies]=1` alongside it to opt out.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class MessageChannelFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'channel';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $channelId = (int) (is_array($value) ? reset($value) : $value);

        if ($channelId <= 0) {
            return;
        }

        $query = $state->getQuery();

        $query->where('chat_messages.channel_id', $negate ? '!=' : '=', $channelId);

        if ($negate) {
            return;
        }

        // Keep thread roots — which carry a thread_id too — but drop their replies.
        $query->where(function ($query) {
            $query->whereNull('chat_messages.thread_id')
                ->orWhereExists(function ($sub) {
                    $sub->selectRaw(1)
                        ->from('chat_threads')
                        ->whereColumn('chat_threads.id', 'chat_messages.thread_id')
                        ->whereColumn('chat_threads.original_message_id', 'chat_messages.id');
                });
        });
    }
}
