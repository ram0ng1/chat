<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Gdpr;

use Flarum\Gdpr\Data\Type;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Arr;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Draft;
use Ramon\Chat\Message;
use Ramon\Chat\MessageReaction;
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
     * No IP addresses or emails are stored on a chat row, so nothing here needs
     * redacting when a payload is serialised for a non-PII context.
     */
    public static function piiFields(): array
    {
        return [];
    }

    public function export(): ?array
    {
        $exportData = [];

        // Only what this user wrote. `whereVisibleTo` is deliberately not applied:
        // an export is of *their* data, and a message they wrote in a channel they
        // have since left is still theirs.
        Message::query()
            ->where('user_id', $this->user->id)
            ->where('type', Message::TYPE_TEXT)
            ->with(['channel', 'uploads'])
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
                    ]),
                ];
            });

        // Channel membership is personal data in its own right: which rooms someone
        // was in says something about them even with no message attached.
        $memberships = ChannelUser::query()
            ->where('user_id', $this->user->id)
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

        return $exportData === [] ? null : $exportData;
    }

    public function anonymize(): void
    {
        // Authorship goes, the conversation stays legible. Reactions and drafts are
        // removed outright — neither has any value once detached from its author.
        Message::query()
            ->where('user_id', $this->user->id)
            ->update(['user_id' => null]);

        $this->purgeIncidentals();
    }

    public function delete(): void
    {
        // Attachments first: the rows are what point at the stored files, and a
        // cascade would take them away before anything could clean up.
        $this->deleteUploads();

        Message::query()->where('user_id', $this->user->id)->delete();

        $this->purgeIncidentals();
    }

    /**
     * Reactions, drafts, memberships and uploads: everything that is only ever
     * about this one user.
     */
    protected function purgeIncidentals(): void
    {
        $this->deleteUploads();

        MessageReaction::query()->where('user_id', $this->user->id)->delete();
        Draft::query()->where('user_id', $this->user->id)->delete();
        ChannelUser::query()->where('user_id', $this->user->id)->delete();
    }

    protected function deleteUploads(): void
    {
        // The same disks PruneChatCommand writes to; Upload holds only the path
        // and which of the two disks it is on, not a handle to its own storage.
        $filesystem = resolve(Factory::class);

        Upload::query()
            ->where('user_id', $this->user->id)
            ->each(function (Upload $upload) use ($filesystem) {
                // Best effort on the stored file. A missing or unreadable file must
                // not stop the erasure — removing the row is what matters legally,
                // and an exception here would abort the whole request.
                try {
                    $filesystem->disk($upload->diskName())->delete($upload->path);
                } catch (\Throwable $e) {
                    // Deliberately swallowed; see above.
                }

                $upload->delete();
            });
    }
}
