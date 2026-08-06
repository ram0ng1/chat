<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Access;

use Flarum\User\User;
use PHPUnit\Framework\TestCase;
use Ramon\Chat\Access\VisibilityCache;

/**
 * The memo behind the policies' visibility answers.
 *
 * The nesting test is the one that matters. Resolvers nest — answering "can this
 * actor see thread 5" asks "can they see channel 1" from inside its own
 * `remember()` — and an earlier implementation held a plain array, so the outer
 * call wrote back a copy taken before the inner one ran and silently discarded
 * it. Nothing failed; the cache simply stopped caching, and a list of twenty
 * threads in two channels ran twenty visibility queries instead of two.
 */
class VisibilityCacheTest extends TestCase
{
    protected function actor(int $id): User
    {
        $user = new User();
        $user->setRawAttributes(['id' => $id], true);

        return $user;
    }

    public function test_the_resolver_runs_once_per_key(): void
    {
        $cache = new VisibilityCache();
        $actor = $this->actor(1);
        $calls = 0;

        $resolve = function () use (&$calls) {
            $calls++;

            return true;
        };

        $this->assertTrue($cache->remember($actor, 'channel', 7, $resolve));
        $this->assertTrue($cache->remember($actor, 'channel', 7, $resolve));
        $this->assertTrue($cache->remember($actor, 'channel', 7, $resolve));

        $this->assertSame(1, $calls);
    }

    public function test_a_negative_answer_is_cached_too(): void
    {
        $cache = new VisibilityCache();
        $actor = $this->actor(1);
        $calls = 0;

        $resolve = function () use (&$calls) {
            $calls++;

            return false;
        };

        $this->assertFalse($cache->remember($actor, 'channel', 7, $resolve));
        $this->assertFalse($cache->remember($actor, 'channel', 7, $resolve));

        $this->assertSame(1, $calls, 'a cached "no" must not be re-resolved');
    }

    public function test_answers_do_not_leak_between_actors(): void
    {
        $cache = new VisibilityCache();

        $this->assertTrue($cache->remember($this->actor(1), 'channel', 7, fn () => true));
        $this->assertFalse($cache->remember($this->actor(2), 'channel', 7, fn () => false));
    }

    public function test_keys_of_different_types_do_not_collide(): void
    {
        $cache = new VisibilityCache();
        $actor = $this->actor(1);

        $this->assertTrue($cache->remember($actor, 'channel', 7, fn () => true));
        $this->assertFalse($cache->remember($actor, 'message', 7, fn () => false));
    }

    /**
     * The regression this class was rewritten for: what a nested resolver stores
     * has to survive the outer call returning.
     */
    public function test_a_nested_answer_survives_the_outer_call(): void
    {
        $cache = new VisibilityCache();
        $actor = $this->actor(1);
        $channelLookups = 0;

        $channel = function () use ($cache, $actor, &$channelLookups) {
            return $cache->remember($actor, 'channel', 1, function () use (&$channelLookups) {
                $channelLookups++;

                return true;
            });
        };

        // Each thread resolves its own answer by asking about the same channel,
        // exactly as MessagePolicy and ThreadPolicy do.
        foreach ([10, 11, 12, 13] as $threadId) {
            $cache->remember($actor, 'thread', $threadId, $channel);
        }

        $this->assertSame(
            1,
            $channelLookups,
            'the channel answer stored by a nested resolver was discarded by the outer write'
        );
    }

    public function test_flush_drops_everything(): void
    {
        $cache = new VisibilityCache();
        $actor = $this->actor(1);
        $calls = 0;

        $resolve = function () use (&$calls) {
            $calls++;

            return true;
        };

        $cache->remember($actor, 'channel', 7, $resolve);
        $cache->flush();
        $cache->remember($actor, 'channel', 7, $resolve);

        $this->assertSame(2, $calls);
    }
}
