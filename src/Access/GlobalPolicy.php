<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Global chat abilities.
 *
 * ## Why these return a definite bool, unlike the model policies
 *
 * Flarum's Gate falls through to `isAdmin() || hasPermission($ability)` only when
 * *no* policy reached a decision. Abstaining (returning null) therefore hands the
 * outcome to that fallback — and to every other extension's global policy, any of
 * which may define a `can()` catch-all that blanket-allows unknown abilities. On a
 * forum with a few dozen extensions that is not hypothetical: measured here,
 * abstaining made `startDirect` resolve true for a user with no groups, despite the
 * permission being granted to moderators only.
 *
 * The fallback would also be wrong even in isolation. It tests
 * `hasPermission('startDirect')` — the *ability* name — whereas the permission
 * actually stored is `ramon-chat.startDirect`. So it can never legitimately grant
 * this; it can only leak it.
 *
 * These gates are therefore authoritative: they answer true or false and never
 * abstain. `hasPermission()` is used rather than `can()` for the same reason — it
 * reads the actor's groups directly (short-circuiting for admins) instead of
 * re-entering the Gate.
 */
class GlobalPolicy extends AbstractPolicy
{
    /**
     * Starting a direct message also requires the base chat gate — a group granted
     * only `startDirect` must not be able to bypass the opt-in.
     */
    public function startDirect(User $actor): bool
    {
        if (! $actor->hasPermission('ramon-chat.use')) {
            return false;
        }

        return $actor->hasPermission('ramon-chat.startDirect');
    }

    public function createChannel(User $actor): bool
    {
        if (! $actor->hasPermission('ramon-chat.use')) {
            return false;
        }

        return $actor->hasPermission('ramon-chat.createChannel');
    }
}
