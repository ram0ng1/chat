<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Gdpr;

use Flarum\Gdpr\Data\Type;
use Illuminate\Support\Arr;
use Ramon\Chat\Bookmark;
use Ramon\Chat\ChannelInvite;
use Ramon\Chat\ChannelTransfer;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Draft;
use Ramon\Chat\Message;
use Ramon\Chat\MessageFlag;
use Ramon\Chat\MessageReaction;
use Ramon\Chat\MessageRevision;
use Ramon\Chat\Service\OwnershipSuccession;
use Ramon\Chat\Storage\UploadStorage;
use Ramon\Chat\ThreadUser;
use Ramon\Chat\Upload;

/**
 * The chat's contribution to flarum/gdpr's export and erasure.
 *
 * Registered through `Flarum\Gdpr\Extend\UserData`, behind a Conditional so the
 * class is only loaded when the extension is enabled — its parent lives in
 * flarum/gdpr, and referencing it unconditionally would fatal on a forum without it.
 *
 * ## What anonymising means here, and why it is not deletion
 *
 * A chat is a conversation. Deleting one participant's lines leaves everyone else's
 * replies answering nothing, which damages other people's records to satisfy one
 * person's request. Anonymising therefore detaches authorship — `user_id` becomes
 * null, which the schema already allows for system messages — and leaves the text
 * in place. Deletion is the stronger request and does remove the messages; that is
 * the user's choice to make, and flarum/gdpr presents both.
 *
 * Uploads are removed in both cases: a file is personal data in a way a sentence in
 * a shared conversation is not, and an orphaned attachment under someone else's
 * reply serves nobody.
 */
class ChatData extends Type
{
    public static function dataType(): string
    {
        return 'ChatMessages';
    }

