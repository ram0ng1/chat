<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Formatter\Formatter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Ramon\Chat\Service\RateLimiter;

class ChatServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // The rate limiter needs a raw Store; Flarum binds a Repository.
        $this->container->when(RateLimiter::class)
            ->needs(Store::class)
            ->give(fn () => $this->container->make(CacheRepository::class)->getStore());
    }

    public function boot(): void
    {
        // HasFormattedContent keeps the formatter in a static per-class, so each
        // model that uses the trait has to be handed one explicitly.
        $formatter = $this->container->make(Formatter::class);

        Message::setFormatter($formatter);
        MessageRevision::setFormatter($formatter);
    }
}
