<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Store;
use Ramon\Chat\Channel;

/**
 * Slow mode: the minimum gap between one person's messages in a channel.
 *
 * Distinct from RateLimiter, which is a forum-wide flood guard measured in
 * messages per second and exists to keep the server standing. This is a
 * moderation tool a channel owner reaches for when a conversation is moving too
 * fast to follow — Discord draws the same line, and so the two coexist rather than
 * one subsuming the other.
 *
 * Held in the cache rather than derived from the last message row. The check runs
 * on every send, which is the hottest path the extension has, and a cache key with
 * a TTL expires itself: no query, no index, and nothing to clean up. The cost is
 * that flushing the cache clears everyone's cooldown, which is the right trade for
 * friction — this is not a security control, and Discord's own slow mode is
 * best-effort in the same way.
 */
class SlowMode
{
    public function __construct(
        protected Store $cache,
        protected Translator $translator
    ) {
    }

    /**
     * Refuses the send when the actor is still within the channel's cooldown.
     *
     * @throws ValidationException
     */
    public function assertMayPost(Channel $channel, User $actor): void
    {
        $seconds = $this->secondsFor($channel);

        if ($seconds <= 0 || $this->isExempt($channel, $actor)) {
            return;
        }

        $remaining = $this->remainingFor($channel, $actor);

        if ($remaining <= 0) {
            return;
        }

        throw new ValidationException([
            'content' => $this->translator->trans(
                'ramon-chat.api.slow_mode_wait',
                ['seconds' => $remaining]
            ),
        ]);
    }

    /**
     * Starts the actor's cooldown. Called after a message is accepted, never
     * before — a send that fails validation should not cost the sender their turn.
     */
    public function noteSent(Channel $channel, User $actor): void
    {
        $seconds = $this->secondsFor($channel);

        if ($seconds <= 0 || $this->isExempt($channel, $actor)) {
            return;
        }

        // The value is when the cooldown ends, so `remainingFor` needs no second
        // source of truth about how long the channel's window is — a moderator
        // shortening it mid-conversation does not strand anyone on the old one.
        $this->cache->put($this->key($channel, $actor), time() + $seconds, $seconds + 1);
    }

    /**
     * Seconds the actor must still wait, or 0 when they may post now.
     */
    public function remainingFor(Channel $channel, User $actor): int
    {
        if ($this->secondsFor($channel) <= 0 || $this->isExempt($channel, $actor)) {
            return 0;
        }

        // `Store::get()` takes no default, unlike the cache Repository's.
        $until = (int) ($this->cache->get($this->key($channel, $actor)) ?? 0);

        return max(0, $until - time());
    }

    protected function secondsFor(Channel $channel): int
    {
        return max(0, (int) $channel->slow_mode_seconds);
    }

    /**
     * Who slow mode does not apply to.
     *
     * Its own permission rather than `ramon-chat.moderate`, which conflated two
     * questions: who may act on other people's messages, and who the channel's
     * pace applies to. A bot account or a support agent may need to answer
     * without waiting while holding no moderation power at all, and a forum may
     * equally want its moderators keeping the same rhythm as everyone else.
     *
     * Seeded to MODERATOR, so the forums already running slow mode see no change.
     */
    protected function isExempt(Channel $channel, User $actor): bool
    {
        return $actor->hasPermission('ramon-chat.bypassSlowMode');
    }

    protected function key(Channel $channel, User $actor): string
    {
        return 'ramon-chat.slow.'.$channel->id.'.'.$actor->id;
    }
}
