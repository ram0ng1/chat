<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage\Job;

use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Ramon\Chat\Channel;

/**
 * Fans a tag that became restricted out to the channels it now hides.
 *
 * A channel bound to the tag, or to any tag below it, may still have files on
 * the public disk from when the category was open. Each such channel gets a
 * PrivatizeChannelUploads of its own, so one busy room's history does not hold
 * up the rest and a retry only repeats the room that failed.
 *
 * Only ever queued by Listener\KeepUploadsPrivate when flarum/tags is enabled,
 * which is why the `tags` table can be read here.
 */
class PrivatizeTagChannels extends AbstractJob
{
    /**
     * Same bound as UploadPrivacy's walk up the hierarchy, for the same reason.
     */
    protected const MAX_DEPTH = 8;

    public int $tries = 3;

    public function __construct(
        protected int $tagId
    ) {
        parent::__construct();
    }

    public function __invoke(Queue $queue): void
    {
        $tagIds = $this->withDescendants($this->tagId);

        Channel::query()
            ->whereIn('tag_id', $tagIds)
            ->select('id')
            ->chunkById(200, function (Collection $channels) use ($queue) {
                foreach ($channels as $channel) {
                    $queue->push(new PrivatizeChannelUploads((int) $channel->id));
                }
            });
    }

    /**
     * @return int[]
     */
    protected function withDescendants(int $tagId): array
    {
        $db = Channel::query()->getConnection();
        $all = [$tagId];
        $level = [$tagId];

        for ($depth = 0; $depth < self::MAX_DEPTH && $level !== []; $depth++) {
            $level = array_values(array_diff(
                $this->childrenOf($db, $level),
                $all
            ));
            $all = array_merge($all, $level);
        }

        return $all;
    }

    /**
     * @param  int[]  $parentIds
     * @return int[]
     */
    protected function childrenOf(ConnectionInterface $db, array $parentIds): array
    {
        return $db->table('tags')
            ->whereIn('parent_id', $parentIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
