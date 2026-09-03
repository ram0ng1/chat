<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Foundation\Paths;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Laminas\Diactoros\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * A private channel's attachments are private.
 *
 * Every file used to go under `public/assets/chat`, where the web server hands
 * it to anyone with the URL — which, for a picture posted in a private room, is
 * anyone the URL was ever forwarded to. The rule now: a file posted in a
 * channel the world cannot read lives outside the webroot and is served only
 * through the endpoint that checks the reader can see the message.
 *
 * The assertions are made on disk as well as on the API. The API can say
 * "private" about a file that is still sitting in the public directory; the
 * directory cannot.
 */
class PrivateChannelUploadTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use ResetsVisibilityScopers;

    /** BCrypt for "too-obscure", the same hash `normalUser()` carries. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const MEMBER = 3;
    private const OUTSIDER = 4;

    private const CH_PUBLIC = 1;
    private const CH_PRIVATE = 2;

    /** A 1×1 transparent PNG — the smallest thing `mime_content_type` calls an image. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        // Before the boot this test triggers, so it registers the one copy of
        // each scoper a real forum has — see the trait for what goes wrong
        // otherwise.
        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => self::MEMBER, 'username' => 'member', 'password' => self::PASSWORD_HASH, 'email' => 'member@machine.local', 'is_email_confirmed' => 1],
                ['id' => self::OUTSIDER, 'username' => 'outsider', 'password' => self::PASSWORD_HASH, 'email' => 'outsider@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.upload'],
            ],
            'chat_channels' => [
                ['id' => self::CH_PUBLIC, 'type' => 'category', 'name' => 'open', 'slug' => 'open', 'status' => 'open', 'is_private' => 0, 'created_at' => $now, 'updated_at' => $now],
                ['id' => self::CH_PRIVATE, 'type' => 'category', 'name' => 'secret', 'slug' => 'secret', 'status' => 'open', 'is_private' => 1, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => self::CH_PUBLIC, 'user_id' => self::MEMBER, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now],
                ['channel_id' => self::CH_PRIVATE, 'user_id' => self::MEMBER, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    public function test_a_file_uploaded_for_a_private_channel_never_touches_the_public_disk(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PRIVATE);

        $this->assertTrue($upload['attributes']['isPrivate']);
        $this->assertStringContainsString('/api/chat/uploads/'.$upload['id'].'/file', $upload['attributes']['url']);
        $this->assertStringNotContainsString('assets/chat', $upload['attributes']['url']);

        $path = $this->storedPath((int) $upload['id']);

        $this->assertFileExists($this->privateRoot().'/'.$path);
        $this->assertFileDoesNotExist($this->publicRoot().'/'.$path);
    }

    public function test_a_file_uploaded_for_a_public_channel_stays_on_the_public_disk(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);

        $this->assertFalse($upload['attributes']['isPrivate']);
        $this->assertStringContainsString('assets/chat/', $upload['attributes']['url']);

        $path = $this->storedPath((int) $upload['id']);

        $this->assertFileExists($this->publicRoot().'/'.$path);
        $this->assertFileDoesNotExist($this->privateRoot().'/'.$path);
    }

    public function test_the_composer_hint_is_not_the_gate(): void
    {
        // Uploaded as if for the public channel, then sent to the private one.
        // The send is what decides, and it moves the file.
        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $path = $this->storedPath((int) $upload['id']);

        $this->assertFileExists($this->publicRoot().'/'.$path);

        $this->sendMessage(self::MEMBER, self::CH_PRIVATE, [(int) $upload['id']]);

        $this->assertFileExists($this->privateRoot().'/'.$path);
        $this->assertFileDoesNotExist($this->publicRoot().'/'.$path);
        $this->assertSame(1, (int) $this->database()->table('chat_uploads')->where('id', $upload['id'])->value('is_private'));
    }

    public function test_a_private_file_is_served_to_a_member_and_to_nobody_else(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PRIVATE);
        $this->sendMessage(self::MEMBER, self::CH_PRIVATE, [(int) $upload['id']]);

        $served = $this->fetch((int) $upload['id'], self::MEMBER);

        $this->assertEquals(200, $served->getStatusCode(), (string) $served->getBody());
        $this->assertSame('image/png', $served->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $served->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringStartsWith('private', $served->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith('inline', $served->getHeaderLine('Content-Disposition'));
        $this->assertSame(base64_decode(self::PNG), (string) $served->getBody());

        // Not 403: the URL must not confirm that there is something to be denied.
        $this->assertStatus(404, $this->fetch((int) $upload['id'], self::OUTSIDER));
        $this->assertStatus(404, $this->fetch((int) $upload['id'], null));
    }

    public function test_a_pending_upload_is_served_to_its_uploader_only(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PRIVATE);

        $this->assertStatus(200, $this->fetch((int) $upload['id'], self::MEMBER));
        $this->assertStatus(404, $this->fetch((int) $upload['id'], self::OUTSIDER));
    }

    public function test_a_byte_range_is_honoured(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PRIVATE);

        $served = $this->send(
            $this->request('GET', '/api/chat/uploads/'.$upload['id'].'/file', ['authenticatedAs' => self::MEMBER])
                ->withQueryParams(['id' => $upload['id']])
                ->withHeader('Range', 'bytes=0-3')
        );

        $this->assertStatus(206, $served);
        $this->assertSame('bytes 0-3/'.strlen(base64_decode(self::PNG)), $served->getHeaderLine('Content-Range'));
        $this->assertSame(substr(base64_decode(self::PNG), 0, 4), (string) $served->getBody());
    }

    public function test_making_a_channel_private_moves_what_was_posted_in_it(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $path = $this->storedPath((int) $upload['id']);

        $this->sendMessage(self::MEMBER, self::CH_PUBLIC, [(int) $upload['id']]);

        $this->assertFileExists($this->publicRoot().'/'.$path);

        $response = $this->send(
            $this->request('PATCH', '/api/chat-channels/'.self::CH_PUBLIC, [
                'authenticatedAs' => 1,
                'json'            => [
                    'data' => [
                        'type'       => 'chat-channels',
                        'id'         => (string) self::CH_PUBLIC,
                        'attributes' => ['isPrivate' => true],
                    ],
                ],
            ])
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertFileExists($this->privateRoot().'/'.$path);
        $this->assertFileDoesNotExist($this->publicRoot().'/'.$path);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @return array{id: string, attributes: array<string, mixed>}
     */
    private function upload(int $as, int $channelId): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chat');
        file_put_contents($tmp, base64_decode(self::PNG));

        $response = $this->send(
            $this->request('POST', '/api/chat/uploads', ['authenticatedAs' => $as])
                ->withUploadedFiles(['file' => new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'pic.png', 'image/png')])
                ->withParsedBody(['channelId' => (string) $channelId])
        );

        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data'];
    }

    /**
     * @param  int[]  $uploadIds
     */
    private function sendMessage(int $as, int $channelId, array $uploadIds): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-messages', [
                'authenticatedAs' => $as,
                'json'            => [
                    'data' => [
                        'type'       => 'chat-messages',
                        'attributes' => [
                            'channelId' => $channelId,
                            'content'   => 'here you go',
                            'uploadIds' => $uploadIds,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * The body travels with a mismatch: a 500 is only diagnosable from it, and a
     * bare "404 is not 200" says nothing about which side of the door failed.
     */
    private function assertStatus(int $expected, ResponseInterface $response): void
    {
        $this->assertEquals($expected, $response->getStatusCode(), substr((string) $response->getBody(), 0, 2000));
    }

    private function fetch(int $uploadId, ?int $as): ResponseInterface
    {
        // `withQueryParams`: the harness builds the request from the path alone
        // and route parameters arrive as query params, which the controller
        // reads with Arr::get — the same way a live request delivers them.
        return $this->send(
            $this->request('GET', '/api/chat/uploads/'.$uploadId.'/file', $as ? ['authenticatedAs' => $as] : [])
                ->withQueryParams(['id' => (string) $uploadId])
        );
    }

    private function storedPath(int $uploadId): string
    {
        return (string) $this->database()->table('chat_uploads')->where('id', $uploadId)->value('path');
    }

    private function publicRoot(): string
    {
        return $this->app()->getContainer()->make(Paths::class)->public.'/assets/chat';
    }

    private function privateRoot(): string
    {
        return $this->app()->getContainer()->make(Paths::class)->storage.'/chat-uploads';
    }
}
