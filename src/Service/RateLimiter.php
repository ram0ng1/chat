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
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Store;

/**
 * Throttles message sends per user.
 *
 * Chat inverts the usual forum assumption that posting is rare, so an
 * unthrottled endpoint is a trivial flood vector. Discourse exposes the same
 * control as "chat max messages per second".
 */
class RateLimiter
{
    public function __construct(
        protected Store $cache,
        protected SettingsRepositoryInterface $settings,
        protected Translator $translator
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function assertWithinLimit(User $actor): void
    {
        // Admins are exempt: they are the ones who would need to talk their way
        // out of a misconfigured limit.
        if ($actor->isAdmin()) {
            return;
        }

        $perSecond = (float) $this->settings->get('ramon-chat.max_messages_per_second', 2);

        if ($perSecond <= 0) {
            return;
        }

        // Fixed one-second window. A sliding window would be more precise but
        // needs per-request timestamp storage; for flood control the difference
        // does not justify the extra writes.
        $key = 'ramon-chat.rate.'.$actor->id.'.'.time();

        $count = (int) $this->cache->get($key, 0);

        if ($count >= $perSecond) {
            throw new ValidationException([
                'content' => $this->translator->trans('ramon-chat.api.rate_limited'),
            ]);
        }

        // TTL of 2s so the key outlives its window without lingering.
        $this->cache->put($key, $count + 1, 2);
    }
}
