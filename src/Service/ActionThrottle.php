<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A fixed-window counter for the chat's side actions: reactions, typing,
 * drafts, bookmarks and webhook deliveries.
 *
 * Message sends have their own limiter, with a setting behind it, because how
 * fast people may talk is a forum's decision. These are not: each is an action
 * no person performs more than a few times a second, and a cap on each is what
 * keeps a script from turning one of them into a flood of writes or pushes.
 *
 * `add` then `increment`, rather than read and put, so two requests landing in
 * the same instant cannot both see the counter below the cap. The window key
 * carries its own start, so a counter never needs resetting; it expires.
 */
class ActionThrottle
{
    public function __construct(
        protected Cache $cache
    ) {
    }

    /**
     * Records one attempt and says whether it was within the limit.
     */
    public function attempt(string $key, int $max, int $windowSeconds): bool
    {
        $windowSeconds = max(1, $windowSeconds);
        $window = intdiv(time(), $windowSeconds);
        $cacheKey = 'ramon-chat.throttle.'.$key.'.'.$window;

        $this->cache->add($cacheKey, 0, $windowSeconds * 2);

        return (int) $this->cache->increment($cacheKey) <= $max;
    }
}
