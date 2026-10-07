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
use Illuminate\Contracts\Cache\Repository;
use Ramon\Chat\Message;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;
use Ramon\Chat\Webhook;

/**
 * Who a webhook delivery is attributed to.
 *
 * Deliveries used to be stored as authorless text messages, which the stream
 * draws as a deleted account. With no account chosen they are bot messages; with
 * one they belong to that administrator, and nobody else may be chosen.
 */
class WebhookAuthorTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const ADMIN = 1;
    private const MEMBER = 3;
    private const DEMOTED = 4;

    /** Built rather than written out, so secret scanners do not read a fixture as a credential. */
    private const KEY_BOT = 'test'.'webhook'.'bot'.'00000000000000000000000000000000000';
    private const KEY_ADMIN = 'test'.'webhook'.'adm'.'00000000000000000000000000000000000';
    private const KEY_DEMOTED = 'test'.'webhook'.'dem'.'00000000000000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                ['id' => self::MEMBER, 'username' => 'member', 'email' => 'member@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1],
                ['id' => self::DEMOTED, 'username' => 'demoted', 'email' => 'demoted@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'Geral', 'slug' => 'geral', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_webhooks' => [
                ['id' => 1, 'name' => 'bot', 'channel_id' => 1, 'key' => self::KEY_BOT, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'name' => 'admin', 'channel_id' => 1, 'user_id' => self::ADMIN, 'key' => self::KEY_ADMIN, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['id' => 3, 'name' => 'demoted', 'channel_id' => 1, 'user_id' => self::DEMOTED, 'key' => self::KEY_DEMOTED, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    private function deliver(string $key): int
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        return $this->send(
            $this->request('POST', '/api/chat/hooks/'.$key, [
                'json' => ['text' => 'hello from CI'],
            ])
        )->getStatusCode();
    }

    public function test_without_an_account_a_delivery_is_a_bot_message(): void
    {
        $this->assertSame(201, $this->deliver(self::KEY_BOT));

        $message = Message::query()->where('webhook_id', 1)->firstOrFail();

        $this->assertSame(Message::TYPE_BOT, $message->type);
        $this->assertNull($message->user_id);
    }

    public function test_a_delivery_is_posted_as_the_chosen_admin(): void
    {
        $this->assertSame(201, $this->deliver(self::KEY_ADMIN));

        $message = Message::query()->where('webhook_id', 2)->firstOrFail();

        $this->assertSame(Message::TYPE_TEXT, $message->type);
        $this->assertSame(self::ADMIN, $message->user_id);
    }

    public function test_an_account_that_is_no_longer_admin_falls_back_to_the_bot(): void
    {
        $this->assertSame(201, $this->deliver(self::KEY_DEMOTED));

        $message = Message::query()->where('webhook_id', 3)->firstOrFail();

        $this->assertSame(Message::TYPE_BOT, $message->type);
        $this->assertNull($message->user_id);
    }

    public function test_only_an_admin_can_be_chosen(): void
    {
        $patch = fn (?int $userId) => $this->send(
            $this->request('PATCH', '/api/chat-webhooks/1', [
                'authenticatedAs' => self::ADMIN,
                'json'            => ['data' => ['type' => 'chat-webhooks', 'id' => '1', 'attributes' => ['userId' => $userId]]],
            ])
        )->getStatusCode();

        $this->assertSame(422, $patch(self::MEMBER));
        $this->assertNull(Webhook::query()->findOrFail(1)->user_id);

        $this->assertSame(200, $patch(self::ADMIN));
        $this->assertSame(self::ADMIN, Webhook::query()->findOrFail(1)->user_id);

        $this->assertSame(200, $patch(null));
        $this->assertNull(Webhook::query()->findOrFail(1)->user_id);
    }
}
