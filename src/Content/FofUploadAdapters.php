<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Content;

use Flarum\Frontend\Document;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Storage\UploadStorage;

/**
 * The fof/upload adapters the admin may store chat attachments with, for the
 * dropdown on the settings page.
 *
 * Admin frontend only, and wired only while fof/upload is enabled, so neither
 * the forum payload nor a forum without fof/upload ever runs it. Adapter keys
 * only: no bucket, region or credential is read here.
 */
class FofUploadAdapters
{
    public function __construct(
        protected UploadStorage $storage,
        protected LoggerInterface $log
    ) {
    }

    public function __invoke(Document $document): void
    {
        $keys = [];

        try {
            if ($this->storage->available()) {
                $keys = $this->storage->fof()->adapterKeys();
            }
        } catch (\Throwable $e) {
            // An adapter whose package is half installed must not take the
            // admin panel down with it; the dropdown then offers the mapping only.
            $this->log->warning('[ramon-chat] could not list fof/upload adapters: '.$e->getMessage());
        }

        $document->payload['ramonChatFofUploadAdapters'] = $keys;
    }
}
