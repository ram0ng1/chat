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
use Flarum\Locale\LocaleManager;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Email;
use Psr\Http\Message\ResponseInterface;
use Ramon\Chat\Tests\integration\FlushesCache;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Passar um canal para outro membro, de ponta a ponta, e o aviso de promoção
 * a moderador.
 *
 * O dono escolhe um membro e recebe um código por e-mail; só o hash do código
 * fica no banco. Digitado o código, o membro recebe o pedido e aceita ou
 * recusa. Aceito, o membro vira dono e o dono anterior vira moderador do
 * canal, numa transação. Erros de código contam e travam no quinto, o código
 * expira, e sair do canal encerra o pedido.
 */
class OwnershipTransferTest extends TestCase
{
    use FlushesCache;
    use RetrievesAuthorizedUsers;
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const ADMIN = 1;
    private const PLAIN = 2;
    private const OWNER = 3;
    private const TARGET = 4;
    private const OUTSIDER = 5;
    private const DEPARTED = 6;

    private const CHANNEL = 1;

    /** @var string[] */
    private array $mails = [];

    /** @var string[] */
    private array $recipients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $this->setting('ramon-chat.channel_ownership', 'members');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                $this->user(self::OWNER, 'owner'),
                $this->user(self::TARGET, 'target'),
                $this->user(self::OUTSIDER, 'outsider'),
                $this->user(self::DEPARTED, 'departed'),
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Owner', 'name_plural' => 'Owners'],
            ],
            'group_user' => [
                ['user_id' => self::OWNER, 'group_id' => 100],
                ['user_id' => self::TARGET, 'group_id' => 100],
                ['user_id' => self::OUTSIDER, 'group_id' => 100],
                ['user_id' => self::DEPARTED, 'group_id' => 100],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => 100, 'permission' => 'ramon-chat.createChannel'],
                ['group_id' => 100, 'permission' => 'ramon-chat.manageOwnChannels'],
            ],
            'chat_channels' => [
                [
                    'id'                          => self::CHANNEL,
                    'type'                        => 'category',
                    'name'                        => 'Room',
                    'slug'                        => 'room',
                    'status'                      => 'open',
                    'is_private'                  => 0,
                    'post_permission'             => 'all',
                    'creator_id'                  => self::OWNER,
                    'threading_enabled'           => 0,
                    'auto_join'                   => 0,
                    'auto_join_on_reply'          => 0,
                    'post_discussions'            => 0,
                    'allow_channel_wide_mentions' => 1,
                    'messages_count'              => 0,
                    'user_count'                  => 3,
                    'created_at'                  => $now,
                    'updated_at'                  => $now,
                ],
            ],
            'chat_channel_user' => [
                ['channel_id' => self::CHANNEL, 'user_id' => self::OWNER, 'joined_at' => $now, 'created_at' => $now],
                ['channel_id' => self::CHANNEL, 'user_id' => self::TARGET, 'joined_at' => $now, 'created_at' => $now],
                ['channel_id' => self::CHANNEL, 'user_id' => self::PLAIN, 'joined_at' => $now, 'created_at' => $now],
                ['channel_id' => self::CHANNEL, 'user_id' => self::DEPARTED, 'joined_at' => $now, 'left_at' => $now, 'created_at' => $now],
            ],
        ]);

        $this->flushCache();

        $container = $this->app()->getContainer();

        // The harness resolves the locale manager before extension extenders
        // run, so the chat's catalogue is loaded by hand, as MentionEmailTest
        // explains.
        $locales = $container->make(LocaleManager::class);
        $locales->clearCache();
        $locales->addTranslations('en', dirname(__DIR__, 3).'/locale/en.yml');

        // MessageSent rather than MessageSending: the latter is dispatched as
        // a halting event, and core's own listener answers it first.
        $container->make('events')->listen(MessageSent::class, function (MessageSent $event) {
            $email = $event->sent->getOriginalMessage();

            if (! $email instanceof Email) {
                return;
            }

            $this->mails[] = (string) $email->getTextBody();

            foreach ($email->getTo() as $address) {
                $this->recipients[] = $address->getAddress();
            }
        });
    }

    public function test_only_the_owner_or_an_administrator_may_start(): void
    {
        $this->assertSame(403, $this->start(self::PLAIN, self::TARGET)->getStatusCode());
        $this->assertSame(403, $this->start(self::TARGET, self::PLAIN)->getStatusCode(), 'a member with the same grants is not the owner');

        $response = $this->start(self::OWNER, self::TARGET);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $attributes = json_decode((string) $response->getBody(), true)['data']['attributes'];
        $this->assertTrue($attributes['canTransferOwnership']);
        $this->assertSame(self::TARGET, $attributes['ownershipTransfer']['toUserId']);
        $this->assertFalse($attributes['ownershipTransfer']['confirmed']);

        $admin = $this->start(self::ADMIN, self::TARGET);
        $this->assertSame(200, $admin->getStatusCode(), (string) $admin->getBody());
        $this->assertSame(self::ADMIN, (int) $this->transfer()->from_user_id, 'a new transfer replaces the pending one');
    }

    public function test_the_target_must_be_a_current_member_who_may_own_channels(): void
    {
        $this->assertSame(422, $this->start(self::OWNER, self::OUTSIDER)->getStatusCode(), 'not a member');
        $this->assertSame(422, $this->start(self::OWNER, self::DEPARTED)->getStatusCode(), 'left the channel');
        $this->assertSame(422, $this->start(self::OWNER, self::OWNER)->getStatusCode(), 'already the owner');
        $this->assertSame(422, $this->start(self::OWNER, self::PLAIN)->getStatusCode(), 'no manageOwnChannels');
        $this->assertSame(422, $this->start(self::OWNER, 999)->getStatusCode(), 'no such user');

        $this->assertNull($this->transfer());
        $this->assertSame([], $this->mails, 'no code is mailed for a refused start');
    }

    public function test_the_code_is_mailed_to_the_owner_and_only_its_hash_is_stored(): void
    {
        $response = $this->start(self::OWNER, self::TARGET);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $code = $this->mailedCode();

        $this->assertSame(['owner@machine.local'], $this->recipients, 'only the owner is mailed');
        $this->assertStringNotContainsString($code, (string) $response->getBody(), 'the code is never returned');

        $row = $this->transfer();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertStringNotContainsString($code, (string) $row->code_hash);
        $this->assertTrue(password_verify($code, (string) $row->code_hash));
        $this->assertSame(0, (int) $row->attempts);
        $this->assertNull($row->confirmed_at);
    }

    public function test_wrong_codes_count_and_the_fifth_invalidates_the_transfer(): void
    {
        $this->start(self::OWNER, self::TARGET);
        $code = $this->mailedCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= 4; $i++) {
            $response = $this->confirm(self::OWNER, $wrong);
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame($i, (int) $this->transfer()->attempts);
        }

        $this->assertSame(422, $this->confirm(self::OWNER, $wrong)->getStatusCode());
        $this->assertNull($this->transfer(), 'the fifth wrong code ends the transfer');

        $this->assertSame(422, $this->confirm(self::OWNER, $code)->getStatusCode(), 'and the right code no longer helps');
        $this->assertSame(self::OWNER, $this->creatorId());
    }

    public function test_only_the_owner_may_enter_the_code(): void
    {
        $this->start(self::OWNER, self::TARGET);
        $code = $this->mailedCode();

        $this->assertSame(422, $this->confirm(self::TARGET, $code)->getStatusCode());
        $this->assertNull($this->transfer()->confirmed_at);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->start(self::OWNER, self::TARGET);
        $code = $this->mailedCode();

        $this->database()->table('chat_channel_transfers')
            ->update(['expires_at' => Carbon::now()->subMinute()->toDateTimeString()]);

        $response = $this->confirm(self::OWNER, $code);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull($this->transfer());
    }

    public function test_accepting_moves_ownership_and_makes_the_previous_owner_a_moderator(): void
    {
        $this->start(self::OWNER, self::TARGET);
        $confirmed = $this->confirm(self::OWNER, $this->mailedCode());
        $this->assertSame(200, $confirmed->getStatusCode(), (string) $confirmed->getBody());

        $row = $this->transfer();
        $this->assertNotNull($row->confirmed_at);
        $this->assertNull($row->code_hash, 'the hash goes once the code is used');

        $notification = $this->notification(self::TARGET, 'chatOwnershipTransfer');
        $this->assertNotNull($notification, 'the member is asked');
        $this->assertSame(self::OWNER, (int) $notification->from_user_id);
        $this->assertSame(
            ['channelId' => self::CHANNEL, 'transferId' => (int) $row->id],
            json_decode((string) $notification->data, true),
            'ids only'
        );

        $this->assertSame(422, $this->answer(self::PLAIN, 'accept')->getStatusCode(), 'nobody else may accept');

        $response = $this->answer(self::TARGET, 'accept');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $attributes = json_decode((string) $response->getBody(), true)['data']['attributes'];
        $this->assertSame(self::TARGET, $attributes['creatorId']);
        $this->assertTrue($attributes['canManageModerators'], 'the new owner holds every owner right');
        $this->assertTrue($attributes['canTransferOwnership']);
        $this->assertTrue($attributes['bypassesSlowMode']);
        $this->assertSame([self::OWNER], $attributes['moderatorIds']);

        $this->assertSame(self::TARGET, $this->creatorId());
        $this->assertNull($this->transfer());
        $this->assertTrue($this->isModerator(self::OWNER));
        $this->assertFalse($this->isModerator(self::TARGET));
        $this->assertSame(1, (int) $this->notification(self::TARGET, 'chatOwnershipTransfer')->is_deleted, 'the request leaves the bell');

        $this->assertSame(403, $this->start(self::OWNER, self::PLAIN)->getStatusCode(), 'the previous owner no longer owns it');
    }

    public function test_declining_tells_the_owner(): void
    {
        $this->start(self::OWNER, self::TARGET);
        $this->confirm(self::OWNER, $this->mailedCode());

        $response = $this->answer(self::TARGET, 'decline');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertNull($this->transfer());
        $this->assertSame(self::OWNER, $this->creatorId());

        $declined = $this->notification(self::OWNER, 'chatOwnershipTransferDeclined');
        $this->assertNotNull($declined);
        $this->assertSame(self::TARGET, (int) $declined->from_user_id);
        $this->assertSame(1, (int) $this->notification(self::TARGET, 'chatOwnershipTransfer')->is_deleted);
    }

    public function test_the_owner_may_cancel(): void
    {
        $this->start(self::OWNER, self::TARGET);
        $this->confirm(self::OWNER, $this->mailedCode());

        $this->assertSame(422, $this->answer(self::PLAIN, 'cancel')->getStatusCode(), 'a bystander may not cancel');

        $response = $this->answer(self::OWNER, 'cancel');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertNull($this->transfer());
        $this->assertSame(422, $this->answer(self::TARGET, 'accept')->getStatusCode());
        $this->assertSame(1, (int) $this->notification(self::TARGET, 'chatOwnershipTransfer')->is_deleted);
    }

    public function test_leaving_the_channel_cancels_the_transfer(): void
    {
        $this->start(self::OWNER, self::TARGET);
        $this->confirm(self::OWNER, $this->mailedCode());

        $left = $this->send($this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/leave', [
            'authenticatedAs' => self::TARGET,
        ]));
        $this->assertSame(204, $left->getStatusCode(), (string) $left->getBody());

        $this->assertNull($this->transfer());
        $this->assertSame(self::OWNER, $this->creatorId());
    }

    public function test_starting_is_throttled_for_members(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->start(self::OWNER, self::TARGET)->getStatusCode());
        }

        $this->assertSame(422, $this->start(self::OWNER, self::TARGET)->getStatusCode());
        $this->assertCount(3, $this->mails);
    }

    public function test_promotion_notifies_the_promoted_member_only_with_ids(): void
    {
        $response = $this->send($this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/moderators', [
            'authenticatedAs' => self::OWNER,
            'json'            => ['data' => ['attributes' => ['userId' => self::PLAIN]]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $rows = $this->database()->table('notifications')->where('type', 'chatModeratorPromoted')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(self::PLAIN, (int) $rows[0]->user_id);
        $this->assertSame(self::OWNER, (int) $rows[0]->from_user_id);
        $this->assertSame(self::CHANNEL, (int) $rows[0]->subject_id);
        $this->assertSame(['channelId' => self::CHANNEL, 'userId' => self::PLAIN], json_decode((string) $rows[0]->data, true));

        $this->send($this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/moderators/remove', [
            'authenticatedAs' => self::OWNER,
            'json'            => ['data' => ['attributes' => ['userId' => self::PLAIN]]],
        ]));

        $this->assertSame(0, $this->database()->table('notifications')->where('type', 'chatModeratorPromoted')->count(), 'a demotion withdraws it and sends nothing');
    }

    private function start(int $actor, int $target): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/transfer', [
            'authenticatedAs' => $actor,
            'json'            => ['data' => ['attributes' => ['userId' => $target]]],
        ]));
    }

    private function confirm(int $actor, string $code): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/transfer/confirm', [
            'authenticatedAs' => $actor,
            'json'            => ['data' => ['attributes' => ['code' => $code]]],
        ]));
    }

    private function answer(int $actor, string $action): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/transfer/'.$action, [
            'authenticatedAs' => $actor,
            'json'            => ['data' => ['attributes' => []]],
        ]));
    }

    private function mailedCode(): string
    {
        $this->assertNotEmpty($this->mails, 'a code was mailed');
        $this->assertSame(1, preg_match('/\b(\d{6})\b/', (string) end($this->mails), $match));

        return $match[1];
    }

    private function transfer(): ?object
    {
        return $this->database()->table('chat_channel_transfers')->where('channel_id', self::CHANNEL)->first();
    }

    private function creatorId(): int
    {
        return (int) $this->database()->table('chat_channels')->where('id', self::CHANNEL)->value('creator_id');
    }

    private function isModerator(int $user): bool
    {
        return (bool) $this->database()->table('chat_channel_user')
            ->where('channel_id', self::CHANNEL)
            ->where('user_id', $user)
            ->value('is_moderator');
    }

    private function notification(int $user, string $type): ?object
    {
        return $this->database()->table('notifications')
            ->where('user_id', $user)
            ->where('type', $type)
            ->orderByDesc('id')
            ->first();
    }

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
}
