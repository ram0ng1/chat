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

/**
 * Pinning, over the real API.
 *
 * The permission boundary is the reason this is an integration test rather than a
 * unit one: a policy that returns the right answer in isolation is worth little if
 * the endpoint never consults it, which is exactly the mistake the create-message
 * path made with `createThread` before it was wired up.
 */
class PinMessageTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'member', 'email' => 'member@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.startDirect'],
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
                    'content'    => 'pin me',
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ],
            ],
        ]);
    }

    public function test_admin_can_pin_and_unpin(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-messages/1/pin', ['authenticatedAs' => 1])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertTrue($body['data']['attributes']['isPinned']);
        $this->assertNotNull(Message::query()->find(1)->pinned_at);

        // The same route toggles, so two calls must leave it unpinned.
        $this->send($this->request('POST', '/api/chat-messages/1/pin', ['authenticatedAs' => 1]));

        $message = Message::query()->find(1);
        $this->assertNull($message->pinned_at);
        $this->assertNull($message->pinned_by_id, 'attribution is cleared with the timestamp');
    }

    public function test_a_member_without_the_permission_cannot_pin(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-messages/1/pin', ['authenticatedAs' => 3])
        );

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertNull(Message::query()->find(1)->pinned_at, 'a refused request changes nothing');
    }

    public function test_a_guest_cannot_pin(): void
    {
        $response = $this->send($this->request('POST', '/api/chat-messages/1/pin'));

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_pinned_messages_are_listable(): void
    {
        $this->send($this->request('POST', '/api/chat-messages/1/pin', ['authenticatedAs' => 1]));

        $response = $this->send(
            $this->request('GET', '/api/chat-messages', ['authenticatedAs' => 1])
                ->withQueryParams([
                    'filter' => ['channel' => '1', 'pinned' => '1'],
                    'sort'   => '-pinnedAt',
                ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $ids = array_map(
            fn (array $row) => (int) $row['id'],
            json_decode($response->getBody()->getContents(), true)['data']
        );

        $this->assertSame([1], $ids);
    }

    /** The key that must never regress: a listing without the filter is unaffected. */
    public function test_unpinned_messages_still_appear_in_the_channel(): void
    {
        $response = $this->send(
            $this->request('GET', '/api/chat-messages', ['authenticatedAs' => 3])
                ->withQueryParams(['filter' => ['channel' => '1']])
        );

        $ids = array_map(
            fn (array $row) => (int) $row['id'],
            json_decode($response->getBody()->getContents(), true)['data']
        );

        $this->assertContains(1, $ids);
    }
}
