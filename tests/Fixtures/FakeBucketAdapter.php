<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\Fixtures;

use FoF\Upload\Adapters\Flysystem;
use FoF\Upload\File;

/**
 * A fof/upload adapter that behaves like a bucket on a CDN: Flysystem underneath,
 * a URL on a host of its own. The bytes land in whatever directory the wrapped
 * Flysystem adapter points at, so a test can look at them on disk.
 *
 * Only loaded by tests that have already checked fof/upload is installed.
 */
class FakeBucketAdapter extends Flysystem
{
    public const HOST = 'https://bucket.chat.test';

    /**
     * When set, every write is refused the way a backend outage is reported:
     * `upload()` answers false.
     */
    public bool $refuse = false;

    public function upload(File $file, $upload = null, $contents = null)
    {
        if ($this->refuse) {
            return false;
        }

        return parent::upload($file, $upload, $contents);
    }

    protected function generateUrl(File $file): void
    {
        $file->url = self::HOST.'/'.$file->path;
    }
}
