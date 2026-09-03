<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration;

use Ramon\Chat\Channel;
use Ramon\Chat\Message;
use Ramon\Chat\Thread;
use Ramon\Chat\Upload;

/**
 * Starts each test with one copy of every chat visibility scoper.
 *
 * `Extend\ModelVisibility` registers its scoper into a static registry on the
 * model, and flarum/testing boots a fresh application per test without ever
 * clearing it — so the hundredth test in the process runs a hundred copies of
 * each scoper, ANDed together. Harmless on its own; the copies agree. But the
 * scopes nest: an upload is visible when its message is, a message when its
 * channel is, and each level multiplies the copies below it. Late in a long run
 * the upload scope alone binds tens of thousands of parameters, and SQLite
 * answers "too many SQL variables" to a query that in production carries twenty.
 *
 * Clearing before the boot leaves exactly the set a real forum has.
 */
trait ResetsVisibilityScopers
{
    protected function resetVisibilityScopers(): void
    {
        foreach ([Channel::class, Message::class, Thread::class, Upload::class] as $model) {
            $property = new \ReflectionProperty($model, 'visibilityScopers');
            $property->setValue(null, []);
        }
    }
}
