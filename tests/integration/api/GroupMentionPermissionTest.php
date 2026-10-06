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
use Flarum\Notification\NotificationSyncer;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Mockery;
use Psr\Log\NullLogger;
use Ramon\Chat\Event\MessageWasSent;
use Ramon\Chat\Listener\SendChatNotifications;
use Ramon\Chat\Mention\MentionResolver;
use Ramon\Chat\Message;
use Ramon\Chat\MessageMention;
use Ramon\Chat\Notification\ChatMentionBlueprint;
use Ramon\Chat\Service\UnreadTracker;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Who may ping a group, and who is mailed about a mention.
 *
 * A plain-text `@Mods` fell back to a group lookup that asked nothing of the
 * author, so any member could ping a whole group, hidden ones included. Group
 * mentions now need core's `mentionGroups`, never name guests, members or a
 * hidden group, and reach only the group's members in the channel (see
 * GroupMentionAndNumberingTest for the expansion half).
 *
 * The mail half: `ramon-chat.emailNotifications` was registered and never read,
 * so turning it off changed nothing.
 */
class GroupMentionPermissionTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const AUTHOR = 3;
    private const MOD = 4;
    private const QUIET_MOD = 5;
    private const HIDDEN = 10;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                ['id' => self::AUTHOR, 'username' => 'author', 'email' => 'author@machine.local', 'password' => 'x', 'is_email_confirmed' => 1],
                ['id' => self::MOD, 'username' => 'mod', 'email' => 'mod@machine.local', 'password' => 'x', 'is_email_confirmed' => 1],
                [
                    'id' => self::QUIET_MOD, 'username' => 'quiet', 'email' => 'quiet@machine.local', 'password' => 'x', 'is_email_confirmed' => 1,
                    'preferences' => json_encode(['ramon-chat.emailNotifications' => false]),
                ],
            ],
            'groups' => [
                ['id' => self::HIDDEN, 'name_singular' => 'Secret', 'name_plural' => 'Secrets', 'is_hidden' => 1],
            ],
            'group_user' => [
                ['user_id' => self::MOD, 'group_id' => Group::MODERATOR_ID],
                ['user_id' => self::QUIET_MOD, 'group_id' => Group::MODERATOR_ID],
                ['user_id' => self::MOD, 'group_id' => self::HIDDEN],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'Geral', 'slug' => 'geral', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => 1, 'user_id' => self::AUTHOR, 'created_at' => $now],
                ['channel_id' => 1, 'user_id' => self::MOD, 'created_at' => $now],
                ['channel_id' => 1, 'user_id' => self::QUIET_MOD, 'created_at' => $now],
            ],
            'chat_messages' => [
                $this->message(1, '<t>hey @Mods look</t>'),
                $this->message(2, '<t>hey @Secrets look</t>'),
                $this->message(3, '<r>hey <GROUPMENTION groupname="Mods" id="4">@Mods</GROUPMENTION></r>'),
                $this->message(4, '<t>hey @Members</t>'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function message(int $id, string $content): array
    {
        return [
            'id'         => $id,
            'channel_id' => 1,
            'user_id'    => self::AUTHOR,
            'number'     => $id,
            'type'       => 'text',
            'content'    => $content,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    private function grantMentionGroups(): void
    {
        $this->database()->table('group_permission')->insert([
            'group_id' => Group::MEMBER_ID, 'permission' => 'mentionGroups',
        ]);
    }

    /** @return int[] */
    private function groupsMentionedIn(int $messageId): array
    {
        $resolver = $this->app()->getContainer()->make(MentionResolver::class);

        return $resolver->resolve(Message::query()->findOrFail($messageId), false)
            ->where('type', MessageMention::TYPE_GROUP)
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function test_a_member_without_the_permission_cannot_ping_a_group(): void
    {
        $this->assertSame([], $this->groupsMentionedIn(1), 'plain-text fallback');
        $this->assertSame([], $this->groupsMentionedIn(3), 'parsed GROUPMENTION');
    }

    public function test_the_permission_allows_a_visible_group(): void
    {
        $this->grantMentionGroups();

        $this->assertSame([Group::MODERATOR_ID], $this->groupsMentionedIn(1));
        $this->assertSame([Group::MODERATOR_ID], $this->groupsMentionedIn(3));
    }

    public function test_hidden_groups_and_members_are_never_mentionable(): void
    {
        $this->grantMentionGroups();

        $this->assertSame([], $this->groupsMentionedIn(2), 'hidden group');
        $this->assertSame([], $this->groupsMentionedIn(4), 'the members group');
    }

    public function test_the_chat_email_preference_is_honoured(): void
    {
        $this->app();

        $this->database()->table('chat_message_mentions')->insert([
            ['message_id' => 1, 'type' => MessageMention::TYPE_USER, 'user_id' => self::MOD, 'created_at' => Carbon::now()->toDateTimeString()],
            ['message_id' => 1, 'type' => MessageMention::TYPE_USER, 'user_id' => self::QUIET_MOD, 'created_at' => Carbon::now()->toDateTimeString()],
        ]);

        $mailed = null;

        $syncer = Mockery::mock(NotificationSyncer::class);
        $syncer->shouldReceive('sync')->andReturnUsing(function ($blueprint, array $users) use (&$mailed) {
            if ($blueprint instanceof ChatMentionBlueprint) {
                $mailed = array_map(fn (User $u) => (int) $u->id, $users);
            }
        });

        $listener = new SendChatNotifications(
            $syncer,
            $this->app()->getContainer()->make(UnreadTracker::class),
            new NullLogger()
        );

        $message = Message::query()->with(['channel', 'mentions'])->findOrFail(1);

        $listener->handle(new MessageWasSent($message, User::query()->find(self::AUTHOR)));

        $this->assertSame([self::MOD], $mailed, 'the member who turned chat mail off is not mailed');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
