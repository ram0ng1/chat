<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit;

use Carbon\Carbon;
use Ramon\Chat\Message;
use Ramon\Chat\Thread;

/**
 * The thread's reply counters.
 *
 * `noteReply` exists because the send path used to recount the whole thread on
 * every reply — a COUNT that grew with the thread, so the busiest threads were the
 * slowest to post in. The arithmetic is small enough to look self-evident, which is
 * exactly why it is worth pinning: if it ever drifts from what `refreshMetadata`
 * would compute, the reply count in the UI silently stops matching the messages.
 */
class ThreadTest extends QueryTestCase
{
    protected function thread(array $attributes = []): Thread
    {
        $thread = new Thread();

        $thread->setRawAttributes(array_merge([
            'id'                  => 7,
            'channel_id'          => 1,
            'original_message_id' => 100,
            'replies_count'       => 0,
            'last_message_id'     => null,
            'last_message_at'     => null,
        ], $attributes), true);

        return $thread;
    }

    protected function message(int $id, string $createdAt = '2026-07-30 10:00:00'): Message
    {
        $message = new Message();

        $message->setRawAttributes([
            'id'         => $id,
            'channel_id' => 1,
            'thread_id'  => 7,
            'created_at' => $createdAt,
        ], true);

        return $message;
    }

    public function test_a_reply_advances_the_counters(): void
    {
        $thread = $this->thread();

        $thread->noteReply($this->message(101, '2026-07-30 10:05:00'));

        $this->assertSame(1, $thread->replies_count);
        $this->assertSame(101, (int) $thread->last_message_id);
        $this->assertInstanceOf(Carbon::class, $thread->last_message_at);
        $this->assertSame('2026-07-30 10:05:00', $thread->last_message_at->toDateTimeString());
    }

    public function test_replies_accumulate(): void
    {
        $thread = $this->thread(['replies_count' => 12, 'last_message_id' => 140]);

        $thread->noteReply($this->message(141));

        $this->assertSame(13, $thread->replies_count);
        $this->assertSame(141, (int) $thread->last_message_id);
    }

    /**
     * The root is the message the thread hangs off, not a reply to it —
     * `refreshMetadata` excludes it from its COUNT, so the incremental path has to
     * exclude it too or the two disagree by one for the thread's whole life.
     */
    public function test_the_root_message_is_not_a_reply(): void
    {
        $thread = $this->thread(['replies_count' => 3, 'last_message_id' => 130]);

        $thread->noteReply($this->message(100));

        $this->assertSame(3, $thread->replies_count);
        $this->assertSame(130, (int) $thread->last_message_id);
    }

    /**
     * A thread created from a reply may not have its root back-filled yet. There is
     * no id to compare against, so every message counts — which is correct: with no
     * root recorded, nothing is excluded from the recount either.
     */
    public function test_counts_normally_when_there_is_no_root_yet(): void
    {
        $thread = $this->thread(['original_message_id' => null]);

        $thread->noteReply($this->message(101));

        $this->assertSame(1, $thread->replies_count);
    }

    /**
     * The counter is read back from the database as a string on some drivers; adding
     * to it without casting would concatenate rather than increment.
     */
    public function test_a_string_counter_still_increments(): void
    {
        $thread = $this->thread(['replies_count' => '5']);

        $thread->noteReply($this->message(101));

        $this->assertSame(6, $thread->replies_count);
    }
}
