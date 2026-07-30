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

/**
 * The message model's derived state.
 *
 * These read as trivial, and they are — but each one is a boundary the UI branches
 * on, and getting `isRedacted` wrong in particular means either leaking a removed
 * message's text or showing a tombstone to its own author.
 */
class MessageTest extends QueryTestCase
{
    protected function message(array $attributes = []): Message
    {
        $message = new Message();

        // setRawAttributes, not fill(): these are read paths, and going through the
        // model's casts is the point of the test.
        $message->setRawAttributes(array_merge([
            'id'         => 1,
            'channel_id' => 1,
            'type'       => Message::TYPE_TEXT,
            'content'    => 'hello',
        ], $attributes), true);

        return $message;
    }

    public function test_a_text_message_is_not_a_system_message(): void
    {
        $this->assertFalse($this->message()->isSystem());
    }

    public function test_a_system_message_is_recognised(): void
    {
        $this->assertTrue($this->message(['type' => Message::TYPE_SYSTEM])->isSystem());
    }

    public function test_deletion_is_decided_by_the_timestamp(): void
    {
        $this->assertFalse($this->message()->isDeleted());
        $this->assertTrue($this->message(['deleted_at' => '2026-01-01 00:00:00'])->isDeleted());
    }

    /*
     * Redaction — a deleted message whose text is withheld — is deliberately NOT
     * tested here. On the server it is a decision about an actor and lives in
     * MessageResource::isRedacted(Message, User), so it belongs to the integration
     * suite where a real actor exists. The no-argument `isRedacted()` is the client
     * model's inference from the payload it received, which is a different question
     * and is TypeScript.
     *
     * This is worth stating rather than leaving as a gap: the two names look like the
     * same method, and asserting the client's meaning against the server's model was
     * the first thing this file got wrong.
     */

    public function test_editing_is_decided_by_the_timestamp(): void
    {
        $this->assertFalse($this->message()->isEdited());
        $this->assertTrue($this->message(['edited_at' => '2026-01-01 00:00:00'])->isEdited());
    }

    /**
     * `pinned_at` alone decides a pin; `pinned_by_id` is attribution and is nulled by
     * a user deletion, so it must not be consulted.
     */
    public function test_pinning_is_decided_by_the_timestamp_alone(): void
    {
        $this->assertFalse($this->message()->isPinned());

        $this->assertTrue(
            $this->message(['pinned_at' => '2026-01-01 00:00:00', 'pinned_by_id' => null])->isPinned(),
            'a pin whose author was deleted is still a pin'
        );

        $this->assertFalse(
            $this->message(['pinned_by_id' => 5])->isPinned(),
            'an attribution without a timestamp is not a pin'
        );
    }

    public function test_pinned_at_is_cast_to_a_date(): void
    {
        $message = $this->message(['pinned_at' => '2026-01-01 12:00:00']);

        $this->assertInstanceOf(Carbon::class, $message->pinned_at);
    }
}
