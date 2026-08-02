<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;

/**
 * `createChannel` creates both kinds of channel.
 *
 * Public or private is a choice made while creating one, not a second right: a
 * group trusted to open a room is trusted to decide who can find it. A separate
 * `createPrivateChannel` permission was tried and withdrawn, and this is what
 * keeps it from coming back.
 *
 * Both cases are asserted on the response *and* on the stored row. The response
 * is what the modal reads back; the row is what visibility is scoped against, and
 * a value that reaches one but not the other is exactly the half-applied write
 * this is here to catch.
 */
class CreatePrivateChannelTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** BCrypt for "too-obscure", the same hash `normalUser()` carries. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    /** Holds `createChannel` and nothing else beyond using the chat. */
    private const CREATOR = 3;

    /** Same groups as CREATOR, but the account was never confirmed. */
    private const UNCONFIRMED = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                [
                    'id'                 => self::CREATOR,
                    'username'           => 'creator',
                    'password'           => self::PASSWORD_HASH,
                    'email'              => 'creator@machine.local',
                    'is_email_confirmed' => 1,
                ],
                [
                    'id'                 => self::UNCONFIRMED,
                    'username'           => 'unconfirmed',
                    'password'           => self::PASSWORD_HASH,
                    'email'              => 'unconfirmed@machine.local',
                    'is_email_confirmed' => 0,
                ],
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Builder', 'name_plural' => 'Builders'],
            ],
            'group_user' => [
                ['user_id' => self::CREATOR, 'group_id' => 100],
                ['user_id' => self::UNCONFIRMED, 'group_id' => 100],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => 100, 'permission' => 'ramon-chat.createChannel'],
            ],
        ]);
    }

    private function create(int $as, bool $private): \Psr\Http\Message\ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/chat-channels', [
                'authenticatedAs' => $as,
                'json'            => ['data' => ['attributes' => [
                    // `type` is what ChannelFormModal sends on create, and the
                    // schema requires it — without it the request never reaches
                    // the question these tests are asking.
                    'type'      => 'category',
                    'name'      => $private ? 'secret room' : 'open room',
                    'isPrivate' => $private,
                ]]],
            ])
        );
    }

    private function assertCreated(int $as, bool $private): void
    {
        $response = $this->create($as, $private);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame($private, (bool) ($body['data']['attributes']['isPrivate'] ?? null), 'the response');

        $this->assertSame(
            $private ? 1 : 0,
            (int) $this->database()->table('chat_channels')
                ->where('id', $body['data']['id'])
                ->value('is_private'),
            'the stored row'
        );
    }

    public function test_create_channel_creates_a_public_one(): void
    {
        $this->assertCreated(self::CREATOR, false);
    }

    public function test_the_same_permission_creates_a_private_one(): void
    {
        $this->assertCreated(self::CREATOR, true);
    }

    public function test_an_admin_creates_both(): void
    {
        $this->assertCreated(1, false);
        $this->assertCreated(1, true);
    }

    public function test_a_member_without_the_permission_creates_neither(): void
    {
        $this->assertSame(403, $this->create(2, false)->getStatusCode());
        $this->assertSame(403, $this->create(2, true)->getStatusCode());
    }

    /**
     * The grid can say yes while the answer is no.
     *
     * `createChannel` needs `ramon-chat.use` *and* `ramon-chat.createChannel`, and
     * core resolves an unconfirmed account to GUEST alone — see
     * User::permissions(), which only merges MEMBER and the user's own groups when
     * `is_email_confirmed` is set. So a user sitting in a group the admin panel
     * shows as holding the permission still has neither, because the group
     * membership itself is not counted.
     *
     * Same shape produces the same symptom for a *suspended* account, which
     * flarum/suspend demotes to GUEST through the same group processor.
     */
    public function test_an_unconfirmed_account_is_refused_despite_the_group(): void
    {
        $this->assertSame(
            403,
            $this->create(self::UNCONFIRMED, false)->getStatusCode(),
            'unconfirmed, in a group that holds createChannel'
        );

        // The control: identical groups, identical permissions, confirmed.
        $this->assertSame(201, $this->create(self::CREATOR, false)->getStatusCode());
    }

    /**
     * The exact body ChannelFormModal sends, replayed as a non-admin creator.
     *
     * Reported from production: a user holding `createChannel` gets a permission
     * error on every attempt, public or private, while an administrator doing the
     * same thing succeeds. The narrow fixtures above missed it because they send
     * three attributes and the modal sends thirteen — and json-api-server answers
     * 403 for a field that is merely *present* while not writable, so one
     * admin-only attribute in the payload refuses the whole request.
     */
    public function test_the_modal_payload_is_accepted_from_a_non_admin(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-channels', [
                'authenticatedAs' => self::CREATOR,
                'json'            => ['data' => ['type' => 'chat-channels', 'attributes' => [
                    'name'                     => 'canal teste privado',
                    'description'              => null,
                    'emoji'                    => null,
                    'threadingEnabled'         => true,
                    'slowModeSeconds'          => 0,
                    'maxMessageLength'         => null,
                    'allowChannelWideMentions' => true,
                    'autoJoin'                 => false,
                    'autoJoinOnReply'          => false,
                    'isPrivate'                => true,
                    'postPermission'           => 'all',
                    'postDiscussions'          => false,
                    'tagId'                    => null,
                    'type'                     => 'category',
                ]]],
            ])
        );

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_create_channel_covers_auto_join_too(): void
    {
        // Every field on the form belongs to whoever may create a channel — there
        // is no attribute the modal offers and the API then refuses. Auto-join was
        // the last exception and it is gone: it is asserted here rather than merely
        // no longer denied, so bringing the carve-out back fails loudly instead of
        // silently dropping the value.
        $response = $this->send(
            $this->request('POST', '/api/chat-channels', [
                'authenticatedAs' => self::CREATOR,
                'json'            => ['data' => ['attributes' => [
                    'type'     => 'category',
                    'name'     => 'default room for members',
                    'autoJoin' => true,
                ]]],
            ])
        );

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $id = json_decode((string) $response->getBody(), true)['data']['id'];

        $this->assertSame(
            1,
            (int) $this->database()->table('chat_channels')->where('id', $id)->value('auto_join'),
            'the value the creator asked for is the value stored'
        );
    }

    public function test_an_admin_can_switch_auto_join_on(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-channels', [
                'authenticatedAs' => 1,
                'json'            => ['data' => ['attributes' => [
                    'type'     => 'category',
                    'name'     => 'default room',
                    'autoJoin' => true,
                ]]],
            ])
        );

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $id = json_decode((string) $response->getBody(), true)['data']['id'];

        $this->assertSame(
            1,
            (int) $this->database()->table('chat_channels')->where('id', $id)->value('auto_join')
        );
    }
}
