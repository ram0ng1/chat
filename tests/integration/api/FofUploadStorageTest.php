<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Group\Group;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use FoF\Upload\Events\Adapter\Collecting;
use FoF\Upload\Events\Adapter\Instantiate;
use Laminas\Diactoros\UploadedFile;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Http\Message\ResponseInterface;
use Ramon\Chat\Console\PruneChatCommand;
use Ramon\Chat\Storage\FofUploadStore;
use Ramon\Chat\Tests\Fixtures\FakeBucketAdapter;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Public attachments stored through fof/upload, private ones never.
 *
 * Two adapters are used. `chat-bucket` is a fake registered through fof/upload's
 * own adapter events, writing to a temporary directory and naming URLs on a host
 * of its own — the shape of an S3 bucket behind a CDN, without the network. The
 * paths that read a remote file back (moving it to the private disk, serving it
 * while it moves) use fof/upload's real `local` adapter, because reading goes
 * through fof/upload's downloader, which fetches anything but `local` over HTTP.
 *
 * Every assertion that matters is made on disk and in the database as well as
 * on the API: the API can say "stored elsewhere" about a file that is still
 * sitting in the webroot.
 */
class FofUploadStorageTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use ResetsVisibilityScopers;

    /** BCrypt for "too-obscure", the same hash `normalUser()` carries. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const MEMBER = 3;
    private const OUTSIDER = 4;

    private const CH_PUBLIC = 1;
    private const CH_PRIVATE = 2;

    /** A 1×1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** Where the fake bucket keeps its bytes for the current test. */
    private static string $bucketRoot = '';

    /** Set to make the fake bucket refuse every write. */
    private static bool $bucketDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FofUploadStore::supported()) {
            $this->markTestSkipped('fof/upload is not installed; composer install with dev dependencies.');
        }

        $this->resetVisibilityScopers();

        self::$bucketRoot = sys_get_temp_dir().'/chat-bucket-'.bin2hex(random_bytes(6));
        self::$bucketDown = false;
        mkdir(self::$bucketRoot);

        $this->extension('fof-upload', 'ramon-chat');

        // The fake bucket, registered the way any third-party fof/upload adapter
        // is: announced on Collecting, built on Instantiate.
        $this->extend(
            (new Extend\Event())
                ->listen(Collecting::class, function (Collecting $event) {
                    $event->adapters->put('chat-bucket', true);
                })
                ->listen(Instantiate::class, function (Instantiate $event) {
                    if ($event->adapter !== 'chat-bucket') {
                        return null;
                    }

                    $adapter = new FakeBucketAdapter(
                        new LocalFilesystemAdapter(self::$bucketRoot),
                        resolve(SettingsRepositoryInterface::class),
                        resolve(UrlGenerator::class)
                    );
                    $adapter->refuse = self::$bucketDown;

                    return $adapter;
                })
        );

        // Before the boot: the settings repository reads them once, when the
        // application starts, so a later write to the table is never seen. Tests
        // that need a different value call setting() again before their first
        // request.
        $this->setting('ramon-chat.upload_storage', 'fof-upload');
        $this->setting('ramon-chat.fof_upload_adapter', 'chat-bucket');

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

    protected function tearDown(): void
    {
        $this->removeTree(self::$bucketRoot);

        parent::tearDown();
    }

    public function test_a_public_file_goes_to_the_bucket_and_nowhere_else(): void
    {
        $before = $this->countFiles($this->publicRoot());

        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $row = $this->row((int) $upload['id']);

        $this->assertSame('fof', $row->storage);
        $this->assertSame('chat-bucket', $row->storage_adapter);
        $this->assertSame(0, (int) $row->is_private);
        $this->assertFileExists(self::$bucketRoot.'/'.$row->path);
        $this->assertSame(base64_decode(self::PNG), file_get_contents(self::$bucketRoot.'/'.$row->path));

        // Not under the chat's own public disk, by path or by count.
        $this->assertFileDoesNotExist($this->publicRoot().'/'.$row->path);
        $this->assertSame($before, $this->countFiles($this->publicRoot()));

        // The URL handed out is the bucket's.
        $this->assertSame($row->remote_url, $upload['attributes']['url']);
        $this->assertStringStartsWith(FakeBucketAdapter::HOST.'/', $upload['attributes']['url']);

        // And fof/upload never heard of it, so its orphan cleanup cannot take it.
        $this->assertSame(0, $this->database()->table('fof_upload_files')->count());
    }

    public function test_a_private_file_stays_on_the_private_disk_whatever_is_configured(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PRIVATE);
        $row = $this->row((int) $upload['id']);

        $this->assertSame('local', $row->storage);
        $this->assertNull($row->remote_url);
        $this->assertTrue($upload['attributes']['isPrivate']);
        $this->assertFileExists($this->privateRoot().'/'.$row->path);
        $this->assertSame(0, $this->countFiles(self::$bucketRoot));
    }

    public function test_a_bucket_that_refuses_the_write_refuses_the_upload(): void
    {
        self::$bucketDown = true;
        $before = $this->countFiles($this->publicRoot());

        $response = $this->sendUpload(self::MEMBER, self::CH_PUBLIC);

        // Refused, and not quietly kept on the local disk instead.
        $this->assertStatus(422, $response);
        // The translated message, or its key where the test boot has no locale
        // loaded. Either way the member is told nothing about the bucket.
        $this->assertMatchesRegularExpression('/upload_storage_failed|could not be stored/', (string) $response->getBody());
        $this->assertStringNotContainsString('chat-bucket', (string) $response->getBody());
        $this->assertSame(0, $this->database()->table('chat_uploads')->count());
        $this->assertSame($before, $this->countFiles($this->publicRoot()));
    }

    public function test_sending_a_bucket_file_to_a_private_channel_brings_it_home(): void
    {
        $this->useFofLocalAdapter();

        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $remote = $this->row((int) $upload['id']);

        $this->assertSame('fof', $remote->storage);
        $this->assertFileExists($this->fofLocalRoot().'/'.$remote->path);

        $this->sendMessage(self::MEMBER, self::CH_PRIVATE, [(int) $upload['id']]);

        $row = $this->row((int) $upload['id']);

        $this->assertSame('local', $row->storage);
        $this->assertSame(1, (int) $row->is_private);
        $this->assertNull($row->remote_url);
        $this->assertNull($row->storage_adapter);
        $this->assertSame(base64_decode(self::PNG), file_get_contents($this->privateRoot().'/'.$row->path));
        $this->assertFileDoesNotExist($this->fofLocalRoot().'/'.$remote->path);
    }

    public function test_making_a_channel_private_brings_its_bucket_files_home(): void
    {
        $this->useFofLocalAdapter();

        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $remote = $this->row((int) $upload['id']);
        $this->sendMessage(self::MEMBER, self::CH_PUBLIC, [(int) $upload['id']]);

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

        $this->assertStatus(200, $response);

        // The queue is synchronous here, so the job has already run.
        $row = $this->row((int) $upload['id']);

        $this->assertSame('local', $row->storage);
        $this->assertSame(1, (int) $row->is_private);
        $this->assertFileExists($this->privateRoot().'/'.$row->path);
        $this->assertFileDoesNotExist($this->fofLocalRoot().'/'.$remote->path);
    }

    public function test_a_file_flagged_private_while_still_remote_is_served_only_through_the_check(): void
    {
        $this->useFofLocalAdapter();

        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);

        // The state between flagging and moving, which the job leaves behind if
        // the move fails.
        $this->database()->table('chat_uploads')->where('id', $upload['id'])->update(['is_private' => 1]);

        $served = $this->fetch((int) $upload['id'], self::MEMBER);

        $this->assertStatus(200, $served);
        $this->assertSame(base64_decode(self::PNG), (string) $served->getBody());
        $this->assertSame('image/png', $served->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $served->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringStartsWith('private', $served->getHeaderLine('Cache-Control'));
        $this->assertSame('', $served->getHeaderLine('Accept-Ranges'));

        $this->assertStatus(404, $this->fetch((int) $upload['id'], self::OUTSIDER));
    }

    public function test_a_public_bucket_file_is_not_proxied_by_the_forum(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);

        $this->assertStatus(404, $this->fetch((int) $upload['id'], self::MEMBER));
    }

    public function test_deleting_a_message_deletes_its_bucket_file(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $path = $this->row((int) $upload['id'])->path;
        $messageId = $this->sendMessage(self::MEMBER, self::CH_PUBLIC, [(int) $upload['id']]);

        $this->assertFileExists(self::$bucketRoot.'/'.$path);

        $response = $this->send(
            $this->request('POST', '/api/chat-messages/'.$messageId.'/delete', ['authenticatedAs' => self::MEMBER])
        );

        $this->assertStatus(204, $response);
        $this->assertFileDoesNotExist(self::$bucketRoot.'/'.$path);
        $this->assertSame(0, $this->database()->table('chat_uploads')->count());
    }

    public function test_discarding_a_pending_upload_deletes_its_bucket_file(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $path = $this->row((int) $upload['id'])->path;

        $response = $this->send(
            $this->request('DELETE', '/api/chat-uploads/'.$upload['id'], ['authenticatedAs' => self::MEMBER])
        );

        $this->assertStatus(204, $response);
        $this->assertFileDoesNotExist(self::$bucketRoot.'/'.$path);
        $this->assertSame(0, $this->database()->table('chat_uploads')->count());
    }

    public function test_discarding_a_pending_local_upload_deletes_its_file_too(): void
    {
        // The bug this fixes predates fof/upload: the row went, the file stayed.
        $this->setting('ramon-chat.upload_storage', 'local');

        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $path = $this->row((int) $upload['id'])->path;

        $this->assertFileExists($this->publicRoot().'/'.$path);

        $response = $this->send(
            $this->request('DELETE', '/api/chat-uploads/'.$upload['id'], ['authenticatedAs' => self::MEMBER])
        );

        $this->assertStatus(204, $response);
        $this->assertFileDoesNotExist($this->publicRoot().'/'.$path);
    }

    public function test_pruning_an_orphaned_upload_deletes_its_bucket_file(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $path = $this->row((int) $upload['id'])->path;

        $this->database()->table('chat_uploads')->where('id', $upload['id'])
            ->update(['created_at' => Carbon::now()->subDays(2)->toDateTimeString()]);

        $command = $this->app()->getContainer()->make(PruneChatCommand::class);
        $command->run(new ArrayInput([]), new BufferedOutput());

        $this->assertFileDoesNotExist(self::$bucketRoot.'/'.$path);
        $this->assertSame(0, $this->database()->table('chat_uploads')->count());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * fof/upload's real local adapter, for the paths that read a file back.
     */
    private function useFofLocalAdapter(): void
    {
        $this->setting('ramon-chat.fof_upload_adapter', 'local');
    }

    /**
     * @return array{id: string, attributes: array<string, mixed>}
     */
    private function upload(int $as, int $channelId): array
    {
        $response = $this->sendUpload($as, $channelId);

        $this->assertStatus(201, $response);

        return json_decode((string) $response->getBody(), true)['data'];
    }

    private function sendUpload(int $as, int $channelId): ResponseInterface
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chat');
        file_put_contents($tmp, base64_decode(self::PNG));

        return $this->send(
            $this->request('POST', '/api/chat/uploads', ['authenticatedAs' => $as])
                ->withUploadedFiles(['file' => new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'pic.png', 'image/png')])
                ->withParsedBody(['channelId' => (string) $channelId])
        );
    }

    /**
     * @param  int[]  $uploadIds
     */
    private function sendMessage(int $as, int $channelId, array $uploadIds): int
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

        $this->assertStatus(201, $response);

        return (int) json_decode((string) $response->getBody(), true)['data']['id'];
    }

    private function fetch(int $uploadId, ?int $as): ResponseInterface
    {
        return $this->send(
            $this->request('GET', '/api/chat/uploads/'.$uploadId.'/file', $as ? ['authenticatedAs' => $as] : [])
                ->withQueryParams(['id' => (string) $uploadId])
        );
    }

    /**
     * The body travels with a mismatch: a 500 is only diagnosable from it.
     */
    private function assertStatus(int $expected, ResponseInterface $response): void
    {
        $this->assertEquals($expected, $response->getStatusCode(), substr((string) $response->getBody(), 0, 2000));
    }

    private function row(int $uploadId): object
    {
        return $this->database()->table('chat_uploads')->where('id', $uploadId)->first();
    }

    private function publicRoot(): string
    {
        return $this->app()->getContainer()->make(Paths::class)->public.'/assets/chat';
    }

    private function privateRoot(): string
    {
        return $this->app()->getContainer()->make(Paths::class)->storage.'/chat-uploads';
    }

    private function fofLocalRoot(): string
    {
        return $this->app()->getContainer()->make(Paths::class)->public.'/assets/files';
    }

    private function countFiles(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }

        $count = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $item) {
            $count += $item->isFile() ? 1 : 0;
        }

        return $count;
    }

    private function removeTree(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
