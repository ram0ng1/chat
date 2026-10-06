<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage;

use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Upload;

/**
 * Picks the store for an upload.
 *
 * For a new file the rule is short. Private files — a private channel's, a
 * direct conversation's, a restricted category's — always go on the chat's own
 * private disk, whatever is configured: fof/upload's storage is public by
 * design, and Service\UploadPrivacy::requiredFor stays the one rule deciding
 * what is private. Public files go through fof/upload only when the admin chose
 * it AND fof/upload is installed and enabled; otherwise the local public disk.
 *
 * For an existing file the row decides: `storage` records where the bytes went,
 * so a later change of setting never strands what was written before it.
 */
class UploadStorage
{
    public const SETTING = 'ramon-chat.upload_storage';
    public const SETTING_FOF = 'fof-upload';

    protected ?LocalUploadStore $local = null;
    protected ?FofUploadStore $fof = null;

    public function __construct(
        protected Container $container,
        protected SettingsRepositoryInterface $settings,
        protected ExtensionManager $extensions,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Whether fof/upload can be used as storage right now.
     */
    public function available(): bool
    {
        return FofUploadStore::supported() && $this->extensions->isEnabled('fof-upload');
    }

    /**
     * Whether new public files are meant to go through fof/upload.
     */
    public function usesFof(): bool
    {
        return $this->settings->get(self::SETTING) === self::SETTING_FOF && $this->available();
    }

    public function forNew(bool $private): UploadStore
    {
        if ($private || ! $this->usesFof()) {
            return $this->local();
        }

        return $this->fof();
    }

    /**
     * @throws StorageFailed when the file is on fof/upload and fof/upload is no
     *                       longer available.
     */
    public function for(Upload $upload): UploadStore
    {
        if (! $upload->isRemote()) {
            return $this->local();
        }

        if (! $this->available()) {
            throw new StorageFailed("Upload {$upload->id} is stored through fof/upload, which is not enabled.");
        }

        return $this->fof();
    }

    /**
     * Best effort: every caller is removing the row as well, and a file that
     * will not go must not keep the rest of a batch published. Logged at error
     * level, because a remote copy that outlives its row is unreachable by any
     * later cleanup and only an operator can remove it.
     */
    public function delete(Upload $upload): bool
    {
        try {
            $this->for($upload)->delete($upload);

            return true;
        } catch (\Throwable $e) {
            $this->log->error('[ramon-chat] could not delete an upload\'s file', [
                'upload'  => $upload->id,
                'storage' => $upload->storage,
                'adapter' => $upload->storage_adapter,
                'path'    => $upload->path,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function local(): LocalUploadStore
    {
        return $this->local ??= $this->container->make(LocalUploadStore::class);
    }

    public function fof(): FofUploadStore
    {
        return $this->fof ??= $this->container->make(FofUploadStore::class);
    }
}
