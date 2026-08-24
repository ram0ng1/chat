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
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * @property-read ThreadUser|null $actorMembership
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

    /**
     * Memberships already looked up on this instance, by user id. Per-instance
     * and not static: a static would survive between actors in a queue worker.
     *
     * @var array<int, ThreadUser|null>
     */
    protected array $membershipCache = [];


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

    /**
     * @return HasMany<ThreadUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ThreadUser::class, 'thread_id');
    }

    /**
     * The membership of whoever is asking, for the endpoints that eager-load it.
     *
     * Deliberately unconstrained here: the constraint is applied at eager-load
     * time (`eagerLoadWhere`), because only the endpoint knows the actor. Reading
     * it directly off a model would give you an arbitrary member's row, which is
     * why nothing but `membershipFor()` — which checks whose row it got — touches
     * it.
     *
     * The same shape Channel uses, and for the same reason: four fields on every
     * thread read this membership, and a page of threads was asking the database
     * once per row.
     */
    /**
     * @return HasOne<ThreadUser, $this>
     */
    public function actorMembership(): HasOne
    {
        return $this->hasOne(ThreadUser::class, 'thread_id');
    }

    /**
     * Memoised on the instance, including the misses.
     *
     * `array_key_exists` rather than `isset`, so a cached "not a member" is not
     * re-queried on every ask. Model instances do not outlive the request, so the
     * cache cannot leak between actors.
     */
    public function membershipFor(?User $user): ?ThreadUser
    {
        if ($user === null || ! $user->exists) {
            return null;
        }

        $id = (int) $user->id;

        if (array_key_exists($id, $this->membershipCache)) {
            return $this->membershipCache[$id];
        }

        // The eager-loaded row, but only once it has proved it belongs to the user
        // being asked about. A null there is ambiguous — it could mean "this actor
        // is not a member" or "the relation was constrained to somebody else" — so
        // it falls through to the query rather than answering wrongly.
        if ($this->relationLoaded('actorMembership')) {
            $loaded = $this->getRelation('actorMembership');

            if ($loaded instanceof ThreadUser && (int) $loaded->user_id === $id) {
                return $this->membershipCache[$id] = $loaded;
            }
        }

        return $this->membershipCache[$id] = $this->memberships()
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Drops a memoised membership after joining or leaving — the mutation happens
     * mid-request and the next read must see it.
     *
     * Passing null clears the lot, for a bulk change.
     */
    public function forgetMembership(?User $user = null): void
    {
        if ($user === null) {
            $this->membershipCache = [];

            return;
        }

        unset($this->membershipCache[(int) $user->id]);
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
