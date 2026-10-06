<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Content;

use Flarum\Frontend\Document;
use Illuminate\Contracts\Queue\Queue;
use Ramon\Chat\Realtime\QueueKind;

/**
 * The forum's queue kind (sync, database or other), for the realtime card on
 * the admin settings page, which offers the queue toggle only where a
 * continuous worker exists. Admin frontend only; no connection detail leaves
 * the server, just the kind.
 */
class QueueDriver
{
    public function __construct(protected Queue $queue)
    {
    }

    public function __invoke(Document $document): void
    {
        $document->payload['ramonChatQueueKind'] = QueueKind::of($this->queue);
    }
}
