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
use Ramon\Chat\Message;
use Ramon\Chat\MessageMention;
use Ramon\Chat\Service\UnreadTracker;

/**
 * Two paths that used to reach around the query builder: the group-mention
 * expansion read `group_user` through a raw connection, and the per-channel
 * sequence concatenated the table prefix into its subquery by hand. Both now go
 * through Eloquent / the grammar, and these pin the behaviour they must keep.
 */
class GroupMentionAndNumberingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                ['id' => 3, 'username' => 'mod_one', 'email' => 'mod1@machine.local', 'password' => 'x', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'mod_two', 'email' => 'mod2@machine.local', 'password' => 'x', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'bystander', 'email' => 'by@machine.local', 'password' => 'x', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => Group::MODERATOR_ID],
                ['user_id' => 4, 'group_id' => Group::MODERATOR_ID],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'Um', 'slug' => 'um', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'type' => 'category', 'name' => 'Dois', 'slug' => 'dois', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_messages' => [
                ['id' => 1, 'channel_id' => 1, 'user_id' => 3, 'number' => 1, 'type' => 'text', 'content' => '<t>oi</t>', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'channel_id' => 1, 'user_id' => 3, 'number' => 2, 'type' => 'text', 'content' => '<t>oi</t>', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_message_mentions' => [
                ['id' => 1, 'message_id' => 1, 'type' => MessageMention::TYPE_GROUP, 'group_id' => Group::MODERATOR_ID, 'created_at' => $now],
            ],
        ]);
    }

    public function test_a_group_mention_expands_to_its_members_except_the_author(): void
    {
        $app = $this->app();

        $message = Message::query()->findOrFail(1);

        $ids = $app->getContainer()->make(UnreadTracker::class)->mentionedUserIds($message);

        $this->assertSame([4], $ids);
    }

    public function test_new_messages_continue_their_own_channels_sequence(): void
    {
        $this->app();

        // Eloquent boots a model once per process, but every test builds a fresh
        // app with a fresh event dispatcher, so the `creating` hook registered by
        // an earlier test is gone. Re-boot so this one is wired to the current app.
        Message::clearBootedModels();

        $inFirst = new Message();
        $inFirst->channel_id = 1;
        $inFirst->user_id = 3;
        $inFirst->type = 'text';
        $inFirst->content = '<t>tres</t>';
        $inFirst->save();

        $inSecond = new Message();
        $inSecond->channel_id = 2;
        $inSecond->user_id = 3;
        $inSecond->type = 'text';
        $inSecond->content = '<t>um</t>';
        $inSecond->save();

        $this->assertSame(3, $inFirst->number);
        $this->assertSame(1, $inSecond->number);
        $this->assertSame(3, (int) Message::query()->whereKey($inFirst->id)->value('number'));
    }
}
