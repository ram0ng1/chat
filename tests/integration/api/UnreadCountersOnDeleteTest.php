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
use Flarum\Testing\integration\TestCase;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\MessageMention;
use Ramon\Chat\Service\UnreadTracker;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Unread counters after a delete or a move, kept by targeted updates.
 *
 * The listener used to recount every membership of the channel from source, two
 * COUNTs per member per event. It now adjusts only the members still counting
 * the message, by one. These pin that the shortcut lands exactly where the full
 * recount would: every live, unmuted membership is compared with
 * `UnreadTracker::recalculate()` after the change.
 */
class UnreadCountersOnDeleteTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const AUTHOR = 3;
    private const BEHIND = 4;
    private const CAUGHT_UP = 5;
    private const MUTED = 6;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $users = [];

        foreach ([self::AUTHOR => 'author', self::BEHIND => 'behind', self::CAUGHT_UP => 'caught', self::MUTED => 'muted'] as $id => $name) {
            $users[] = ['id' => $id, 'username' => $name, 'email' => $name.'@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1];
        }

        $this->prepareDatabase([
            'users' => $users,
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'One', 'slug' => 'one', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'type' => 'category', 'name' => 'Two', 'slug' => 'two', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                $this->membership(1, self::AUTHOR, 3),
                $this->membership(1, self::BEHIND, null),
                $this->membership(1, self::CAUGHT_UP, 2),
                $this->membership(1, self::MUTED, null, muted: true),
                $this->membership(1, 1, 3),
                $this->membership(2, 1, null),
                // Read up to an id below the message that will move in.
                $this->membership(2, self::BEHIND, null),
            ],
            'chat_messages' => [
                $this->message(1, 1),
                $this->message(2, 2),
                $this->message(3, 3),
            ],
            'chat_message_mentions' => [
                ['message_id' => 2, 'type' => MessageMention::TYPE_USER, 'user_id' => self::BEHIND, 'created_at' => $now],
                ['message_id' => 2, 'type' => MessageMention::TYPE_USER, 'user_id' => self::MUTED, 'created_at' => $now],
                ['message_id' => 3, 'type' => MessageMention::TYPE_USER, 'user_id' => self::BEHIND, 'created_at' => $now],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function membership(int $channelId, int $userId, ?int $lastRead, bool $muted = false): array
    {
        return [
            'channel_id'           => $channelId,
            'user_id'              => $userId,
            'last_read_message_id' => $lastRead,
            'muted'                => $muted ? 1 : 0,
            'created_at'           => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function message(int $id, int $number): array
    {
        return [
            'id'         => $id,
            'channel_id' => 1,
            'user_id'    => self::AUTHOR,
            'number'     => $number,
            'type'       => 'text',
            'content'    => '<t>message '.$id.'</t>',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * Brings every counter to what the full recount says, so the test starts
     * from the state the send path would have left. The muted member's unread
     * count stays at zero, as recordNewMessage() leaves it; only mentions reach
     * a muted membership.
     */
    private function seedCounters(UnreadTracker $tracker): void
    {
        foreach (ChannelUser::query()->get() as $membership) {
            $tracker->recalculate($membership);

            if ($membership->muted) {
                $membership->unread_count = 0;
            }

            $membership->save();
        }
    }

    private function assertMatchesRecount(UnreadTracker $tracker): void
    {
        foreach (ChannelUser::query()->where('muted', false)->get() as $membership) {
            $stored = [(int) $membership->unread_count, (int) $membership->unread_mentions_count];
            $recounted = $tracker->recalculate(clone $membership);

            $this->assertSame(
                [(int) $recounted->unread_count, (int) $recounted->unread_mentions_count],
                $stored,
                "channel {$membership->channel_id}, user {$membership->user_id}"
            );
        }
    }

    private function counters(int $channelId, int $userId): array
    {
        $row = ChannelUser::query()->where('channel_id', $channelId)->where('user_id', $userId)->firstOrFail();

        return [(int) $row->unread_count, (int) $row->unread_mentions_count];
    }

    public function test_deleting_a_message_adjusts_only_the_members_counting_it(): void
    {
        $tracker = $this->app()->getContainer()->make(UnreadTracker::class);
        $this->seedCounters($tracker);

        $this->assertSame([3, 2], $this->counters(1, self::BEHIND));
        $this->assertSame([1, 0], $this->counters(1, self::CAUGHT_UP));

        $response = $this->send(
            $this->request('POST', '/api/chat-messages/2/delete', ['authenticatedAs' => self::AUTHOR])
        );

        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame([2, 1], $this->counters(1, self::BEHIND));
        $this->assertSame([1, 0], $this->counters(1, self::CAUGHT_UP), 'read past it, so never counting it');
        $this->assertSame([0, 0], $this->counters(1, self::MUTED), 'the mention leaves a muted membership too');
        $this->assertSame([0, 0], $this->counters(1, self::AUTHOR));

        $this->assertMatchesRecount($tracker);
    }

    public function test_moving_a_message_moves_its_unread_pressure(): void
    {
        $tracker = $this->app()->getContainer()->make(UnreadTracker::class);
        $this->seedCounters($tracker);

        $response = $this->send(
            $this->request('POST', '/api/chat/messages/move', [
                'authenticatedAs' => 1,
                'json'            => ['data' => ['attributes' => ['messageIds' => [3], 'channelId' => 2]]],
            ])
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame([2, 1], $this->counters(1, self::BEHIND), 'left the source channel');
        $this->assertSame([1, 1], $this->counters(2, self::BEHIND), 'joined the destination');

        $this->assertMatchesRecount($tracker);
    }

    public function test_counters_never_go_below_zero(): void
    {
        $this->app();

        $response = $this->send(
            $this->request('POST', '/api/chat-messages/2/delete', ['authenticatedAs' => self::AUTHOR])
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame([0, 0], $this->counters(1, self::BEHIND));
    }
}
