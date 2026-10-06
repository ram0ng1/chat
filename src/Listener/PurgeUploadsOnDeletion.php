<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\Storage\UploadStorage;
use Ramon\Chat\Upload;

/**
 * Takes a deleted message's attachments off the disk.
 *
 * Deleting a message hid its text and left its files exactly where they were.
 * The chat disk is public — it lives under `public/assets/chat` and is served by
 * the web server with no authorisation in front of it — so an image stayed fully
 * readable to anyone holding its URL, which after a moderator removal is the
 * whole channel. The tombstone was a curtain in front of an open door.
 *
 * Only retention pruning cleaned these up, and only for forums that configured a
 * retention window at all; the default of 0 means never.
 *
 * The trade this makes: restoring a deleted message brings back its text but not
 * its attachments. That is a real loss and it is the deliberate side to lose —
 * the alternative is a picture that stays published after being deleted, and no
 * amount of restorability is worth that. The rows go with the files so a restored
 * message does not render broken images.
 */
class PurgeUploadsOnDeletion
{
    public function __construct(
        protected UploadStorage $storage
    ) {
    }

    public function handle(MessageWasDeleted $event): void
    {
        $uploads = Upload::query()
            ->where('message_id', $event->message->id)
            ->get();

        if ($uploads->isEmpty()) {
            return;
        }

        // Best effort per file, wherever it is stored: the row still has to go,
        // and refusing to continue would leave the rest of the message's
        // attachments published. A failure is logged by the resolver.
        foreach ($uploads as $upload) {
            $this->storage->delete($upload);
        }

        Upload::query()->whereIn('id', $uploads->pluck('id')->all())->delete();
    }
}
