<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Mention;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Ramon\Chat\Message;
use Ramon\Chat\MessageMention;

/**
 * Extracts the mentions from a message and persists them as rows.
 *
 * Resolving once at send time (rather than re-parsing on every read) is what
 * lets notification fan-out and unread-mention counters stay cheap queries.
 */
class MentionResolver
{
    /**
     * Matches @username, @"display name", @group-name, @here and @all.
     *
     * Flarum's own mentions extension rewrites @mentions into `@"name"#uid`
     * tokens at parse time, so we read the parsed XML form when present and fall
     * back to the plain-text form otherwise (e.g. webhook-delivered messages).
     */
    private const PLAIN_PATTERN = '/(?<![\w\/])@("(?<quoted>[^"]{1,80})"|(?<plain>[a-z0-9_.\-]{1,80}))/i';

    /**
     * The parsed representation flarum/mentions produces, which carries the
     * resolved id and is therefore authoritative when available.
     */
    private const TAG_PATTERN = '/<(?<kind>USERMENTION|GROUPMENTION)[^>]*\b(?<attr>id|username|groupname)="(?<value>[^"]+)"/i';

    /**
     * @return Collection<int, MessageMention> Unsaved mention rows.
     */
    public function resolve(Message $message, bool $allowChannelWide): Collection
    {
        // Read the stored (parsed) representation directly rather than through
        // the accessor, which would unparse it back to author-facing text.
        $raw = $message->getAttributes()['content'] ?? '';

        if ($raw === '' || $raw === null) {
            return collect();
        }

        $mentions = collect();
        // Asked only when a group is actually named: it reads the author, and
        // most messages mention no group at all.
        $mayMentionGroupsMemo = null;
        $mayMentionGroups = function () use ($message, &$mayMentionGroupsMemo): bool {
            return $mayMentionGroupsMemo ??= $this->mayMentionGroups($message);
        };
        $seenUsers = [];
        $seenGroups = [];
        $seenChannelWide = [];

        foreach ($this->parsedMentions($raw) as [$type, $id]) {
            if ($type === MessageMention::TYPE_USER && ! isset($seenUsers[$id])) {
                $seenUsers[$id] = true;
                $mentions->push($this->make($message, MessageMention::TYPE_USER, userId: $id));
            }

            if ($type === MessageMention::TYPE_GROUP && ! isset($seenGroups[$id])) {
                $seenGroups[$id] = true;

                // The parsed form records the group, not whether the author was
                // allowed to name it: flarum/mentions only checks when it parses
                // with an actor in hand, and not every path that stores content
                // has one.
                if (! $mayMentionGroups() || $this->mentionableGroup($id) === null) {
                    continue;
                }

                $mentions->push($this->make($message, MessageMention::TYPE_GROUP, groupId: $id));
            }
        }

        // @here / @all are never encoded by flarum/mentions, so they are always
        // read from the plain text.
        if ($allowChannelWide) {
            foreach ($this->channelWideMentions($raw) as $type) {
                if (isset($seenChannelWide[$type])) {
                    continue;
                }

                $seenChannelWide[$type] = true;
                $mentions->push($this->make($message, $type));
            }
        }

        // Fall back to name-based resolution only when the parsed form produced
        // nothing — otherwise we would double-count the same mention.
        if ($seenUsers === [] && $seenGroups === []) {
            foreach ($this->plainNames($raw) as $name) {
                $user = $this->findUserByName($name);

                if ($user !== null && ! isset($seenUsers[$user->id])) {
                    $seenUsers[$user->id] = true;
                    $mentions->push($this->make($message, MessageMention::TYPE_USER, userId: $user->id));

                    continue;
                }

                // Groups by name only for someone allowed to ping a group at all.
                // Without the check any member's `@Moderators` reached every user
                // in the group.
                $group = $mayMentionGroups() ? $this->findGroupByName($name) : null;

                if ($group !== null && ! isset($seenGroups[$group->id])) {
                    $seenGroups[$group->id] = true;
                    $mentions->push($this->make($message, MessageMention::TYPE_GROUP, groupId: $group->id));
                }
            }
        }

        return $mentions;
    }

