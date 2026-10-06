<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Event;

use Flarum\User\User;
use Ramon\Chat\Channel;

/**
 * One move, after it committed: every message it carried, grouped by the
 * channel each came from.
 *
 * MessageWasMoved still fires per message, inside the transaction, for the
 * listeners that work row by row. This one exists for whoever needs the move as
 * a whole and only once it is real — the websocket push, which would otherwise
 * be one trigger per message per channel and could land before the commit.
 */
class MessagesWereMoved
{
    /**
     * @param  array<int, int[]>  $messageIdsBySource  Source channel id => moved message ids.
     * @param  array<int, Channel>  $sources            Source channel id => channel.
     * @param  array<int, int>  $threadIds             Moved message id => the thread it left.
     */
    public function __construct(
        public array $messageIdsBySource,
        public array $sources,
        public Channel $target,
        public array $threadIds = [],
        public ?User $actor = null
    ) {
    }
}
