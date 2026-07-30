<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\ConnectionInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\ChannelWasArchived;
use Ramon\Chat\Message;

/**
 * Archives a channel by copying its transcript into a discussion.
 *
 * Archiving is non-destructive: the channel stays readable and its messages are
 * left in place. What changes is that the conversation now also exists as a
 * durable, searchable discussion — which is the point of the feature.
 */
class ChannelArchiver
{
    /**
     * Messages per post. A channel with tens of thousands of messages cannot
     * become one post, so the transcript is chunked across replies.
     */
    protected const MESSAGES_PER_POST = 200;

    public function __construct(
        protected ConnectionInterface $db,
        protected Events $events,
        protected Translator $translator,
        protected TranscriptRenderer $transcript
    ) {
    }

    /**
     * @param  int|null  $discussionId  Append to this discussion; null creates one.
     *
     * @throws ValidationException
     */
    public function archive(
        Channel $channel,
        User $actor,
        ?int $discussionId = null,
        ?string $title = null
    ): Discussion {
        $existing = $discussionId !== null
            ? Discussion::query()->whereVisibleTo($actor)->find($discussionId)
            : null;

        if ($discussionId !== null && $existing === null) {
            throw new ValidationException([
                'discussionId' => $this->translator->trans('ramon-chat.api.archive_discussion_not_found'),
            ]);
        }

        $title = trim((string) ($title ?? $channel->name ?? ''));

        if ($existing === null && $title === '') {
            throw new ValidationException([
                'title' => $this->translator->trans('ramon-chat.api.archive_title_required'),
            ]);
        }

        return $this->db->transaction(function () use ($channel, $actor, $existing, $title) {
            $discussion = $existing ?? Discussion::start($title, $actor);

            if ($existing === null) {
                // A tag-bound channel archives into its own category so the
                // transcript lands where the conversation belonged.
                $discussion->save();

                if ($channel->tag_id !== null && method_exists($discussion, 'tags')) {
                    $discussion->tags()->sync([$channel->tag_id]);
                }
            }

            $chunks = $this->chunks($channel);

            if ($chunks === []) {
                // Still record an archive marker: an empty channel that was
                // archived should not silently look un-archived.
                $chunks = [$this->translator->trans('ramon-chat.api.archive_empty')];
            }

            // Flarum 2 dropped CommentPost::reply(); posts are built by hand.
            // Post::boot() assigns `type` and the per-discussion `number`, so
            // only the content and ownership need setting here.
            $isFirst = $existing === null;

            foreach ($chunks as $body) {
                $post = new CommentPost();
                $post->discussion_id = $discussion->id;
                $post->user_id = $actor->id;
                $post->created_at = Carbon::now();
                $post->setContentAttribute($body, $actor);
                $post->save();

                if ($isFirst) {
                    $discussion->setFirstPost($post);
                    $isFirst = false;
                }

                $discussion->refreshCommentCount();
                $discussion->refreshLastPost();
                $discussion->refreshParticipantCount();
                $discussion->save();
            }

            $channel->status = Channel::STATUS_ARCHIVED;
            $channel->archived_discussion_id = $discussion->id;
            $channel->archived_at = Carbon::now();
            $channel->archived_by_id = $actor->id;
            $channel->save();

            $this->events->dispatch(new ChannelWasArchived($channel, $discussion, $actor));

            return $discussion;
        });
    }

    /**
     * @return string[] Rendered transcript bodies, in chronological order.
     */
    protected function chunks(Channel $channel): array
    {
        $bodies = [];

        Message::query()
            ->where('channel_id', $channel->id)
            ->whereNull('deleted_at')
            ->where('type', Message::TYPE_TEXT)
            ->with(['user', 'uploads', 'webhook'])
            ->orderBy('id')
            ->chunk(self::MESSAGES_PER_POST, function ($messages) use (&$bodies, $channel) {
                $rendered = $this->transcript->render($messages, $channel);

                if ($rendered !== '') {
                    $bodies[] = $rendered;
                }
            });

        return $bodies;
    }
}
