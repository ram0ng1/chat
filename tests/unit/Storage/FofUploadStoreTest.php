<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Storage;

use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\Upload\Adapters\Manager;
use FoF\Upload\Contracts\UploadAdapter;
use FoF\Upload\Helpers\Util;
use Illuminate\Contracts\Container\Container;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Ramon\Chat\Storage\FofUploadStore;
use Ramon\Chat\Storage\StorageFailed;
use Ramon\Chat\Tests\Fixtures\FakeBucketAdapter;
use Ramon\Chat\Upload;

/**
 * fof/upload used as a storage driver, and only as one.
 *
 * What is pinned here: the adapter writes the bytes under a server-generated
 * name and the row learns where they went; an adapter that is not Flysystem
 * (Imgur) is refused rather than half-used; and an adapter that fails is a
 * StorageFailed, which the controller turns into a refused upload.
 */
class FofUploadStoreTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** A 1×1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FofUploadStore::supported()) {
            $this->markTestSkipped('fof/upload is not installed; composer install with dev dependencies.');
        }

        $this->root = sys_get_temp_dir().'/chat-fof-'.bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function test_a_public_file_is_written_through_the_adapter_and_recorded_on_the_row(): void
    {
        $bucket = $this->bucket();
        $upload = $this->newUpload();

        $this->store('chat-bucket', ['chat-bucket' => $bucket])->put($upload, $this->stream(), 'png');

        $this->assertSame(Upload::STORAGE_FOF, $upload->storage);
        $this->assertSame('chat-bucket', $upload->storage_adapter);
        $this->assertStringStartsWith(FakeBucketAdapter::HOST.'/', (string) $upload->remote_url);
        $this->assertStringEndsWith('.png', $upload->path);
        $this->assertStringNotContainsString('pic', $upload->path, 'The client filename must never reach the path.');
        $this->assertSame(base64_decode(self::PNG), file_get_contents($this->root.'/'.$upload->path));
        $this->assertTrue($upload->isRemote());
    }

    public function test_deleting_removes_the_bytes_from_the_adapter_it_was_written_with(): void
    {
        $bucket = $this->bucket();
        $upload = $this->newUpload();
        $store = $this->store('chat-bucket', ['chat-bucket' => $bucket]);

        $store->put($upload, $this->stream(), 'png');
        $this->assertFileExists($this->root.'/'.$upload->path);

        $store->delete($upload);

        $this->assertFileDoesNotExist($this->root.'/'.$upload->path);
    }

    public function test_an_adapter_that_is_not_flysystem_is_refused(): void
    {
        $imgur = Mockery::mock(UploadAdapter::class);
        $imgur->shouldNotReceive('upload');

        $this->expectException(StorageFailed::class);

        $this->store('imgur', ['imgur' => $imgur])->put($this->newUpload(), $this->stream(), 'png');
    }

    public function test_a_mime_mapping_that_names_no_adapter_is_refused(): void
    {
        $this->expectException(StorageFailed::class);

        $this->store('', [], mapped: null)->put($this->newUpload(), $this->stream(), 'png');
    }

    public function test_the_mime_mapping_is_followed_when_no_adapter_is_chosen(): void
    {
        $upload = $this->newUpload();

        $this->store('', [], mapped: $this->bucket())->put($upload, $this->stream(), 'png');

        $this->assertSame(Upload::STORAGE_FOF, $upload->storage);
    }

    public function test_a_failing_adapter_is_a_storage_failure(): void
    {
        $bucket = $this->bucket();
        $bucket->refuse = true;

        $this->expectException(StorageFailed::class);

        $this->store('chat-bucket', ['chat-bucket' => $bucket])->put($this->newUpload(), $this->stream(), 'png');
    }

    public function test_imgur_is_never_offered(): void
    {
        $manager = Mockery::mock(Manager::class);
        $manager->shouldReceive('adapters')->andReturn(collect([
            'aws-s3' => true,
            'imgur'  => true,
            'qiniu'  => false,
            'local'  => true,
        ]));

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('make')->with(Manager::class)->andReturn($manager);

        $keys = (new FofUploadStore($container, Mockery::mock(SettingsRepositoryInterface::class)))->adapterKeys();

        $this->assertSame(['aws-s3', 'local'], $keys);
    }

    /**
     * @param  array<string, UploadAdapter>  $adapters
     */
    protected function store(string $chosen, array $adapters, ?UploadAdapter $mapped = null): FofUploadStore
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturnUsing(
            fn (string $key, $default = null) => $key === 'ramon-chat.fof_upload_adapter' ? $chosen : $default
        );

        $manager = Mockery::mock(Manager::class);
        $manager->shouldReceive('instantiate')->andReturnUsing(function (string $key) use ($adapters) {
            if (! isset($adapters[$key])) {
                throw new \RuntimeException("No adapter configured for $key");
            }

            $adapter = $adapters[$key];

            if (property_exists($adapter, 'adapterKey')) {
                $adapter->adapterKey = $key;
            }

            return $adapter;
        });

        $util = Mockery::mock(Util::class);
        $util->shouldReceive('getAdapterForMime')->andReturn($mapped);

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('make')->with(Manager::class)->andReturn($manager);
        $container->shouldReceive('make')->with(Util::class)->andReturn($util);

        return new FofUploadStore($container, $settings);
    }

    protected function bucket(): FakeBucketAdapter
    {
        return new FakeBucketAdapter(
            new LocalFilesystemAdapter($this->root),
            Mockery::mock(SettingsRepositoryInterface::class),
            Mockery::mock(UrlGenerator::class)
        );
    }

    protected function newUpload(): Upload
    {
        $upload = new Upload();
        $upload->is_private = false;
        $upload->mime_type = 'image/png';
        $upload->size = strlen(base64_decode(self::PNG));
        $upload->file_name = 'pic.png';

        return $upload;
    }

    /**
     * @return resource
     */
    protected function stream()
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, base64_decode(self::PNG));
        rewind($stream);

        return $stream;
    }

    protected function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
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
