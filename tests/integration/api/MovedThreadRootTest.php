<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;
use Ramon\Chat\Thread;

/**
 * A thread's root moved into a private channel stays behind that channel.
 *
 * Moving used to null the message's `thread_id` and nothing else, so the thread
 * still named the root as `original_message_id`. ThreadResource eager-loaded
 * that relation, which core's relationship buffer then skipped, and the buffer is
 * the only place MessageResource's scope applies — so the moved message came
 * back in `included` to anyone who could list the public channel's threads.
 *
 * Two halves, asserted separately: the move detaches the root and recounts the
 * thread, and the listing scopes both message relations even when a thread
 * points across channels for any other reason.
 */
class MovedThreadRootTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const READER = 3;

    private const CH_PUBLIC = 1;
    private const CH_PRIVATE = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                ['id' => self::READER, 'username' => 'reader', 'password' => self::PASSWORD_HASH, 'email' => 'reader@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                ['id' => self::CH_PUBLIC, 'type' => 'category', 'name' => 'Open', 'slug' => 'open', 'status' => 'open', 'is_private' => 0, 'threading_enabled' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['id' => self::CH_PRIVATE, 'type' => 'category', 'name' => 'Staff', 'slug' => 'staff', 'status' => 'open', 'is_private' => 1, 'threading_enabled' => 1, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => self::CH_PUBLIC, 'user_id' => self::READER, 'created_at' => $now],
                ['channel_id' => self::CH_PUBLIC, 'user_id' => 1, 'created_at' => $now],
                ['channel_id' => self::CH_PRIVATE, 'user_id' => 1, 'created_at' => $now],
            ],
            'chat_messages' => [
                $this->message(1, self::CH_PUBLIC, 1, 'root that will move', 7),
                $this->message(2, self::CH_PUBLIC, 2, 'a reply that stays', 7),
                $this->message(3, self::CH_PRIVATE, 1, 'private words', null),
                $this->message(4, self::CH_PUBLIC, 3, 'root of the second thread', 8),
            ],
            'chat_threads' => [
                ['id' => 7, 'channel_id' => self::CH_PUBLIC, 'original_message_id' => 1, 'creator_id' => 1, 'status' => 'open', 'replies_count' => 1, 'last_message_id' => 2, 'created_at' => $now, 'updated_at' => $now],
                // Points its last reply into the private channel, the shape a stale
                // pointer from any cause would have.
                ['id' => 8, 'channel_id' => self::CH_PUBLIC, 'original_message_id' => 4, 'creator_id' => 1, 'status' => 'open', 'replies_count' => 0, 'last_message_id' => 3, 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function message(int $id, int $channelId, int $number, string $text, ?int $threadId): array
    {
        return [
            'id'         => $id,
            'channel_id' => $channelId,
            'user_id'    => 1,
            'number'     => $number,
            'thread_id'  => $threadId,
            'type'       => 'text',
            'content'    => '<t><p>'.$text.'</p></t>',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return int[] message ids in `included` */
    private function includedMessageIds(): array
    {
        $response = $this->send(
            $this->request('GET', '/api/chat-threads', ['authenticatedAs' => self::READER])
                ->withQueryParams([
                    'filter'  => ['channel' => (string) self::CH_PUBLIC],
                    'include' => 'originalMessage,lastMessage',
                ])
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $document = json_decode((string) $response->getBody(), true);

        return array_values(array_map(
            fn (array $row) => (int) $row['id'],
            array_filter($document['included'] ?? [], fn (array $row) => $row['type'] === 'chat-messages')
        ));
    }

    public function test_a_thread_never_includes_a_message_the_reader_cannot_see(): void
    {
        $ids = $this->includedMessageIds();

        $this->assertContains(4, $ids, 'the visible root is still included');
        $this->assertNotContains(3, $ids, 'the private last reply leaked through the eager load');
    }

    public function test_moving_a_root_detaches_it_from_its_thread(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat/messages/move', [
                'authenticatedAs' => 1,
                'json'            => ['data' => ['attributes' => ['messageIds' => [1], 'channelId' => self::CH_PRIVATE]]],
            ])
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $thread = Thread::query()->findOrFail(7);

        $this->assertNull($thread->original_message_id, 'the thread no longer points at a message in another channel');
        $this->assertSame(2, $thread->last_message_id, 'the reply that stayed is still the last one');
        $this->assertSame(1, $thread->replies_count);

        $this->assertNotContains(1, $this->includedMessageIds(), 'the moved root is not handed to a non-member');
    }
}
