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
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\EventGeneratorTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $type
 * @property string|null $name
 * @property string|null $slug
 * @property string|null $description
 * @property string|null $emoji
 * @property string|null $image_path
 * @property int|null $tag_id
 * @property string $status
 * @property bool $is_private
 * @property string $post_permission
 * @property bool $threading_enabled
 * @property int $slow_mode_seconds
 * @property int|null $max_message_length
 * @property bool $auto_join
 * @property bool $auto_join_on_reply
 * @property bool $post_discussions
 * @property bool $allow_channel_wide_mentions
 * @property int|null $creator_id
 * @property int $messages_count
 * @property int $user_count
 * @property int|null $last_message_id
 * @property Carbon|null $last_message_at
 * @property int|null $archived_discussion_id
 * @property Carbon|null $archived_at
 * @property int|null $archived_by_id
 * @property Carbon|null $deleted_at
 * @property int|null $deleted_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $creator
 * @property-read Collection<int, Message> $messages
 * @property-read Collection<int, Thread> $threads
 * @property-read Collection<int, User> $participants
 * @property-read Message|null $lastMessage
 */
class Channel extends AbstractModel
{
    use EventGeneratorTrait;
    use ScopeVisibilityTrait;

    /**
     * A channel bound to a tag (or to the whole forum when tags is disabled).
     * Visibility is inherited from the tag, so category permissions govern chat
     * access without a second permission surface to maintain.
     */
    public const TYPE_CATEGORY = 'category';

    /**
     * A 1:1 or group conversation with an explicit participant list.
     */
    public const TYPE_DIRECT = 'direct';

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'chat_channels';

    public $timestamps = true;


    protected $casts = [
        'tag_id'                      => 'integer',
        'creator_id'                  => 'integer',
        'messages_count'              => 'integer',
        'user_count'                  => 'integer',
        'last_message_id'             => 'integer',
        'archived_discussion_id'      => 'integer',
        'archived_by_id'              => 'integer',
        'deleted_by_id'               => 'integer',
        'slow_mode_seconds'           => 'integer',
        'max_message_length'          => 'integer',
        'is_private'                  => 'boolean',
        'threading_enabled'           => 'boolean',
        'auto_join'                   => 'boolean',
        'auto_join_on_reply'          => 'boolean',
        'post_discussions'            => 'boolean',
        'allow_channel_wide_mentions' => 'boolean',
        'last_message_at'             => 'datetime',
        'archived_at'                 => 'datetime',
        'deleted_at'                  => 'datetime',
    ];

    public static function build(
        string $type,
        ?string $name = null,
        ?string $description = null,
        ?int $tagId = null,
        ?User $creator = null
    ): static {
        $channel = new static();

        $channel->type = $type;
        $channel->name = $name;
        $channel->description = $description;
        $channel->tag_id = $tagId;
        $channel->creator_id = $creator?->id;
        $channel->status = self::STATUS_OPEN;

        if ($type === self::TYPE_CATEGORY && $name !== null) {
            $channel->slug = $channel->generateSlug($name);
        }

        return $channel;
    }

    /**
     * Slugs are only meaningful for category channels — direct channels are
     * addressed by id because their name is derived from the participant list.
     */
    public function generateSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'channel';
        $slug = $base;
        $suffix = 1;

        while (
            static::query()
                ->where('slug', $slug)
                ->when($this->exists, fn (Builder $q) => $q->whereKeyNot($this->id))
                ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }

    /**
     * Invitation-only: visible to members alone, so it is absent from Browse and
     * cannot be joined by someone who was not added.
     *
     * A direct channel is never marked private — it is private by construction, and
     * treating it as both would make the flag mean two different things.
     */
    /** Only holders of `ramon-chat.moderate` may post. */
    public const POST_ALL = 'all';
    public const POST_MODERATORS = 'moderators';

    /**
     * Whether posting is narrowed to moderators — an announcement channel.
     *
     * Never true for a direct channel: a conversation only one side may write to is
     * not a conversation, and the setting is not offered for them.
     */
    public function restrictsPostingToModerators(): bool
    {
        return $this->isCategory() && $this->post_permission === self::POST_MODERATORS;
    }

    public function isPrivate(): bool
    {
        return $this->isCategory() && (bool) $this->is_private;
    }

    public function isCategory(): bool
    {
        return $this->type === self::TYPE_CATEGORY;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * Whether new messages may be posted. Closed and archived channels stay
     * readable but frozen, which is what makes archiving non-destructive.
     */
    public function acceptsMessages(): bool
    {
        return $this->isOpen() && ! $this->isDeleted();
    }

    /**
     * How long a message may be here, given the forum-wide default.
     *
     * Null and zero both mean "follow the forum": a channel that never had an
     * opinion and a channel whose own limit was cleared must behave the same,
     * and zero as a literal cap would forbid every message.
     */
    public function maxMessageLength(int $forumDefault): int
    {
        return $this->max_message_length ?: $forumDefault;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'channel_id');
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class, 'channel_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class, 'channel_id');
    }

    /**
     * Every user with a membership row, including those who have muted the
     * channel. Excludes users who left a direct channel.
     */
    /**
     * The people in the channel, as everyone else sees them.
     *
     * Hidden memberships are excluded: a moderator who joined to observe should not
     * appear in the member list, which is the whole point of joining that way. They
     * still have a membership row — see `memberships()`, which does not filter — so
     * their unread state and sidebar entry work normally.
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_channel_user', 'channel_id', 'user_id')
            ->whereNull('chat_channel_user.left_at')
            ->where('chat_channel_user.hidden', false);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ChannelUser::class, 'channel_id');
    }

    /**
     * The tag this channel inherits permissions from. Resolved dynamically so
     * the model does not hard-depend on flarum/tags being installed.
     *
     * Gated on the extension being *enabled*, not on the class existing: the
     * class is autoloaded from vendor/ either way, and loading this relation
     * against a `tags` table whose migrations never ran is a 500.
     */
    public function tag(): ?BelongsTo
    {
        if (! resolve(ExtensionManager::class)->isEnabled('flarum-tags')) {
            return null;
        }

        return $this->belongsTo(\Flarum\Tags\Tag::class, 'tag_id');
    }

    public function membershipFor(?User $user): ?ChannelUser
    {
        if ($user === null || ! $user->exists) {
            return null;
        }

        return $this->memberships()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();
    }

    /**
     * The channel's picture, or null.
     *
     * Built from the assets base each time rather than stored as a URL, so moving
     * the forum to another host does not leave every channel icon pointing at the
     * old one. Mirrors how core resolves the logo path.
     */
    public function imageUrl(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return resolve(\Flarum\Foundation\Config::class)->url()->getPath()
            .'/assets/'.$this->image_path;
    }

    /**
     * Recomputes the denormalised counters from the messages table. Used after
     * bulk operations (message moves, retention pruning, archiving) where
     * tracking increments incrementally would be error-prone.
     */
    public function refreshMetadata(): static
    {
        $last = $this->messages()
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $this->messages_count = $this->messages()->whereNull('deleted_at')->count();
        $this->last_message_id = $last?->id;
        $this->last_message_at = $last?->created_at;
        $this->user_count = $this->memberships()->whereNull('left_at')->count();

        return $this;
    }
}
