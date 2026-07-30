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
    public function view(User $actor, Thread $thread): ?bool
    {
        return Thread::whereVisibleTo($actor)->whereKey($thread->id)->exists() ?: null;
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
