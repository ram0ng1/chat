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
     * Opening the chat at all.
     *
     * The ability is `useChat` while the permission remains `ramon-chat.use`,
     * the same split flarum/messages makes between `sendAnyMessage` and
     * `dialog.sendMessage`. The two names have to differ because `checkAbility`
     * dispatches on `method_exists($this, $ability)`, and `ramon-chat.use` is
     * not a method name — asking the Gate for it therefore matched nothing here
     * and fell through to the fallback described above, where any other
     * extension's `can()` catch-all could answer for us.
     *
     * With a method to dispatch to, this returns an explicit DENY, and DENY
     * outranks ALLOW in the Gate's priority order. A blanket-allowing catch-all
     * elsewhere can no longer open the chat.
     */
    public function useChat(User $actor): bool
    {
        return $actor->hasPermission('ramon-chat.use');
    }

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
