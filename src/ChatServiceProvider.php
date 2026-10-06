<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat;

use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Config;
use Flarum\Formatter\Formatter;
use Flarum\Http\UrlGenerator;
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

        // One instance, so both policies memoise into the same map. Resolved fresh
        // per request in php-fpm; in a long-lived worker it survives, which is why
        // it keys on the User object rather than on the id — see VisibilityCache.
        $this->container->singleton(Access\VisibilityCache::class);

        // One instance, so clearing a channel's rank book after a write also
        // clears what any resource read of it earlier in the request. Its
        // memo keys on the Channel object, which does not outlive the request.
        $this->container->singleton(Service\ChannelRanks::class);
    }

    public function boot(): void
    {
        // HasFormattedContent keeps the formatter in a static per-class, so each
        // model that uses the trait has to be handed one explicitly.
        $formatter = $this->container->make(Formatter::class);

        Message::setFormatter($formatter);
        MessageRevision::setFormatter($formatter);

        $url = $this->container->make(UrlGenerator::class);

        Channel::setServices(
            $this->container->make(ExtensionManager::class),
            $this->container->make(Config::class)
        );
        Upload::setUrlGenerator($url);
        Webhook::setUrlGenerator($url);
    }
}
