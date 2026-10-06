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
use Pusher\Pusher;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\ChannelWasCreated;
use Ramon\Chat\Event\FlagsChanged;
use Ramon\Chat\Event\MessagesWereMoved;
use Ramon\Chat\Event\ThreadWasEdited;
use Ramon\Chat\Realtime\BroadcastListener;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * The state changes that reached nobody live until this pass: a rename of a
 * thread, a move of messages between channels, a channel created with members
 * already in it, and the moderation queue.
 *
 * flarum/realtime is not installed here, so its listeners are not registered:
 * the domain events are asserted on the dispatcher, and the payloads by calling
 * the listener against a recording stand-in for the daemon.
 */
class LiveStateBroadcastTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const READER = 3;
    private const OUTSIDER = 4;
    private const MOD = 5;

    private const CH_PUBLIC = 1;
    private const CH_PRIVATE = 2;

    /** @var array<int, object> */
    private array $dispatched = [];

    private ?object $recorder = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                $this->user(self::READER, 'reader'),
                $this->user(self::OUTSIDER, 'outsider'),
                $this->user(self::MOD, 'moderator'),
            ],
            'groups' => [
                ['id' => 101, 'name_singular' => 'Chatmod', 'name_plural' => 'Chatmods'],
            ],
            'group_user' => [
                ['user_id' => self::MOD, 'group_id' => 101],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => 101, 'permission' => 'ramon-chat.moderate'],
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
                $this->message(1, self::CH_PUBLIC, 1, 'root', null),
                $this->message(2, self::CH_PUBLIC, 2, 'a reply that moves', 7),
                $this->message(3, self::CH_PUBLIC, 3, 'stays', null),
            ],
            'chat_threads' => [
                ['id' => 7, 'channel_id' => self::CH_PUBLIC, 'original_message_id' => 1, 'creator_id' => 1, 'status' => 'open', 'replies_count' => 1, 'last_message_id' => 2, 'title' => 'Before', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    private function listen(string $event): void
    {
        $this->app()->getContainer()->make('events')->listen($event, function ($payload) {
            $this->dispatched[] = $payload;
        });
    }

    private function bindRecorder(): void
    {
        $recorder = new class('key', 'secret', '1') extends Pusher {
            /** @var array<int, mixed> */
            public array $calls = [];

            public function trigger($channels, string $event, $data, array $params = [], bool $already_encoded = false): object
            {
                $this->calls[] = ['channels' => (array) $channels, 'event' => $event, 'data' => $data];

                return new \stdClass();
            }
        };

        $this->app()->getContainer()->instance(Pusher::class, $recorder);

        $this->recorder = $recorder;
    }

    /** @return array<int, array{channels: string[], event: string, data: mixed}> */
    private function pushes(): array
    {
        return $this->recorder->calls ?? [];
    }

    public function test_renaming_a_thread_saves_it_and_announces_it(): void
    {
        $this->listen(ThreadWasEdited::class);

        $response = $this->send($this->request('PATCH', '/api/chat-threads/7', [
            'authenticatedAs' => 1,
            'json'            => ['data' => ['type' => 'chat-threads', 'id' => '7', 'attributes' => ['title' => 'After']]],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('After', $this->database()->table('chat_threads')->where('id', 7)->value('title'), 'the rename is written');
        $this->assertCount(1, $this->dispatched);
    }

    public function test_a_move_is_announced_once_after_it_commits(): void
    {
        $this->listen(MessagesWereMoved::class);

        $response = $this->send($this->request('POST', '/api/chat/messages/move', [
            'authenticatedAs' => 1,
            'json'            => ['data' => ['attributes' => ['messageIds' => [2, 3], 'channelId' => self::CH_PRIVATE]]],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertCount(1, $this->dispatched, 'one event for the whole move');

        /** @var MessagesWereMoved $event */
        $event = $this->dispatched[0];

        $this->assertSame([self::CH_PUBLIC => [2, 3]], $event->messageIdsBySource);
        $this->assertSame([2 => 7], $event->threadIds);
        $this->assertSame(self::CH_PRIVATE, (int) $event->target->id);
    }

    public function test_a_move_tells_each_room_only_its_own_side(): void
    {
        $this->bindRecorder();

        $this->app()->getContainer()->make(BroadcastListener::class)->whenMessagesMoved(new MessagesWereMoved(
            [self::CH_PUBLIC => [2]],
            [self::CH_PUBLIC => Channel::query()->findOrFail(self::CH_PUBLIC)],
            Channel::query()->findOrFail(self::CH_PRIVATE),
            [2 => 7]
        ));

        $pushes = $this->pushes();

        $this->assertCount(2, $pushes);

        $source = $pushes[0];
        $this->assertSame(BroadcastListener::EVENT_MESSAGES_MOVED, $source['event']);
        $this->assertEqualsCanonicalizing(['private-user=1', 'private-user='.self::READER], $source['channels']);
        $this->assertSame([2], $source['data']['movedOut']);
        $this->assertArrayNotHasKey('movedIn', $source['data']);
        $this->assertSame(7, $source['data']['threads'][0]['threadId']);

        $target = $pushes[1];
        $this->assertSame(['private-user=1'], $target['channels'], 'only the private room hears what arrived in it');
        $this->assertSame([2], $target['data']['movedIn']);
        $this->assertArrayNotHasKey('movedOut', $target['data']);
    }

    public function test_a_new_channel_is_announced_to_its_members_but_not_its_creator(): void
    {
        $this->bindRecorder();

        $this->app()->getContainer()->make(BroadcastListener::class)->whenChannelCreated(new ChannelWasCreated(
            Channel::query()->findOrFail(self::CH_PUBLIC),
            \Flarum\User\User::query()->findOrFail(1)
        ));

        $pushes = $this->pushes();

        $this->assertCount(1, $pushes);
        $this->assertSame(['private-user='.self::READER], $pushes[0]['channels']);
        $this->assertSame(['channelId' => self::CH_PUBLIC, 'status' => 'open', 'created' => true], $pushes[0]['data']);
    }

    public function test_the_moderation_queue_reaches_moderators_only(): void
    {
        $this->bindRecorder();
        $this->listen(FlagsChanged::class);

        $response = $this->send($this->request('POST', '/api/chat-message-flags', [
            'authenticatedAs' => self::READER,
            'json'            => ['data' => ['type' => 'chat-message-flags', 'attributes' => ['messageId' => 1, 'reason' => 'spam']]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertCount(1, $this->dispatched, 'filing a report moves the queue');

        $this->app()->getContainer()->make(BroadcastListener::class)->whenFlagsChanged(new FlagsChanged());

        $pushes = $this->pushes();

        $this->assertCount(1, $pushes);
        $this->assertSame(BroadcastListener::EVENT_FLAGS, $pushes[0]['event']);
        $this->assertEqualsCanonicalizing(['private-user=1', 'private-user='.self::MOD], $pushes[0]['channels']);
    }

    /** @return array<string, mixed> */
    private function user(int $id, string $username): array
    {
        return [
            'id'                 => $id,
            'username'           => $username,
            'password'           => self::PASSWORD_HASH,
            'email'              => $username.'@machine.local',
            'is_email_confirmed' => 1,
        ];
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
}
