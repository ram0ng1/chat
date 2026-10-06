<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Storage;

use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Storage\FofUploadStore;
use Ramon\Chat\Storage\LocalUploadStore;
use Ramon\Chat\Storage\StorageFailed;
use Ramon\Chat\Storage\UploadStorage;
use Ramon\Chat\Upload;

/**
 * Which store a file goes to.
 *
 * The rule the whole feature hangs on: private files never leave the chat's own
 * private disk, and public ones go to fof/upload only when the admin chose it
 * and fof/upload is actually enabled. Every combination is spelled out, because
 * the one that matters most — private while fof/upload is chosen and enabled —
 * is exactly the one a careless refactor would get wrong.
 */
class UploadStorageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected LocalUploadStore $local;
    protected FofUploadStore $fof;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FofUploadStore::supported()) {
            $this->markTestSkipped('fof/upload is not installed; composer install with dev dependencies.');
        }

        $this->local = Mockery::mock(LocalUploadStore::class);
        $this->fof = Mockery::mock(FofUploadStore::class);
    }

    /**
     * @return array<string, array{string, bool, bool, string}>
     */
    public static function newFiles(): array
    {
        return [
            'public, local chosen, fof enabled'      => ['local', true, false, 'local'],
            'public, fof chosen, fof enabled'        => ['fof-upload', true, false, 'fof'],
            'public, fof chosen, fof disabled'       => ['fof-upload', false, false, 'local'],
            'private, fof chosen, fof enabled'       => ['fof-upload', true, true, 'local'],
            'private, local chosen, fof enabled'     => ['local', true, true, 'local'],
            'private, fof chosen, fof disabled'      => ['fof-upload', false, true, 'local'],
            'public, nothing saved yet, fof enabled' => ['', true, false, 'local'],
        ];
    }

    #[DataProvider('newFiles')]
    public function test_a_new_file_goes_where_the_rule_says(string $setting, bool $enabled, bool $private, string $expected): void
    {
        $store = $this->storage($setting, $enabled)->forNew($private);

        $this->assertSame($expected === 'fof' ? $this->fof : $this->local, $store);
    }

    public function test_an_existing_file_follows_its_row_not_the_setting(): void
    {
        $storage = $this->storage('local', true);

        $this->assertSame($this->fof, $storage->for($this->upload('fof')));
        $this->assertSame($this->local, $storage->for($this->upload('local')));
    }

    public function test_a_remote_file_cannot_be_reached_once_fof_upload_is_disabled(): void
    {
        $this->expectException(StorageFailed::class);

        $this->storage('fof-upload', false)->for($this->upload('fof'));
    }

    public function test_a_failed_delete_is_logged_and_reported_not_thrown(): void
    {
        $log = Mockery::mock(LoggerInterface::class);
        $log->shouldReceive('error')->once();

        $this->local->shouldReceive('delete')->once()->andThrow(new StorageFailed('refused'));

        $this->assertFalse($this->storage('local', false, $log)->delete($this->upload('local')));
    }

    public function test_a_successful_delete_goes_to_the_rows_store(): void
    {
        $upload = $this->upload('fof');
        $this->fof->shouldReceive('delete')->once()->with($upload);

        $this->assertTrue($this->storage('local', true)->delete($upload));
    }

    protected function storage(string $setting, bool $enabled, ?LoggerInterface $log = null): UploadStorage
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturnUsing(
            fn (string $key, $default = null) => $key === UploadStorage::SETTING ? $setting : $default
        );

        $extensions = Mockery::mock(ExtensionManager::class);
        $extensions->shouldReceive('isEnabled')->with('fof-upload')->andReturn($enabled);

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('make')->with(LocalUploadStore::class)->andReturn($this->local);
        $container->shouldReceive('make')->with(FofUploadStore::class)->andReturn($this->fof);

        return new UploadStorage($container, $settings, $extensions, $log ?? Mockery::mock(LoggerInterface::class));
    }

    protected function upload(string $storage): Upload
    {
        $upload = new Upload();
        $upload->setRawAttributes(['id' => 1, 'path' => 'a/b.png', 'storage' => $storage, 'is_private' => false], true);

        return $upload;
    }
}
