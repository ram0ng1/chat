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
use Flarum\User\User;
use FoF\Upload\Events\Adapter\Collecting;
use FoF\Upload\Events\Adapter\Instantiate;
use Laminas\Diactoros\UploadedFile;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Http\Message\ResponseInterface;
use Ramon\Chat\Gdpr\ChatData;
use Ramon\Chat\Storage\FofUploadStore;
use Ramon\Chat\Tests\Fixtures\FakeBucketAdapter;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * The chat's part of a flarum/gdpr export and erasure.
 *
 * The type is built straight from the container and driven the way
 * flarum/gdpr's processor drives it, so the bytes can be checked on disk: a
 * deleted row whose file is still in a bucket is not an erasure. Files live in
 * both places a chat file can — the private disk for a private channel's, a
 * fof/upload bucket for a public one's.
 */
class GdprChatDataTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use ResetsVisibilityScopers;

    /** BCrypt for "too-obscure", the same hash `normalUser()` carries. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const MEMBER = 3;
    private const OTHER = 4;

    private const CH_PUBLIC = 1;
    private const CH_PRIVATE = 2;

    /** A 1×1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private static string $bucketRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! FofUploadStore::supported() || ! class_exists(\Flarum\Gdpr\Data\Type::class)) {
            $this->markTestSkipped('fof/upload and flarum/gdpr are dev dependencies; composer install with them.');
        }

        $this->resetVisibilityScopers();

        self::$bucketRoot = sys_get_temp_dir().'/chat-gdpr-bucket-'.bin2hex(random_bytes(6));
        mkdir(self::$bucketRoot);

        $this->extension('fof-upload', 'ramon-chat');

        $this->extend(
            (new Extend\Event())
                ->listen(Collecting::class, function (Collecting $event) {
                    $event->adapters->put('chat-bucket', true);
                })
                ->listen(Instantiate::class, function (Instantiate $event) {
                    if ($event->adapter !== 'chat-bucket') {
                        return null;
                    }

                    return new FakeBucketAdapter(
                        new LocalFilesystemAdapter(self::$bucketRoot),
                        resolve(SettingsRepositoryInterface::class),
                        resolve(UrlGenerator::class)
                    );
                })
        );

        $this->setting('ramon-chat.upload_storage', 'fof-upload');
        $this->setting('ramon-chat.fof_upload_adapter', 'chat-bucket');
        $this->setting('flarum-gdpr.default-anonymous-username', 'Ghost');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => self::MEMBER, 'username' => 'member', 'password' => self::PASSWORD_HASH, 'email' => 'member@machine.local', 'is_email_confirmed' => 1],
                ['id' => self::OTHER, 'username' => 'other', 'password' => self::PASSWORD_HASH, 'email' => 'other@machine.local', 'is_email_confirmed' => 1],
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
                ['channel_id' => self::CH_PUBLIC, 'user_id' => self::OTHER, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_messages' => [
                ['id' => 100, 'channel_id' => self::CH_PUBLIC, 'user_id' => self::OTHER, 'type' => 'text', 'content' => '<t>hello</t>', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 101, 'channel_id' => self::CH_PUBLIC, 'user_id' => null, 'type' => 'system', 'system_key' => 'user_added', 'system_data' => json_encode(['username' => 'member', 'actor' => 'other']), 'created_at' => $now, 'updated_at' => $now],
                ['id' => 102, 'channel_id' => self::CH_PUBLIC, 'user_id' => null, 'type' => 'bot', 'system_key' => 'discussion_started', 'content' => '<t>new discussion</t>', 'system_data' => json_encode(['title' => 'Mine', 'username' => 'member', 'userId' => self::MEMBER]), 'created_at' => $now, 'updated_at' => $now],
                ['id' => 103, 'channel_id' => self::CH_PUBLIC, 'user_id' => null, 'type' => 'system', 'system_key' => 'user_joined', 'system_data' => json_encode(['username' => 'other']), 'created_at' => $now, 'updated_at' => $now],
            ],
            // A rank the channel's owner gave the member, and one given to
            // someone else that must stay out of the member's export.
            'chat_channel_ranks' => [
                ['id' => 1, 'channel_id' => self::CH_PUBLIC, 'name' => 'Veteran', 'color' => '#336699', 'show_badge' => 1, 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'channel_id' => self::CH_PUBLIC, 'name' => 'Helper', 'color' => '#993366', 'show_badge' => 1, 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_rank_user' => [
                ['rank_id' => 1, 'user_id' => self::MEMBER, 'channel_id' => self::CH_PUBLIC, 'created_at' => $now],
                ['rank_id' => 2, 'user_id' => self::OTHER, 'channel_id' => self::CH_PUBLIC, 'created_at' => $now],
            ],
            'chat_message_flags' => [
                // A report the member filed, in their own words.
                ['id' => 1, 'message_id' => 100, 'user_id' => self::MEMBER, 'reason' => 'spam', 'detail' => 'my own words about it', 'created_at' => $now],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeTree(self::$bucketRoot);

        parent::tearDown();
    }

    public function test_deletion_removes_the_bytes_from_the_private_disk_and_the_bucket(): void
    {
        $remote = $this->post(self::CH_PUBLIC);
        $local = $this->post(self::CH_PRIVATE);

        $this->assertSame('fof', $remote->storage);
        $this->assertSame('local', $local->storage);
        $this->assertFileExists(self::$bucketRoot.'/'.$remote->path);
        $this->assertFileExists($this->privateRoot().'/'.$local->path);

        $this->type()->delete();

        $this->assertFileDoesNotExist(self::$bucketRoot.'/'.$remote->path);
        $this->assertFileDoesNotExist($this->privateRoot().'/'.$local->path);
        $this->assertSame(0, $this->database()->table('chat_uploads')->where('user_id', self::MEMBER)->count());
        $this->assertSame(0, $this->database()->table('chat_messages')->where('user_id', self::MEMBER)->count());
        $this->assertSame(0, $this->database()->table('chat_message_flags')->where('id', 1)->count());
        $this->assertSame(0, $this->database()->table('chat_channel_user')->where('user_id', self::MEMBER)->count());
        $this->assertNarrationScrubbed();
    }

    public function test_anonymising_detaches_the_member_and_keeps_the_conversation(): void
    {
        $remote = $this->post(self::CH_PUBLIC);
        $messageId = (int) $this->database()->table('chat_messages')->where('user_id', self::MEMBER)->value('id');
        $this->bookmark($messageId);

        $this->type()->anonymize();

        $this->assertSame(1, $this->database()->table('chat_messages')->where('id', $messageId)->whereNull('user_id')->count());
        $this->assertFileDoesNotExist(self::$bucketRoot.'/'.$remote->path);
        $this->assertSame(0, $this->database()->table('chat_bookmarks')->where('user_id', self::MEMBER)->count());
        $this->assertSame(0, $this->database()->table('chat_channel_rank_user')->where('user_id', self::MEMBER)->count(), 'a rank is a label on the person');
        $this->assertSame(1, $this->database()->table('chat_channel_rank_user')->where('user_id', self::OTHER)->count());

        $flag = $this->database()->table('chat_message_flags')->where('id', 1)->first();
        $this->assertNotNull($flag, 'the report stays as a moderation record');
        $this->assertNull($flag->user_id);
        $this->assertNull($flag->detail);

        $this->assertNarrationScrubbed();
    }

    public function test_the_export_holds_what_the_member_wrote_and_named_and_nothing_a_moderator_did(): void
    {
        $this->post(self::CH_PUBLIC);
        $messageId = (int) $this->database()->table('chat_messages')->where('user_id', self::MEMBER)->value('id');
        $this->bookmark($messageId);

        $this->database()->table('chat_message_revisions')->insert([
            ['message_id' => $messageId, 'content' => '<t>my first draft</t>', 'edited_by_id' => self::MEMBER, 'created_at' => Carbon::now()->toDateTimeString()],
            ['message_id' => $messageId, 'content' => '<t>what the moderator replaced</t>', 'edited_by_id' => 1, 'created_at' => Carbon::now()->toDateTimeString()],
        ]);

        $files = [];

        foreach ($this->type()->export() ?? [] as $entry) {
            foreach ($entry as $name => $json) {
                $files[$name] = json_decode($json, true);
            }
        }

        $message = $files["chat/message-{$messageId}.json"];
        $this->assertSame(['my first draft'], array_column($message['revisions'], 'content'));
        $this->assertSame('pic.png', $message['attachments'][0]['file_name']);

        $this->assertSame('for later', $files['chat/bookmarks.json'][0]['name']);
        $this->assertSame('my own words about it', $files['chat/reports.json'][0]['detail']);
        $this->assertSame([['channel' => 'open', 'rank' => 'Veteran']], array_map(fn ($rank) => array_intersect_key($rank, ['channel' => 1, 'rank' => 1]), $files['chat/ranks.json']), 'their own ranks, nobody else\'s');

        $this->assertSame(['content', 'revisions', 'file_name', 'name', 'detail'], ChatData::piiFields());
    }

    private function assertNarrationScrubbed(): void
    {
        $added = json_decode((string) $this->database()->table('chat_messages')->where('id', 101)->value('system_data'), true);
        $bot = json_decode((string) $this->database()->table('chat_messages')->where('id', 102)->value('system_data'), true);
        $unrelated = json_decode((string) $this->database()->table('chat_messages')->where('id', 103)->value('system_data'), true);

        $this->assertSame(['username' => 'Ghost', 'actor' => 'other'], $added);
        $this->assertSame('Ghost', $bot['username']);
        $this->assertNull($bot['userId']);
        $this->assertSame('Mine', $bot['title']);
        $this->assertSame(['username' => 'other'], $unrelated);
    }

    private function type(): ChatData
    {
        return $this->app()->getContainer()->make(ChatData::class, [
            'user'           => User::query()->findOrFail(self::MEMBER),
            'erasureRequest' => null,
        ]);
    }

    private function bookmark(int $messageId): void
    {
        $now = Carbon::now()->toDateTimeString();

        $this->database()->table('chat_bookmarks')->insert([
            'message_id' => $messageId, 'user_id' => self::MEMBER, 'name' => 'for later', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * Uploads a file and sends it, as the member; returns the stored row.
     */
    private function post(int $channelId): object
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chat');
        file_put_contents($tmp, base64_decode(self::PNG));

        $response = $this->send(
            $this->request('POST', '/api/chat/uploads', ['authenticatedAs' => self::MEMBER])
                ->withUploadedFiles(['file' => new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'pic.png', 'image/png')])
                ->withParsedBody(['channelId' => (string) $channelId])
        );

        $this->assertStatus(201, $response);
        $id = (int) json_decode((string) $response->getBody(), true)['data']['id'];

        $response = $this->send(
            $this->request('POST', '/api/chat-messages', [
                'authenticatedAs' => self::MEMBER,
                'json'            => [
                    'data' => [
                        'type'       => 'chat-messages',
                        'attributes' => ['channelId' => $channelId, 'content' => 'mine', 'uploadIds' => [$id]],
                    ],
                ],
            ])
        );

        $this->assertStatus(201, $response);

        return $this->database()->table('chat_uploads')->where('id', $id)->first();
    }

    private function assertStatus(int $expected, ResponseInterface $response): void
    {
        $this->assertEquals($expected, $response->getStatusCode(), substr((string) $response->getBody(), 0, 2000));
    }

    private function privateRoot(): string
    {
        return $this->app()->getContainer()->make(Paths::class)->storage.'/chat-uploads';
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
