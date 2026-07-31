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
 * A report filed against a chat message.
 *
 * @property int $id
 * @property int $message_id
 * @property int|null $user_id
 * @property string $reason
 * @property string|null $detail
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_id
 * @property Carbon|null $created_at
 * @property-read Message|null $message
 * @property-read User|null $user
 * @property-read User|null $resolvedBy
 */
class MessageFlag extends AbstractModel
{
    /**
     * The reasons a report may carry.
     *
     * A closed set, validated server-side: a free-text-only report is a worse
     * signal to triage from, and an open field is one more place for someone to
     * write something a moderator then has to read. `other` exists for what the
     * list does not cover, and is the one reason that requires the detail field.
     */
    public const REASONS = ['spam', 'inappropriate', 'harassment', 'off_topic', 'other'];

    protected $table = 'chat_message_flags';

    public $timestamps = false;

    protected $casts = [
        'message_id'     => 'integer',
        'user_id'        => 'integer',
        'resolved_by_id' => 'integer',
        'created_at'     => 'datetime',
        'resolved_at'    => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function resolve(User $actor): static
    {
        $this->resolved_at = Carbon::now();
        $this->resolved_by_id = $actor->id;

        return $this;
    }

    public static function isValidReason(string $reason): bool
    {
        return in_array($reason, self::REASONS, true);
    }
}
