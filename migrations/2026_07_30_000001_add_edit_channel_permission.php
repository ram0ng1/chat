<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Flarum\Group\Group;

/**
 * Editing an existing channel is its own right, separate from creating one.
 *
 * A community may well want a group that can rename and re-describe channels, set
 * their emoji or toggle threading, without also being able to spawn new ones — and
 * without being handed the whole `moderate` surface, which additionally allows
 * deleting other people's messages and moving them between channels.
 *
 * Defaults to administrators, matching `createChannel`. Moderators retain edit
 * access through `moderate`, so this migration widens nothing on its own.
 */
return Migration::addPermissions([
    'ramon-chat.editChannel' => Group::ADMINISTRATOR_ID,
]);
