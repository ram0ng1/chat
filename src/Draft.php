<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An unsent composer draft, scoped to a channel or to a thread within it.
 *
 * Drafts are server-side rather than localStorage-only so they survive across
 * devices, which is the behaviour Discourse users expect.
 *
 * @property int $id
 * @property int $user_id
 * @property int $channel_id
 * @property int|null $thread_id
 * @property string|null $content
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 * @property-read Channel|null $channel
 * @property-read Thread|null $thread
 */
class Draft extends AbstractModel
{
    protected $table = 'chat_drafts';

    public $timestamps = true;


    protected $casts = [
        'user_id'    => 'integer',
        'channel_id' => 'integer',
        'thread_id'  => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    /**
     * Upserts the draft for a scope. MySQL treats NULL as distinct in unique
     * indexes, so channel-level drafts (thread_id IS NULL) are de-duplicated
     * here rather than by the table constraint.
     */
    public static function store(User $user, Channel $channel, ?Thread $thread, ?string $content): ?static
    {
        $query = static::query()
            ->where('user_id', $user->id)
            ->where('channel_id', $channel->id)
            ->when(
                $thread === null,
                fn ($q) => $q->whereNull('thread_id'),
                fn ($q) => $q->where('thread_id', $thread->id)
            );

        $content = $content === null ? null : trim($content);

        if ($content === null || $content === '') {
            $query->delete();

            return null;
        }

        /** @var static|null $draft */
        $draft = $query->first();

        if ($draft === null) {
            $draft = new static();
            $draft->user_id = $user->id;
            $draft->channel_id = $channel->id;
            $draft->thread_id = $thread?->id;
        }

        $draft->content = $content;
        $draft->save();

        return $draft;
    }
}