    /**
     * Replaces the message's mention rows with a freshly resolved set. Used on
     * both send and edit so an edit that removes a mention also removes the
     * corresponding unread-mention pressure.
     */
    public function sync(Message $message, bool $allowChannelWide): Collection
    {
        $message->mentions()->delete();

        $mentions = $this->resolve($message, $allowChannelWide);

        foreach ($mentions as $mention) {
            $mention->save();
        }

        return $mentions;
    }

    /**
     * @return iterable<array{0: string, 1: int}>
     */
    protected function parsedMentions(string $raw): iterable
    {
        if (! preg_match_all(self::TAG_PATTERN, $raw, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            // Only the numeric `id` attribute is trustworthy; username/groupname
            // attributes are display copies that may be stale after a rename.
            if (strtolower($match['attr']) !== 'id') {
                continue;
            }

            $id = (int) $match['value'];

            if ($id <= 0) {
                continue;
            }

            yield strtoupper($match['kind']) === 'USERMENTION'
                ? [MessageMention::TYPE_USER, $id]
                : [MessageMention::TYPE_GROUP, $id];
        }
    }

    /**
     * @return iterable<string>
     */
    protected function channelWideMentions(string $raw): iterable
    {
        // Word-boundary guards keep "@allen" and "email@here.com" from matching.
        if (preg_match('/(?<![\w\/])@here\b/i', $raw)) {
            yield MessageMention::TYPE_HERE;
        }

        if (preg_match('/(?<![\w\/])@all\b/i', $raw)) {
            yield MessageMention::TYPE_ALL;
        }
    }

    /**
     * @return iterable<string>
     */
    protected function plainNames(string $raw): iterable
    {
        if (! preg_match_all(self::PLAIN_PATTERN, $raw, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $name = $match['quoted'] !== '' ? $match['quoted'] : ($match['plain'] ?? '');

            if ($name === '' || in_array(strtolower($name), ['here', 'all'], true)) {
                continue;
            }

            yield $name;
        }
    }

    /**
     * Matches on `username` only. `display_name` is a computed property, not a
     * column, so it cannot be queried; nickname-based lookup is left to
     * flarum/mentions, whose parsed output this method is only a fallback for.
     */
    protected function findUserByName(string $name): ?User
    {
        return User::query()->where('username', $name)->first();
    }

    protected function findGroupByName(string $name): ?Group
    {
        return $this->mentionableGroups()
            ->where(function ($query) use ($name) {
                $query->where('name_singular', $name)
                    ->orWhere('name_plural', $name);
            })
            ->first();
    }

    protected function mentionableGroup(int $id): ?Group
    {
        return $this->mentionableGroups()->whereKey($id)->first();
    }

    /**
     * The groups a mention may name: never guests or members, which would be
     * the whole forum, and never a hidden group, whose membership is not public.
     * The same exclusions flarum/mentions applies to a post.
     *
     * @return Builder<Group>
     */
    protected function mentionableGroups(): Builder
    {
        $query = Group::query();

        // Statements rather than a chain: the where* methods are typed as
        // returning the base query builder, which would lose the Eloquent one.
        $query->where('is_hidden', false);
        $query->whereNotIn('id', [Group::GUEST_ID, Group::MEMBER_ID]);

        return $query;
    }

    /**
     * `mentionGroups` is core's permission for the same act in a post. A bot or
     * webhook message has no author and so cannot ping a group.
     */
    protected function mayMentionGroups(Message $message): bool
    {
        if ($message->user_id === null) {
            return false;
        }

        $author = $message->relationLoaded('user') ? $message->user : $message->user()->first();

        return $author instanceof User && $author->hasPermission('mentionGroups');
    }

    protected function make(Message $message, string $type, ?int $userId = null, ?int $groupId = null): MessageMention
    {
        $mention = new MessageMention();

        $mention->message_id = $message->id;
        $mention->type = $type;
        $mention->user_id = $userId;
        $mention->group_id = $groupId;
        $mention->created_at = Carbon::now();

        return $mention;
    }
}
