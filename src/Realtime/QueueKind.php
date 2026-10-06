<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime;

use Flarum\Queue\RoutingQueue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\SyncQueue;

/**
 * What kind of queue the forum runs, as far as a realtime push cares.
 *
 * Read the way Flarum\Queue\QueueServiceProvider reads it: the routing wrapper
 * core puts around an async driver is unwrapped first. `sync` runs a pushed job
 * inline anyway, and `database` is worked by `schedule:run` once a minute, so
 * only a continuously worked queue (fof/redis and the like) can take a chat
 * push without delaying it.
 */
final class QueueKind
{
    public const SYNC = 'sync';

    public const DATABASE = 'database';

    public const OTHER = 'other';

    public static function of(mixed $queue): string
    {
        if (class_exists(RoutingQueue::class) && $queue instanceof RoutingQueue) {
            $queue = $queue->getDriver();
        }

        if ($queue instanceof SyncQueue) {
            return self::SYNC;
        }

        if ($queue instanceof DatabaseQueue) {
            return self::DATABASE;
        }

        return self::OTHER;
    }

    /**
     * Whether a push may leave the request without arriving late.
     */
    public static function defers(mixed $queue): bool
    {
        return self::of($queue) === self::OTHER;
    }
}
