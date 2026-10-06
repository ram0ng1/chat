<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Service;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use Ramon\Chat\Service\ActionThrottle;

/**
 * The counter behind the typing, reaction, draft, bookmark and webhook limits.
 */
class ActionThrottleTest extends TestCase
{
    public function test_it_allows_up_to_the_limit_and_refuses_the_next(): void
    {
        $throttle = new ActionThrottle(new Repository(new ArrayStore()));

        $this->assertTrue($throttle->attempt('typing.1.1.1', 1, 2));
        $this->assertFalse($throttle->attempt('typing.1.1.1', 1, 2), 'a second typing signal inside the window is dropped');
    }

    public function test_keys_are_counted_apart(): void
    {
        $throttle = new ActionThrottle(new Repository(new ArrayStore()));

        $this->assertTrue($throttle->attempt('typing.1.1.1', 1, 2));
        $this->assertTrue($throttle->attempt('typing.1.1.0', 1, 2), 'a stop is not dropped because a start was just sent');
        $this->assertTrue($throttle->attempt('typing.2.1.1', 1, 2), 'nor is someone else');
    }
}
