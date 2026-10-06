<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage;

/**
 * An upload store could not write, read or remove a file, or is not available
 * at all. The message is for the log, never for the client: it can carry a
 * bucket name or a path.
 */
class StorageFailed extends \RuntimeException
{
}
