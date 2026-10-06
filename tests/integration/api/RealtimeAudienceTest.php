<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\TestCase;
use Pusher\Pusher;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\ChannelWasDeleted;
use Ramon\Chat\Realtime\BroadcastListener;
use Ramon\Chat\Realtime\ChatBroadcaster;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Who a websocket push reaches.
 *
 * Direct and untagged channels used to deliver to every live membership with no
 * further check, so a member whose `ramon-chat.use` was revoked (or who was
 * suspended, which demotes their groups) kept receiving private messages live.
 *
 * And a deleted channel was never announced at all, so its members kept a
 * sidebar row that answered with a 404. Its audience has to survive the
 * deletion: the visibility scope now excludes the channel, so the job may not
 * ask it.
 *
 * flarum/realtime is not installed here, so the daemon is a recording stand-in
 * bound where ChatBroadcaster looks for it.
 */
class RealtimeAudienceTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const CHATTER = 3;
    private const REVOKED = 4;
    private const CHAT_GROUP = 100;

    /** The stand-in daemon, with every trigger it was handed. */
    private ?object $recorder = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                ['id' => self::CHATTER, 'username' => 'chatter', 'email' => 'chatter@machine.local', 'password' => 'x', 'is_email_confirmed' => 1],
                ['id' => self::REVOKED, 'username' => 'revoked', 'email' => 'revoked@machine.local', 'password' => 'x', 'is_email_confirmed' => 1],
            ],
            'groups' => [
                ['id' => self::CHAT_GROUP, 'name_singular' => 'Chatter', 'name_plural' => 'Chatters'],
            ],
            'group_user' => [
                ['user_id' => self::CHATTER, 'group_id' => self::CHAT_GROUP],
            ],
            // The chat granted to one group only: REVOKED holds memberships but
            // not the permission, the state a revocation or suspension leaves.
            'group_permission' => [
                ['group_id' => self::CHAT_GROUP, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'direct', 'name' => 'dm', 'slug' => 'dm', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'type' => 'category', 'name' => 'Geral', 'slug' => 'geral', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => 1, 'user_id' => self::CHATTER, 'created_at' => $now],
                ['channel_id' => 1, 'user_id' => self::REVOKED, 'created_at' => $now],
                ['channel_id' => 2, 'user_id' => self::CHATTER, 'created_at' => $now],
                ['channel_id' => 2, 'user_id' => self::REVOKED, 'created_at' => $now],
            ],
        ]);
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

        // The install grants the chat to members by default; taken back here so
        // that only CHAT_GROUP holds it.
        $this->database()->table('group_permission')
            ->where('permission', 'ramon-chat.use')
            ->where('group_id', '!=', self::CHAT_GROUP)
            ->delete();
        $this->recorder = $recorder;
    }

    /** @return array<int, array{channels: string[], event: string, data: mixed}> */
    private function pushes(): array
    {
        return $this->recorder->calls ?? [];
    }

    public function test_a_direct_channel_push_skips_members_without_the_chat(): void
    {
        $this->bindRecorder();

        $this->app()->getContainer()->make(ChatBroadcaster::class)->toChannelMembers(
            Channel::query()->findOrFail(1),
            BroadcastListener::EVENT_MESSAGE,
            ['id' => 1],
            null
        );

        $pushes = $this->pushes();

        $this->assertCount(1, $pushes);
        $this->assertSame(['private-user='.self::CHATTER], $pushes[0]['channels']);
    }

    public function test_a_deleted_channel_is_announced_to_its_members(): void
    {
        $this->bindRecorder();

        $channel = Channel::query()->findOrFail(2);
        $channel->deleted_at = Carbon::now();
        $channel->save();

        $this->app()->getContainer()->make(BroadcastListener::class)->whenChannelDeleted(new ChannelWasDeleted($channel));

        $pushes = $this->pushes();

        $this->assertCount(1, $pushes, 'the deletion reached the websocket');
        $this->assertSame(BroadcastListener::EVENT_CHANNEL, $pushes[0]['event']);
        $this->assertSame(['private-user='.self::CHATTER], $pushes[0]['channels'], 'members who may use the chat, and only them');
        $this->assertSame(['channelId' => 2, 'status' => 'open', 'deleted' => true], $pushes[0]['data']);
    }
}
