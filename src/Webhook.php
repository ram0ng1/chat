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
use Illuminate\Support\Str;

/**
 * An incoming webhook that posts into one channel. Slack-compatible payloads
 * are accepted, matching Discourse's integration surface.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $username
 * @property string|null $emoji
 * @property int $channel_id
 * @property string $key
 * @property int|null $creator_id
 * @property bool $active
 * @property int $deliveries_count
 * @property Carbon|null $last_delivered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Channel|null $channel
 * @property-read User|null $creator
 */
class Webhook extends AbstractModel
{
    protected $table = 'chat_webhooks';

    public $timestamps = true;

    protected $guarded = [];

    /**
     * The key authenticates deliveries, so it must never reach a non-admin
     * client through the API layer.
     */
    protected $hidden = ['key'];

    protected $casts = [
        'channel_id'        => 'integer',
        'creator_id'        => 'integer',
        'active'            => 'boolean',
        'deliveries_count'  => 'integer',
        'last_delivered_at' => 'datetime',
    ];

    public static function build(string $name, Channel $channel, ?User $creator = null): static
    {
        $webhook = new static();

        $webhook->name = $name;
        $webhook->channel_id = $channel->id;
        $webhook->creator_id = $creator?->id;
        $webhook->key = static::generateKey();
        $webhook->active = true;

        return $webhook;
    }

    public static function generateKey(): string
    {
        do {
            $key = Str::random(48);
        } while (static::query()->where('key', $key)->exists());

        return $key;
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function url(): string
    {
        return resolve(\Flarum\Http\UrlGenerator::class)
            ->to('api')
            ->path('chat/hooks/'.$this->key);
    }

    public function recordDelivery(): static
    {
        $this->deliveries_count++;
        $this->last_delivered_at = Carbon::now();

        return $this;
    }
}
