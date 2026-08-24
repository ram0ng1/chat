<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\User\User;
use Ramon\Chat\Channel;

/**
 * Answers "can this actor see channel N" once per distinct permission set.
 *
 * The companion to VisibilityCache, for the opposite shape of problem. That one
 * memoises many questions about one actor; this one memoises one question about
 * many actors, which is what a fan-out is — sending a message resolves the
 * audience for a broadcast and again for notifications, and both walked every
 * member running an `EXISTS` that carries the whole tag-visibility subquery. A
 * busy channel was therefore slowest to post in, which is backwards.
 *
 * Bucketing is sound because channel visibility is a function of the actor's
 * permissions and their membership, and nothing else. ScopeChannelVisibility
 * asks three things of a category channel — `useChat`, the bound tag's
 * `viewForum`, and the private-channel exemptions — and all of them resolve
 * through `hasPermission`. The one per-user term is the membership branch, so
 * callers must only share a bucket between users whose membership is already
 * settled the same way; both callers here have just read the members from
 * `chat_channel_user`, so it holds for every user they ask about.
 *
 * `permissionGroupIds()` is the key rather than the `groups` relation because it
 * is what core actually resolves permissions from: it folds in guest and member,
 * drops the latter for an unconfirmed email, and runs the group processors
 * extensions register — which is how flarum/suspend demotes a suspended user.
 * Keying on the raw relation would put a suspended member in the same bucket as
 * an active one.
 *
 * Not a singleton: an instance lives for one fan-out. A longer-lived cache would
 * have to answer what happens when a permission is granted mid-request, and
 * there is no reason to take that on.
 */
class PermissionSetVisibility
{
    /** @var array<string, bool> */
    private array $answers = [];

    public function channelVisible(User $user, int $channelId): bool
    {
        $key = $channelId.'|'.self::key($user);

        return $this->answers[$key] ??= Channel::whereVisibleTo($user)
            ->whereKey($channelId)
            ->exists();
    }

    /**
     * The permission sets already resolved, for assertions and diagnostics.
     */
    public function bucketCount(): int
    {
        return count($this->answers);
    }

    /**
     * A stable identity for everything that can change a permission answer.
     */
    public static function key(User $user): string
    {
        $groupIds = $user->permissionGroupIds();
        sort($groupIds);

        return implode(',', $groupIds);
    }
}
