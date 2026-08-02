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
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\Foundation\EventGeneratorTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A threaded sub-conversation hanging off one channel message.
 *
 * @property int $id
 * @property int $channel_id
 * @property int|null $original_message_id
 * @property string|null $title
 * @property int|null $creator_id
 * @property string $status
 * @property int $replies_count
 * @property int|null $last_message_id
 * @property Carbon|null $last_message_at
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Channel|null $channel
 * @property-read Message|null $originalMessage
 * @property-read Message|null $lastMessage
 * @property-read User|null $creator
 * @property-read Collection<int, Message> $messages
 */
class Thread extends AbstractModel
{
    use EventGeneratorTrait;
    use ScopeVisibilityTrait;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'chat_threads';

    public $timestamps = true;


    protected $casts = [
        'channel_id'          => 'integer',
        'original_message_id' => 'integer',
        'creator_id'          => 'integer',
        'replies_count'       => 'integer',
        'last_message_id'     => 'integer',
        'last_message_at'     => 'datetime',
        'deleted_at'          => 'datetime',
    ];

    public static function build(Channel $channel, Message $originalMessage, ?User $creator = null): static
    {
        $thread = new static();

        $thread->channel_id = $channel->id;
        $thread->original_message_id = $originalMessage->id;
        $thread->creator_id = $creator?->id ?? $originalMessage->user_id;
        $thread->status = self::STATUS_OPEN;
        $thread->replies_count = 0;

        return $thread;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function acceptsMessages(): bool
    {
        return $this->isOpen() && ! $this->isDeleted();
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function originalMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'original_message_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ThreadUser::class, 'thread_id');
    }

    public function membershipFor(?User $user): ?ThreadUser
    {
        if ($user === null || ! $user->exists) {
            return null;
        }

        return $this->memberships()->where('user_id', $user->id)->first();
    }

    /**
     * Records one freshly-posted reply.
     *
     * The append path deliberately does not call refreshMetadata(): the new message
     * is by definition the newest, and the count can only have gone up by one, so
     * recounting asks the database to rediscover what the caller already knows. That
     * cost is not constant — the COUNT scans every message in the thread — so the
     * busiest threads were the slowest to reply in, which is exactly backwards.
     *
     * The channel counters next to this in the dispatcher have always been
     * incremental; this makes the thread behave the same way.
     */
    public function noteReply(Message $message): static
    {
        // The root is not a reply. It cannot normally arrive here — the dispatcher
        // only ever passes the message it just created — but guarding keeps the
        // invariant local to the method that maintains the counter.
        if ($this->original_message_id !== null && (int) $message->id === (int) $this->original_message_id) {
            return $this;
        }

        $this->replies_count = (int) $this->replies_count + 1;
        $this->last_message_id = $message->id;
        $this->last_message_at = $message->created_at;

        return $this;
    }

    /**
     * Recomputes the counters from the messages themselves.
     *
     * Needed wherever the change is not a simple append — deleting, restoring or
     * moving messages can invalidate both the count and the last-message pointer,
     * and neither can be derived from the event alone. New replies use noteReply().
     *
     * Replies exclude the root message, which is why this is not simply a count
     * of the messages relation.
     */
    public function refreshMetadata(): static
    {
        $replies = $this->messages()
            ->whereNull('deleted_at')
            ->when(
                $this->original_message_id !== null,
                fn ($q) => $q->whereKeyNot($this->original_message_id)
            );

        $last = (clone $replies)->orderByDesc('id')->first();

        $this->replies_count = $replies->count();
        $this->last_message_id = $last?->id;
        $this->last_message_at = $last?->created_at;

        return $this;
    }
}
