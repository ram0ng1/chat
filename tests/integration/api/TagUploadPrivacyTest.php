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
use Ramon\Chat\Console\PrivatizePendingUploadsCommand;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A category channel's attachments follow its category's privacy, the whole way
 * up the tag hierarchy and after the fact.
 *
 * flarum/tags hides a child tag whose parent the reader cannot see, so a channel
 * bound to an open child of a restricted parent is as private as the parent.
 * Its files used to go to the public disk all the same. And a file posted while
 * the category was open stayed public when the channel was rebound to a
 * restricted one, or when the category itself was restricted later.
 *
 * Every assertion is made on disk: the API can call a file private while it
 * still sits in the webroot.
 */
class TagUploadPrivacyTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use ResetsVisibilityScopers;

    private const TAG_OPEN = 1;
    private const TAG_STAFF = 2;
    private const TAG_STAFF_CHILD = 3;
    private const TAG_OPEN_CHILD = 4;

    private const CH_OPEN = 1;
    private const CH_STAFF_CHILD = 2;
    private const CH_OPEN_CHILD = 3;

    /** A 1×1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        // Tags before the chat: the chat reads from it.
        $this->extension('flarum-tags', 'ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.upload'],
            ],
            'tags' => [
                ['id' => self::TAG_OPEN, 'name' => 'Lounge', 'slug' => 'lounge', 'position' => 0, 'is_restricted' => 0],
                ['id' => self::TAG_STAFF, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => 1],
                ['id' => self::TAG_STAFF_CHILD, 'name' => 'Staff notes', 'slug' => 'staff-notes', 'parent_id' => self::TAG_STAFF, 'is_restricted' => 0],
                ['id' => self::TAG_OPEN_CHILD, 'name' => 'Lounge corner', 'slug' => 'lounge-corner', 'parent_id' => self::TAG_OPEN, 'is_restricted' => 0],
            ],
            'chat_channels' => [
                $this->channel(self::CH_OPEN, 'lounge-chat', self::TAG_OPEN),
                $this->channel(self::CH_STAFF_CHILD, 'staff-notes-chat', self::TAG_STAFF_CHILD),
                $this->channel(self::CH_OPEN_CHILD, 'corner-chat', self::TAG_OPEN_CHILD),
            ],
            'chat_channel_user' => [
                ['channel_id' => self::CH_OPEN, 'user_id' => 1, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now],
                ['channel_id' => self::CH_STAFF_CHILD, 'user_id' => 1, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now],
                ['channel_id' => self::CH_OPEN_CHILD, 'user_id' => 1, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    public function test_an_open_child_of_a_restricted_parent_is_private(): void
    {
        $upload = $this->upload(self::CH_STAFF_CHILD);
        $path = $this->storedPath((int) $upload['id']);

        $this->assertTrue($upload['attributes']['isPrivate']);
        $this->assertFileExists($this->privateRoot().'/'.$path);
        $this->assertFileDoesNotExist($this->publicRoot().'/'.$path);
    }

    public function test_an_open_child_of_an_open_parent_stays_public(): void
    {
        $upload = $this->upload(self::CH_OPEN_CHILD);

        $this->assertFalse($upload['attributes']['isPrivate']);
        $this->assertFileExists($this->publicRoot().'/'.$this->storedPath((int) $upload['id']));
    }

    public function test_rebinding_a_channel_to_a_restricted_category_moves_its_files(): void
    {
        $id = $this->postFile(self::CH_OPEN);
        $path = $this->storedPath($id);

        $this->assertFileExists($this->publicRoot().'/'.$path);

        $response = $this->send(
            $this->request('PATCH', '/api/chat-channels/'.self::CH_OPEN, [
                'authenticatedAs' => 1,
                'json'            => [
                    'data' => [
                        'type'       => 'chat-channels',
                        'id'         => (string) self::CH_OPEN,
                        'attributes' => ['tagId' => self::TAG_STAFF_CHILD],
                    ],
                ],
            ])
        );

        $this->assertStatus(200, $response);
        $this->assertPrivateOnDisk($id, $path);
    }

    public function test_restricting_a_category_later_moves_the_files_of_every_channel_below_it(): void
    {
        $parentFile = $this->postFile(self::CH_OPEN);
        $childFile = $this->postFile(self::CH_OPEN_CHILD);
        $parentPath = $this->storedPath($parentFile);
        $childPath = $this->storedPath($childFile);

        $response = $this->send(
            $this->request('PATCH', '/api/tags/'.self::TAG_OPEN, [
                'authenticatedAs' => 1,
                'json'            => [
                    'data' => [
                        'type'       => 'tags',
                        'id'         => (string) self::TAG_OPEN,
                        'attributes' => ['isRestricted' => true],
                    ],
                ],
            ])
        );

        $this->assertStatus(200, $response);

        // The queue is synchronous here, so the jobs have already run.
        $this->assertPrivateOnDisk($parentFile, $parentPath);
        $this->assertPrivateOnDisk($childFile, $childPath);
    }

    public function test_the_hourly_sweep_catches_a_category_moved_without_an_event(): void
    {
        $id = $this->postFile(self::CH_OPEN_CHILD);
        $path = $this->storedPath($id);

        // What flarum/tags' reorder endpoint does: a bulk update, no model event.
        $this->database()->table('tags')->where('id', self::TAG_OPEN_CHILD)->update(['parent_id' => self::TAG_STAFF]);

        $this->assertFileExists($this->publicRoot().'/'.$path);

        $command = $this->app()->getContainer()->make(PrivatizePendingUploadsCommand::class);
        $command->run(new ArrayInput([]), new BufferedOutput());

        $this->assertPrivateOnDisk($id, $path);
    }

    public function test_the_sweep_leaves_open_channels_alone(): void
    {
        $id = $this->postFile(self::CH_OPEN);
        $path = $this->storedPath($id);

        $command = $this->app()->getContainer()->make(PrivatizePendingUploadsCommand::class);
        $command->run(new ArrayInput([]), new BufferedOutput());

        $this->assertFileExists($this->publicRoot().'/'.$path);
        $this->assertSame(0, (int) $this->database()->table('chat_uploads')->where('id', $id)->value('is_private'));
    }

    private function channel(int $id, string $slug, ?int $tagId): array
    {
        return [
            'id'         => $id,
            'type'       => 'category',
            'name'       => $slug,
            'slug'       => $slug,
            'status'     => 'open',
            'is_private' => 0,
            'tag_id'     => $tagId,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    private function assertPrivateOnDisk(int $uploadId, string $path): void
    {
        $this->assertSame(1, (int) $this->database()->table('chat_uploads')->where('id', $uploadId)->value('is_private'));
        $this->assertFileExists($this->privateRoot().'/'.$path);
        $this->assertFileDoesNotExist($this->publicRoot().'/'.$path);
    }

    /**
     * Uploads a file and sends it in a message, as the admin.
     */
    private function postFile(int $channelId): int
    {
        $upload = $this->upload($channelId);
        $id = (int) $upload['id'];

        $response = $this->send(
            $this->request('POST', '/api/chat-messages', [
                'authenticatedAs' => 1,
                'json'            => [
                    'data' => [
                        'type'       => 'chat-messages',
                        'attributes' => [
                            'channelId' => $channelId,
                            'content'   => 'here you go',
                            'uploadIds' => [$id],
                        ],
                    ],
                ],
            ])
        );

        $this->assertStatus(201, $response);

        return $id;
    }

    /**
     * @return array{id: string, attributes: array<string, mixed>}
     */
    private function upload(int $channelId): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chat');
        file_put_contents($tmp, base64_decode(self::PNG));

        $response = $this->send(
            $this->request('POST', '/api/chat/uploads', ['authenticatedAs' => 1])
                ->withUploadedFiles(['file' => new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'pic.png', 'image/png')])
                ->withParsedBody(['channelId' => (string) $channelId])
        );

        $this->assertStatus(201, $response);

        return json_decode((string) $response->getBody(), true)['data'];
    }

    private function assertStatus(int $expected, ResponseInterface $response): void
    {
        $this->assertEquals($expected, $response->getStatusCode(), substr((string) $response->getBody(), 0, 2000));
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
