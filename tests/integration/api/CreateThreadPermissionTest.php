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
use Ramon\Chat\Message;
use Ramon\Chat\Thread;

/**
 * `ramon-chat.createThread` gates *starting* a thread, and nothing else.
 *
 * Someone without it must still be able to talk: a plain channel message, and a
 * reply inside a thread that already exists. Getting this wrong in the restrictive
 * direction would silence people rather than merely limit them, which is why both
 * halves are asserted here rather than only the refusal.
 */
class CreateThreadPermissionTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'brancher', 'email' => 'brancher@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                // Deliberately NOT granting createThread to members; user 3 gets it
                // through a group of its own so the two cases differ by exactly that
                // permission and nothing else.
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Brancher', 'name_plural' => 'Branchers'],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 100],
            ],
            'chat_channels' => [
                [
                    'id'                => 1,
                    'type'              => 'category',
                    'name'              => 'general',
                    'slug'              => 'general',
                    'status'            => 'open',
                    'threading_enabled' => 1,
                    'created_at'        => Carbon::now()->toDateTimeString(),
                    'updated_at'        => Carbon::now()->toDateTimeString(),
                ],
            ],
            'chat_channel_user' => [
                ['channel_id' => 1, 'user_id' => 2, 'following' => 1, 'created_at' => Carbon::now()->toDateTimeString()],
                ['channel_id' => 1, 'user_id' => 3, 'following' => 1, 'created_at' => Carbon::now()->toDateTimeString()],
            ],
            'chat_messages' => [
                [
                    'id'         => 1,
                    'channel_id' => 1,
                    'user_id'    => 2,
                    'number'     => 1,
                    'type'       => 'text',
                    'content'    => 'branch off me',
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ],
            ],
        ]);

        $this->database()->table('group_permission')->insert([
            'group_id'   => 100,
            'permission' => 'ramon-chat.createThread',
        ]);
    }

    protected function send_message(int $as, array $attributes)
    {
        return $this->send(
            $this->request('POST', '/api/chat-messages', [
                'authenticatedAs' => $as,
                'json'            => ['data' => ['attributes' => array_merge(['channelId' => 1], $attributes)]],
            ])
        );
    }

    public function test_without_the_permission_a_plain_message_still_sends(): void
    {
        $response = $this->send_message(2, ['content' => 'just talking']);

        $this->assertEquals(201, $response->getStatusCode());
    }

    public function test_without_the_permission_starting_a_thread_is_refused(): void
    {
        $response = $this->send_message(2, [
            'content'      => 'branching',
            'replyToId'    => 1,
            'createThread' => true,
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertSame(0, Thread::query()->count(), 'no thread was created');
    }

    public function test_with_the_permission_a_thread_is_created(): void
    {
        $response = $this->send_message(3, [
            'content'      => 'branching',
            'replyToId'    => 1,
            'createThread' => true,
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $this->assertSame(1, Thread::query()->count());
    }

    public function test_replying_inside_an_existing_thread_needs_no_permission(): void
    {
        // Created by the user who does hold the permission.
        $this->send_message(3, ['content' => 'root', 'replyToId' => 1, 'createThread' => true]);

        $thread = Thread::query()->firstOrFail();

        $response = $this->send_message(2, [
            'content'  => 'me too',
            'threadId' => $thread->id,
        ]);

        $this->assertEquals(201, $response->getStatusCode(), 'replying is not gated by createThread');

        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals($thread->id, $body['data']['attributes']['threadId']);
    }

    /** Threads are one level deep: a reply inside one cannot be branched again. */
    public function test_a_thread_reply_cannot_be_branched(): void
    {
        $this->send_message(3, ['content' => 'root', 'replyToId' => 1, 'createThread' => true]);

        $thread = Thread::query()->firstOrFail();
        $reply = Message::query()->where('thread_id', $thread->id)->latest('id')->firstOrFail();

        $response = $this->send_message(3, [
            'content'      => 'nesting',
            'replyToId'    => $reply->id,
            'createThread' => true,
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertSame(1, Thread::query()->count());
    }
}
