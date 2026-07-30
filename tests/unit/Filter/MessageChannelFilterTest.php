<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Filter;

use Ramon\Chat\Message;
use Ramon\Chat\Search\Filter\MessageChannelFilter;
use Ramon\Chat\Tests\unit\QueryTestCase;

/**
 * The channel filter decides what the main stream shows, and the "keep thread roots
 * but drop their replies" clause is the part most likely to be broken by a careless
 * edit — it is also what the client relies on when it refuses to append a realtime
 * thread reply to the channel window.
 */
class MessageChannelFilterTest extends QueryTestCase
{
    public function test_filter_key_is_channel(): void
    {
        $this->assertSame('channel', (new MessageChannelFilter())->getFilterKey());
    }

    public function test_it_scopes_to_the_channel(): void
    {
        $query = Message::query();

        (new MessageChannelFilter())->filter($this->state($query), '7', false);

        $sql = $this->sql($query);

        $this->assertStringContainsString('"chat_messages"."channel_id" =', $sql);
        $this->assertContains(7, $query->getBindings());
    }

    /**
     * Thread replies are excluded by default: the stream shows a thread as one root
     * message with an indicator, not as every reply inlined.
     */
    public function test_thread_replies_are_excluded_by_default(): void
    {
        $query = Message::query();

        (new MessageChannelFilter())->filter($this->state($query), '7', false);

        $sql = $this->sql($query);

        $this->assertStringContainsString('"chat_messages"."thread_id" is null', $sql);
        // The root is kept via a correlated existence check against chat_threads.
        $this->assertStringContainsString('exists', $sql);
        $this->assertStringContainsString('chat_threads', $sql);
    }

    /**
     * A non-zero channel id is required; zero or a non-numeric value must not
     * produce `channel_id = 0`, which would quietly return nothing.
     */
    public function test_zero_channel_adds_no_predicate(): void
    {
        $query = Message::query();
        $before = $this->sql($query);

        (new MessageChannelFilter())->filter($this->state($query), '0', false);

        $this->assertSame($before, $this->sql($query));
    }

    /**
     * Negated, the filter means "not this channel" and the thread-reply clause must
     * not be applied — excluding replies from *other* channels is not what was asked.
     */
    public function test_negated_filter_skips_the_thread_clause(): void
    {
        $query = Message::query();

        (new MessageChannelFilter())->filter($this->state($query), '7', true);

        $sql = $this->sql($query);

        $this->assertStringContainsString('"chat_messages"."channel_id" !=', $sql);
        $this->assertStringNotContainsString('chat_threads', $sql);
    }
}
