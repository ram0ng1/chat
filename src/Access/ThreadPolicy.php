<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Ramon\Chat\Thread;

/**
 * @see ChannelPolicy for why these methods return `?bool` rather than `bool`.
 */
class ThreadPolicy extends AbstractPolicy
{
    public function __construct(
        protected VisibilityCache $cache
    ) {
    }

    /**
     * Answered from the thread in hand wherever possible, and memoised either way
     * — the same shape as MessagePolicy::view, and for the same reason: the thread
     * list resolves capability fields per row, and asking the database whether a
     * row you are holding exists is one `EXISTS` per thread on every draw.
     *
     * A thread is visible when its channel is and it is not deleted. Channel
     * visibility is one memoised answer for the whole list.
     *
     * The query stays the fallback for a thread whose channel is not loaded.
     */
    public function view(User $actor, Thread $thread): ?bool
    {
        $visible = $this->cache->remember(
            $actor,
            'thread',
            (int) $thread->id,
            function () use ($actor, $thread) {
                $channel = $thread->channel;

                if ($channel === null) {
                    return Thread::whereVisibleTo($actor)->whereKey($thread->id)->exists();
                }

                return ScopeThreadVisibility::rowVisibleTo(
                    $thread,
                    ScopeChannelVisibility::visibleTo($actor, $channel, $this->cache)
                );
            }
        );

        return $visible ?: null;
    }

    public function postMessage(User $actor, Thread $thread): ?bool
    {
        if (! $thread->acceptsMessages()) {
            return false;
        }

        $channel = $thread->channel;

        return $channel !== null && $actor->can('postMessage', $channel) ? true : false;
    }

    /**
     * The thread's creator may retitle it; moderators may retitle any thread.
     */
    public function rename(User $actor, Thread $thread): ?bool
    {
        if ($actor->exists && $actor->id === $thread->creator_id && $this->view($actor, $thread)) {
            return true;
        }

        return $actor->can('ramon-chat.moderate') ? true : null;
    }

    public function close(User $actor, Thread $thread): ?bool
    {
        return $actor->can('ramon-chat.moderate') ? true : null;
    }

    public function delete(User $actor, Thread $thread): ?bool
    {
        return $actor->can('ramon-chat.moderate') ? true : null;
    }
}