    /**
     * Overridden because the base builds its key from the type name and looks it up
     * under `flarum-gdpr.lib.data.*`, where a third-party type has no entry. Ours
     * live in our own locale file.
     */
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ramon-chat.lib.gdpr.export_description');
    }

    public static function anonymizeDescription(): string
    {
        return self::staticTranslator()->trans('ramon-chat.lib.gdpr.anonymize_description');
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ramon-chat.lib.gdpr.delete_description');
    }

    /**
     * Keys of the exported files that carry what the user wrote or named:
     * message text and its earlier versions, attachment names, bookmark names
     * and the free text of a report.
     */
    public static function piiFields(): array
    {
        return ['content', 'revisions', 'file_name', 'name', 'detail'];
    }

    public function export(): ?array
    {
        $exportData = [];
        $userId = (int) $this->user->id;

        // Only what this user wrote. `whereVisibleTo` is deliberately not applied:
        // an export is of *their* data, and a message they wrote in a channel they
        // have since left is still theirs. Earlier versions go with it when they
        // made the edit themselves; a moderator's edit is the moderator's record.
        Message::query()
            ->where('user_id', $userId)
            ->where('type', Message::TYPE_TEXT)
            ->with([
                'channel',
                'uploads',
                'revisions' => fn ($query) => $query->where('edited_by_id', $userId)->orderBy('id'),
            ])
            ->orderBy('id')
            ->each(function (Message $message) use (&$exportData) {
                $exportData[] = [
                    "chat/message-{$message->id}.json" => $this->encodeForExport([
                        'content'      => $message->content,
                        'channel'      => $message->channel?->name,
                        'channel_type' => $message->channel?->type,
                        'thread_id'    => $message->thread_id,
                        'created_at'   => $message->created_at?->toIso8601String(),
                        'edited_at'    => $message->edited_at?->toIso8601String(),
                        'attachments'  => $message->uploads
                            ->map(fn (Upload $upload) => Arr::only($upload->toArray(), ['file_name', 'mime_type', 'size']))
                            ->values()
                            ->all(),
                        'revisions'    => $message->revisions
                            ->map(fn (MessageRevision $revision) => [
                                'content'    => $revision->content,
                                'created_at' => $revision->created_at?->toIso8601String(),
                            ])
                            ->values()
                            ->all(),
                    ]),
                ];
            });

        // Channel membership is personal data in its own right: which rooms someone
        // was in says something about them even with no message attached.
        $memberships = ChannelUser::query()
            ->where('user_id', $userId)
            ->with('channel')
            ->get()
            ->map(fn (ChannelUser $membership) => [
                'channel'   => $membership->channel?->name,
                'joined_at' => $membership->joined_at?->toIso8601String(),
                'left_at'   => $membership->left_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        if ($memberships !== []) {
            $exportData[] = ['chat/channels.json' => $this->encodeForExport($memberships)];
        }

        $bookmarks = Bookmark::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (Bookmark $bookmark) => [
                'message_id' => $bookmark->message_id,
                'name'       => $bookmark->name,
                'remind_at'  => $bookmark->remind_at?->toIso8601String(),
                'created_at' => $bookmark->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        if ($bookmarks !== []) {
            $exportData[] = ['chat/bookmarks.json' => $this->encodeForExport($bookmarks)];
        }

        // Reports this user filed, in their own words. Reports filed about them,
        // and how a moderator resolved any of them, belong to the moderators.
        $flags = MessageFlag::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (MessageFlag $flag) => [
                'message_id' => $flag->message_id,
                'reason'     => $flag->reason,
                'detail'     => $flag->detail,
                'created_at' => $flag->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        if ($flags !== []) {
            $exportData[] = ['chat/reports.json' => $this->encodeForExport($flags)];
        }

        return $exportData === [] ? null : $exportData;
    }

    public function anonymize(): void
    {
        // Authorship goes, the conversation stays legible. Reactions and drafts are
        // removed outright — neither has any value once detached from its author.
        Message::query()
            ->where('user_id', $this->user->id)
            ->update(['user_id' => null]);

        // A report stays a moderation record, but no longer says who filed it or
        // in what words.
        MessageFlag::query()
            ->where('user_id', $this->user->id)
            ->update(['user_id' => null, 'detail' => null]);

        $this->detachFromOthersRecords();
        $this->purgeIncidentals();
    }

    public function delete(): void
    {
        // Attachments first: the rows are what point at the stored files, and a
        // cascade would take them away before anything could clean up.
        $this->deleteUploads();

        Message::query()->where('user_id', $this->user->id)->delete();
        MessageFlag::query()->where('user_id', $this->user->id)->delete();

        $this->detachFromOthersRecords();
        $this->purgeIncidentals();
    }

    /**
     * What stays on rows that are not this user's: their name in the stream's
     * narration ("X joined", "X added Y", the announcement of their discussion),
     * and their id as the editor of someone else's message or the resolver of a
     * report. The rows stay; the name and the id go.
     */
    protected function detachFromOthersRecords(): void
    {
        MessageRevision::query()
            ->where('edited_by_id', $this->user->id)
            ->update(['edited_by_id' => null]);

        MessageFlag::query()
            ->where('resolved_by_id', $this->user->id)
            ->update(['resolved_by_id' => null]);

        $this->scrubNarration();
    }

    /**
     * System and bot messages snapshot a display name into `system_data`, not an
     * id — the narration has to read the same after a rename. So the name is
     * what is matched: the username and the display name as they are now, before
     * flarum/gdpr renames the account (its own type runs last).
     *
     * The query narrows by a fragment of the stored JSON; the comparison that
     * decides is the exact one in PHP, so a loose fragment only costs time.
     */
    protected function scrubNarration(): void
    {
        $names = array_values(array_unique(array_filter([
            (string) $this->user->username,
            (string) $this->user->display_name,
        ], fn (string $name) => $name !== '')));

        if ($names === []) {
            return;
        }

        $replacement = $this->anonymousName();
        $userId = (int) $this->user->id;
        $fragments = array_map(fn (string $name) => $this->likeFragment($name), $names);

        $query = Message::query()
            ->whereIn('type', [Message::TYPE_SYSTEM, Message::TYPE_BOT])
            ->whereNotNull('system_data');

        if (! in_array(null, $fragments, true)) {
            $query->where(function ($query) use ($fragments, $userId) {
                foreach ($fragments as $fragment) {
                    $query->orWhere('system_data', 'like', '%'.$fragment.'%');
                }

                $query->orWhere('system_data', 'like', '%"userId":'.$userId.'%');
            });
        }

        $query->chunkById(200, function ($messages) use ($names, $replacement, $userId) {
            foreach ($messages as $message) {
                $data = $message->system_data;

                if (! is_array($data)) {
                    continue;
                }

                $changed = false;

                foreach (['username', 'actor'] as $key) {
                    if (isset($data[$key]) && in_array($data[$key], $names, true)) {
                        $data[$key] = $replacement;
                        $changed = true;
                    }
                }

                if (isset($data['userId']) && (int) $data['userId'] === $userId) {
                    $data['userId'] = null;
                    $data['username'] = $replacement;
                    $changed = true;
                }

                if ($changed) {
                    $message->system_data = $data;
                    $message->save();
                }
            }
        });
    }

    /**
     * The longest run of the name, as it sits inside stored JSON, that contains
     * no backslash: JSON escapes non-ASCII and slashes, and a backslash is an
     * escape character in some databases' LIKE. Null when no run is long enough
     * to narrow anything, in which case every narration row is checked.
     */
    protected function likeFragment(string $name): ?string
    {
        $runs = explode('\\', substr((string) json_encode($name), 1, -1));
        usort($runs, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return strlen($runs[0]) >= 3 ? $runs[0] : null;
    }

    /**
     * The name flarum/gdpr gives the account, so the narration matches the
     * profile it now links to.
     */
    protected function anonymousName(): string
    {
        $prefix = (string) ($this->settings->get('flarum-gdpr.default-anonymous-username') ?: 'Anonymous');

        return $this->erasureRequest !== null ? $prefix.$this->erasureRequest->id : $prefix;
    }

    /**
     * Reactions, drafts, bookmarks, memberships, read state and uploads:
     * everything that is only ever about this one user.
     */
    protected function purgeIncidentals(): void
    {
        // Channels this account owns go to their oldest moderator first, the
        // same succession as leaving them, before the memberships it would
        // read are deleted below. Resolved here for the reason deleteUploads
        // gives: the gdpr base type owns the constructor.
        resolve(OwnershipSuccession::class)->handOverAll($this->user);

        $this->deleteUploads();

        MessageReaction::query()->where('user_id', $this->user->id)->delete();
        Draft::query()->where('user_id', $this->user->id)->delete();
        Bookmark::query()->where('user_id', $this->user->id)->delete();
        ThreadUser::query()->where('user_id', $this->user->id)->delete();
        ChannelUser::query()->where('user_id', $this->user->id)->delete();
        ChannelInvite::query()->where('user_id', $this->user->id)->delete();

        // Pending ownership handovers either side of: an offer to someone who
        // is no longer there, or from them, has nobody left to answer it.
        ChannelTransfer::query()
            ->where('from_user_id', $this->user->id)
            ->orWhere('to_user_id', $this->user->id)
            ->delete();
    }

    protected function deleteUploads(): void
    {
        // Resolved here rather than injected: the gdpr base type owns the
        // constructor. The resolver knows whether a file is on the chat's disks
        // or on fof/upload, which the row alone cannot reach.
        $storage = resolve(UploadStorage::class);

        Upload::query()
            ->where('user_id', $this->user->id)
            ->each(function (Upload $upload) use ($storage) {
                // Best effort on the stored file, logged by the resolver when it
                // fails. A missing or unreadable file must not stop the erasure:
                // removing the row is what matters legally, and an exception here
                // would abort the whole request.
                $storage->delete($upload);

                $upload->delete();
            });
    }
}
