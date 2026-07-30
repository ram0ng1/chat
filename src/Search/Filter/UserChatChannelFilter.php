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
use Ramon\Chat\Channel;

/**
 * `filter[chatChannel]=7` on the user search — only people in that channel.
 *
 * The composer's `@` autocomplete uses it. Without it the suggestions came from
 * the whole forum, which is wrong twice over: mentioning someone who is not in the
 * channel notifies them about a conversation they cannot open, and in a private
 * channel it offered a list of people who cannot even see that the channel exists.
 *
 * ## Why the channel is re-checked here
 *
 * The filter is reachable by anyone who can search users, with any channel id. If
 * it simply joined on membership, `filter[chatChannel]=<any id>` would become a way
 * to enumerate the membership of a private channel from the outside. So the channel
 * is loaded through `whereVisibleTo` first, and an id the actor cannot see yields
 * an empty result rather than a leak.
 *
 * Hidden memberships are excluded for the same reason they are hidden everywhere
 * else: a lurking moderator should not be suggested as a participant.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class UserChatChannelFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'chatChannel';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $channelId = (int) (is_array($value) ? reset($value) : $value);

        if ($channelId <= 0) {
            return;
        }

        $actor = $state->getActor();

        $channel = Channel::query()
            ->whereVisibleTo($actor)
            ->find($channelId);

        if ($channel === null) {
            // Fail closed: an unreadable channel narrows the search to nothing
            // rather than falling back to every user on the forum.
            $state->getQuery()->whereRaw('1 = 0');

            return;
        }

        $state->getQuery()->{$negate ? 'whereNotExists' : 'whereExists'}(function ($query) use ($channelId) {
            $query->selectRaw(1)
                ->from('chat_channel_user')
                ->whereColumn('chat_channel_user.user_id', 'users.id')
                ->where('chat_channel_user.channel_id', $channelId)
                ->whereNull('chat_channel_user.left_at')
                ->where('chat_channel_user.hidden', false);
        });
    }
}
