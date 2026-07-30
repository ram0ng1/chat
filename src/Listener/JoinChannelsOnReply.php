<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\Post\Event\Posted;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Service\MembershipManager;
use Illuminate\Contracts\Events\Dispatcher as Events;

/**
 * Subscribes a user to a channel when they reply to a discussion in the category
 * that channel is bound to, if the channel has `auto_join_on_reply` set.
 *
 * The point is to grow a channel from demonstrated interest instead of pushing it
 * on everyone: someone who takes part in a category gets its chat room in their
 * sidebar. Unlike `auto_join`, this only ever adds one user at a time.
 */
class JoinChannelsOnReply
{
    public function __construct(
        protected MembershipManager $memberships,
        protected Events $events
    ) {
    }

    public function handle(Posted $event): void
    {
        $post = $event->post;
        $actor = $event->actor;

        // Posted::$actor is nullable — a post can be created by a background job
        // or an import with no acting user.
        if ($actor === null || ! $actor->exists) {
            return;
        }

        // Guests aside, a user with no chat access should not be silently opted in.
        if (! $actor->can('ramon-chat.use')) {
            return;
        }

        $discussion = $post->discussion;

        if ($discussion === null) {
            return;
        }

        $tagIds = $this->tagIdsFor($discussion);

        if ($tagIds === []) {
            return;
        }

        $channels = Channel::query()
            ->where('auto_join_on_reply', true)
            ->where('type', Channel::TYPE_CATEGORY)
            ->where('status', Channel::STATUS_OPEN)
            ->whereNull('deleted_at')
            ->whereIn('tag_id', $tagIds)
            ->get();

        foreach ($channels as $channel) {
            // Respect a deliberate departure: someone who left the channel should
            // not be dragged back in every time they post in the category.
            $everJoined = ChannelUser::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $actor->id)
                ->exists();

            if ($everJoined) {
                continue;
            }

            // The channel inherits the tag's permissions, and a discussion can be
            // visible to someone the channel is not — so check before joining.
            if (! Channel::whereVisibleTo($actor)->whereKey($channel->id)->exists()) {
                continue;
            }

            $this->memberships->join($channel, $actor);
            $channel->refreshMetadata()->save();

            $this->events->dispatch(new UserJoinedChannel($channel, $actor, $actor));
        }
    }

    /**
     * The discussion's tag ids, including parents so a channel bound to a parent
     * category also picks up replies in its children.
     *
     * @return int[]
     */
    protected function tagIdsFor(object $discussion): array
    {
        // Not `method_exists($discussion, 'tags')`: flarum/tags adds the relation
        // through Extend\Model, which registers it dynamically rather than
        // declaring a method. method_exists() therefore returns false even though
        // `$discussion->tags` resolves perfectly — which silently made this method
        // return [] and the whole listener a no-op.
        try {
            $tags = $discussion->tags;
        } catch (\Throwable $e) {
            // tags is not installed, or the relation is unavailable.
            return [];
        }

        if ($tags === null) {
            return [];
        }

        $ids = [];

        foreach ($tags as $tag) {
            $ids[] = (int) $tag->id;

            if (! empty($tag->parent_id)) {
                $ids[] = (int) $tag->parent_id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
