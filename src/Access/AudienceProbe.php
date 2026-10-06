<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * An in-memory account holding exactly a given set of permissions, used to ask
 * "could the least-privileged reader of this place see that?".
 *
 * Copying something from one audience into another — a new discussion into a
 * channel — is only safe when everyone who can read the destination could
 * already read the source. Listing every reader is not possible for a public
 * room, so the question is asked of the weakest reader instead: a confirmed
 * member with no groups beyond what the destination itself demands.
 *
 * The probe has id 0, so every "is this yours" branch of a visibility scope
 * (the author of an unapproved post, a recipient of a private discussion) finds
 * nothing, and no groups, so it is never an administrator. It is never saved.
 */
final class AudienceProbe extends User
{
    /**
     * @param  string[]  $extra  Permissions on top of what guests and members hold.
     */
    public static function memberWith(array $extra = []): self
    {
        $base = Permission::query()
            ->whereIn('group_id', [Group::GUEST_ID, Group::MEMBER_ID])
            ->pluck('permission')
            ->all();

        $probe = new self();
        $probe->id = 0;
        $probe->exists = true;
        $probe->is_email_confirmed = true;
        $probe->setRelation('groups', new Collection());

        // Preset so `getPermissions()` never derives them from groups: the point
        // is that this account holds what it is given and nothing else.
        $probe->permissions = array_values(array_unique(array_merge($base, $extra)));

        return $probe;
    }

    public function save(array $options = []): bool
    {
        return false;
    }
}
