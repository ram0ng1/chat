<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Console;

use Carbon\Carbon;
use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory;
use Symfony\Component\Console\Input\InputOption;
use Ramon\Chat\Channel;
use Ramon\Chat\Message;
use Ramon\Chat\Service\UploadPrivacy;
use Ramon\Chat\Upload;

/**
 * Enforces message retention and sweeps orphaned uploads.
 *
 * Scheduled nightly (see extend.php). Destructive by design — retention means
 * deletion — so it supports --dry-run and reports counts before acting.
 */
class PruneChatCommand extends AbstractCommand
{
    /**
     * Orphaned uploads younger than this are left alone: a composer session may
     * still be open with the attachment pending.
     */
    protected const ORPHAN_GRACE_HOURS = 24;

    protected function configure(): void
    {
        $this
            ->setName('chat:prune')
            ->setDescription('Delete chat messages past their retention window and sweep orphaned uploads.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting it.');
    }

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Factory $filesystem
    ) {
        parent::__construct();
    }

    protected function fire(): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');

        if ($dryRun) {
            $this->info('Dry run — nothing will be deleted.');
        }

        $channelDays = (int) $this->settings->get('ramon-chat.channel_retention_days', 90);
        $dmDays = (int) $this->settings->get('ramon-chat.dm_retention_days', 0);

        $touched = [];

        $channelDeleted = $this->pruneByType(Channel::TYPE_CATEGORY, $channelDays, $dryRun, $touched);
        $dmDeleted = $this->pruneByType(Channel::TYPE_DIRECT, $dmDays, $dryRun, $touched);

        $uploadsDeleted = $this->pruneOrphanedUploads($dryRun);

        // Counters are derived from the messages table, so every channel that lost
        // messages needs rebuilding — otherwise its list entry keeps showing a
        // last message that no longer exists.
        if (! $dryRun && $touched !== []) {
            Channel::query()
                ->whereIn('id', array_keys($touched))
                ->each(fn (Channel $channel) => $channel->refreshMetadata()->save());
        }

        $this->info(sprintf(
            '%s %d channel message(s), %d direct message(s), %d orphaned upload(s) across %d channel(s).',
            $dryRun ? 'Would delete' : 'Deleted',
            $channelDeleted,
            $dmDeleted,
            $uploadsDeleted,
            count($touched)
        ));

        return 0;
    }

    /**
     * @param  array<int, true>  $touched  Channel ids that lost messages.
     */
    protected function pruneByType(string $type, int $days, bool $dryRun, array &$touched): int
    {
        // 0 means "keep forever", which is the documented default for DMs.
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);

        $channelIds = Channel::query()
            ->where('type', $type)
            ->pluck('id');

        if ($channelIds->isEmpty()) {
            return 0;
        }

        $deleted = 0;

        Message::query()
            ->whereIn('channel_id', $channelIds)
            ->where('created_at', '<', $cutoff)
            ->select(['id', 'channel_id'])
            // Chunk by id so deleting rows cannot shift the pages being iterated.
            ->chunkById(500, function ($messages) use (&$deleted, &$touched, $dryRun) {
                $ids = $messages->pluck('id')->all();

                foreach ($messages as $message) {
                    $touched[$message->channel_id] = true;
                }

                if (! $dryRun) {
                    // Attachment files first: the DB rows cascade on delete, so
                    // after the message is gone there is nothing left to locate
                    // the files by.
                    $this->deleteFilesFor($ids);

                    Message::query()->whereIn('id', $ids)->delete();
                }

                $deleted += count($ids);
            });

        return $deleted;
    }

    protected function pruneOrphanedUploads(bool $dryRun): int
    {
        $cutoff = Carbon::now()->subHours(self::ORPHAN_GRACE_HOURS);

        $deleted = 0;

        Upload::query()
            ->whereNull('message_id')
            ->where('created_at', '<', $cutoff)
            ->chunkById(500, function ($uploads) use (&$deleted, $dryRun) {
                if (! $dryRun) {
                    foreach ($uploads as $upload) {
                        try {
                            $this->filesystem->disk(UploadPrivacy::diskFor((bool) $upload->is_private))->delete($upload->path);
                        } catch (\Throwable $e) {
                            // A missing file is not a reason to abort the sweep;
                            // the row still needs removing either way.
                            $this->error('Could not delete '.$upload->path.': '.$e->getMessage());
                        }
                    }

                    Upload::query()->whereIn('id', $uploads->pluck('id')->all())->delete();
                }

                $deleted += $uploads->count();
            });

        return $deleted;
    }

    /**
     * @param  int[]  $messageIds
     */
    protected function deleteFilesFor(array $messageIds): void
    {
        Upload::query()
            ->whereIn('message_id', $messageIds)
            ->each(function (Upload $upload) {
                try {
                    $this->filesystem->disk($upload->diskName())->delete($upload->path);
                } catch (\Throwable $e) {
                    $this->error('Could not delete '.$upload->path.': '.$e->getMessage());
                }
            });
    }
}
