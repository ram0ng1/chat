<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration;

/**
 * Starts each test with an empty cache.
 *
 * The harness rebuilds the database for every test but keeps the file cache in
 * its tmp directory, so whatever one test wrote there is still there for the
 * next — and for the next run. Throttle counters and the invitation cooldown
 * live in the cache, keyed by channel and user ids every test reuses, so without
 * this a decline in one test silently suppresses an invite in another.
 *
 * Boots the application, so it belongs after prepareDatabase() and setting().
 */
trait FlushesCache
{
    protected function flushCache(): void
    {
        $this->app()->getContainer()->make('cache.store')->flush();
    }
}
