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
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Post\CommentPost;
use Flarum\Post\Exception\FloodingException;
use Flarum\Post\Post;
use Flarum\Post\PostCreationThrottler;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\ConnectionInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\ChannelStatusChanged;
use Ramon\Chat\Event\ChannelWasArchived;
use Ramon\Chat\Message;

/**
 * Archives a channel by copying its transcript into a discussion.
 *
 * Archiving is non-destructive: the channel stays readable and its messages are
 * left in place. What changes is that the conversation now also exists as a
 * durable, searchable discussion — which is the point of the feature.
 *
 * ## Publishing is posting
 *
 * The transcript lands in the forum under the archiving user's name, so it is held
 * to what that user could post by hand: replying needs `reply` on the discussion
 * (which is where a lock is enforced), starting one needs `startDiscussion` in the
 * channel's category, the post flood window applies, and on a forum with
 * flarum/approval the content must not need approval — the transcript is written
 * here, not through the post endpoints that would hold it for review.
 *
 * It must also not widen the audience. A private channel is invitation-only and no
 * discussion is, so only an administrator may publish one. A channel on a
 * restricted category may only go into a discussion that carries that category.
 *
 * Posts are built here rather than through core's PostResource: a large channel
 * becomes several posts at once, which the API's own flood control would refuse
 * after the first. The work is bounded instead — see MAX_MESSAGES. Administrators
 * are exempt from all of this: they are the forum's final say, and archiving a
 * large room is a job for them.
 */
class ChannelArchiver
{
    /**
     * Messages per post. A channel with tens of thousands of messages cannot
     * become one post, so the transcript is chunked across replies.
     */
    protected const MESSAGES_PER_POST = 200;

    /**
     * The most messages a non-administrator may archive. The transcript is
     * rendered inside the request, so this keeps one click from being minutes of
     * work; a larger room is an administrator's to archive.
     */
    public const MAX_MESSAGES = 500;

    public function __construct(
        protected ConnectionInterface $db,
        protected Events $events,
        protected Translator $translator,
        protected TranscriptRenderer $transcript,
        protected ExtensionManager $extensions
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

        if (! $actor->isAdmin()) {
            $this->assertMayPublish($channel, $actor, $existing);
        }

        return $this->db->transaction(function () use ($channel, $actor, $existing, $title) {
            $discussion = $existing ?? Discussion::start($title, $actor);

            if ($existing === null) {
                // A tag-bound channel archives into its own category so the
                // transcript lands where the conversation belonged.
                $discussion->save();

                // Not `method_exists($discussion, 'tags')`: flarum/tags registers
                // the relation through Extend\Model rather than declaring a method,
                // so that test is false even when tags is enabled — which quietly
                // filed every archive under no category at all.
                if ($channel->tag_id !== null && $this->extensions->isEnabled('flarum-tags')) {
                    $discussion->tags()->sync([$channel->tag_id]);
                }

                // Asked of the saved, tagged discussion because that is the shape
                // the ability takes; throwing here rolls the discussion back.
                if (! $actor->isAdmin()) {
                    $this->assertWithoutApproval($actor, 'startWithoutApproval', $discussion);
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
     * Takes a channel out of the archive, closed.
     *
     * Closed rather than open: whoever undoes an archive has not necessarily
     * decided the room should take messages again, and reopening is one more
     * deliberate click from there.
     *
     * The archive stamps are cleared, the discussion link included. The
     * transcript stays in the forum as an ordinary discussion; what goes is the
     * channel's claim to be archived, which the archive action keys on, and a
     * channel archived again later gets a link to its new transcript.
     */
    public function unarchive(Channel $channel, User $actor): Channel
    {
        $previous = $channel->status;

        $channel->status = Channel::STATUS_CLOSED;
        $channel->archived_discussion_id = null;
        $channel->archived_at = null;
        $channel->archived_by_id = null;
        $channel->save();

        $this->events->dispatch(new ChannelStatusChanged($channel, $previous, $actor));

        return $channel;
    }

    /**
     * The checks the class docblock lists, for a non-administrator.
     *
     * @throws ValidationException
     */
    protected function assertMayPublish(Channel $channel, User $actor, ?Discussion $existing): void
    {
        if ($channel->isPrivate()) {
            throw new ValidationException([
                'channel' => $this->translator->trans('ramon-chat.api.archive_private_channel'),
            ]);
        }

        $tag = $channel->tag_id !== null && $this->extensions->isEnabled('flarum-tags')
            ? \Flarum\Tags\Tag::query()->find($channel->tag_id)
            : null;

        if ($existing !== null) {
            $actor->assertCan('reply', $existing);

            $restricted = $this->restrictedTagIds($tag);

            if ($restricted !== []) {
                // @phpstan-ignore method.notFound (flarum/tags relation)
                $carried = $existing->tags()->pluck('tags.id')->map(fn ($id) => (int) $id)->all();

                if (array_diff($restricted, $carried) !== []) {
                    throw new ValidationException([
                        'discussionId' => $this->translator->trans('ramon-chat.api.archive_discussion_wider'),
                    ]);
                }
            }

            $this->assertWithoutApproval($actor, 'replyWithoutApproval', $existing);
        } elseif ($tag !== null) {
            $actor->assertCan('startDiscussion', $tag);
        } else {
            $actor->assertCan('startDiscussion');
        }

        if (! $actor->can('postWithoutThrottle')
            && Post::query()
                ->where('user_id', $actor->id)
                ->where('created_at', '>=', Carbon::now()->subSeconds(PostCreationThrottler::$timeout))
                ->exists()) {
            throw new FloodingException();
        }

        $count = Message::query()
            ->where('channel_id', $channel->id)
            ->whereNull('deleted_at')
            ->where('type', Message::TYPE_TEXT)
            ->count();

        if ($count > self::MAX_MESSAGES) {
            throw new ValidationException([
                'channel' => $this->translator->trans('ramon-chat.api.archive_too_large', ['max' => self::MAX_MESSAGES]),
            ]);
        }
    }

    /**
     * flarum/approval holds back content from accounts it does not trust yet, but
     * only content made through the post endpoints. What it would hold back is
     * refused here instead of being published unreviewed.
     *
     * @throws ValidationException
     */
    protected function assertWithoutApproval(User $actor, string $ability, Discussion $discussion): void
    {
        if (! $this->extensions->isEnabled('flarum-approval')) {
            return;
        }

        if (! $actor->can($ability, $discussion)) {
            throw new ValidationException([
                'discussionId' => $this->translator->trans('ramon-chat.api.archive_needs_approval'),
            ]);
        }
    }

    /**
     * The channel's category and its parent, where either is restricted: what a
     * reader of the channel is known to hold and a forum visitor is not.
     *
     * @return int[]
     */
    protected function restrictedTagIds(?object $tag): array
    {
        if ($tag === null) {
            return [];
        }

        $ids = $tag->is_restricted ? [(int) $tag->id] : [];

        $parent = $tag->parent_id !== null ? \Flarum\Tags\Tag::query()->find($tag->parent_id) : null;

        if ($parent !== null && $parent->is_restricted) {
            $ids[] = (int) $parent->id;
        }

        return $ids;
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
