<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Group\Group;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Builder;

/**
 * Removes the memberships an earlier AutoJoinUsers wrote for people who cannot
 * use the chat.
 *
 * That listener added every row in `users` to an `auto_join` channel, with no
 * permission check and no visibility check. On a forum running the chat for one
 * group, everyone else was left holding memberships they could never open — and
 * those rows fed the header badge, which sums `unread_count` per membership
 * rather than re-deriving the permission. The listener now filters; this clears
 * what it already wrote.
 *
 * Scope is deliberately narrow. Only `auto_join` channels are touched, because
 * only those were populated in bulk: a membership anywhere else was created by
 * someone joining, being invited, or being added by a moderator, and none of
 * those are this bug. Rows are removed whether or not the user has since left —
 * a departure from a channel they never had access to is not history worth
 * keeping.
 *
 * The eligibility rule is written out here rather than shared with
 * `Ramon\Chat\Listener\AutoJoinUsers`. A migration is frozen history: pointing it
 * at a helper would let a later edit change what this did to forums that have
 * not run it yet. It mirrors `User::permissions()` as of writing — permissions
 * are group-derived, an unconfirmed account gets guest rights only, and
 * administrators short-circuit.
 *
 * No rollback. Restoring a membership would mean inventing the read state that
 * came with it.
 */
return [
    'up' => function (Builder $schema) {
        // Flarum's Migrator hands a closure migration the schema builder and
        // nothing else, so the connection is reached through it.
        $db = $schema->getConnection();

        $groupIds = $db->table('group_permission')
            ->where('permission', 'ramon-chat.use')
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Granted to guests, so every account carries it — there is nobody
        // ineligible to purge.
        if (in_array(Group::GUEST_ID, $groupIds, true)) {
            return;
        }

        $memberGranted = in_array(Group::MEMBER_ID, $groupIds, true);

        $inGroups = function (QueryBuilder $query, array $ids): void {
            $query->whereExists(function (QueryBuilder $sub) use ($ids) {
                $sub->selectRaw('1')
                    ->from('group_user')
                    ->whereColumn('group_user.user_id', 'users.id')
                    ->whereIn('group_user.group_id', $ids);
            });
        };

        $eligible = function (QueryBuilder $query) use ($groupIds, $memberGranted, $inGroups): void {
            $query->select('users.id')
                ->from('users')
                ->where(function (QueryBuilder $query) use ($groupIds, $memberGranted, $inGroups) {
                    // Administrators hold every permission implicitly, and
                    // `isAdmin()` reads the group rows directly — confirmation
                    // does not gate it.
                    $inGroups($query, [Group::ADMINISTRATOR_ID]);

                    $query->orWhere(function (QueryBuilder $query) use ($groupIds, $memberGranted, $inGroups) {
                        // Confirmation is what admits MEMBER and the account's
                        // own groups into its permission set.
                        $query->where('users.is_email_confirmed', true);

                        // Granted to members, which every confirmed account
                        // belongs to implicitly — no group check to make.
                        if (! $memberGranted) {
                            $inGroups($query, $groupIds);
                        }
                    });
                });
        };

        $channelIds = $db->table('chat_channels')
            ->where('auto_join', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($channelIds === []) {
            return;
        }

        $db->table('chat_channel_user')
            ->whereIn('channel_id', $channelIds)
            ->whereNotIn('user_id', $eligible)
            ->delete();

        // `user_count` is denormalised — see Channel::refreshMetadata(). Left
        // stale it reports members the browse page cannot list. Recomputed per
        // channel rather than in one correlated UPDATE: `auto_join` channels are
        // few, and a plain query builder is portable across the drivers Flarum
        // supports.
        foreach ($channelIds as $channelId) {
            $db->table('chat_channels')
                ->where('id', $channelId)
                ->update([
                    'user_count' => $db->table('chat_channel_user')
                        ->where('channel_id', $channelId)
                        ->whereNull('left_at')
                        ->count(),
                ]);
        }
    },

    'down' => fn () => null,
];
