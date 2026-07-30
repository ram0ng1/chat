<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\Translator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Ramon\Chat\Channel;
use Ramon\Chat\Webhook;
use Tobyz\JsonApiServer\Context as OriginalContext;

/**
 * Inbound webhooks, administered from the admin panel.
 *
 * Admin-only throughout: the whole resource is gated on `isAdmin()` rather than on
 * a chat permission, because a webhook posts *as the forum* into a channel and its
 * key bypasses the normal session. That is an installation-level capability, not a
 * moderation one.
 *
 * The key itself is `$hidden` on the model. It is exposed here on create and on an
 * explicit rotate, and never in a listing — a key that shows up in every index
 * response ends up in logs and screenshots.
 *
 * @extends AbstractDatabaseResource<Webhook>
 */
class WebhookResource extends AbstractDatabaseResource
{
    /**
     * Set by the rotate action so the field getters know the key in this response
     * is newly minted and may be shown.
     *
     * An instance property rather than something read off the endpoint: Api\Context
     * exposes `creating()`/`listing()` and friends but no stable way to identify a
     * custom endpoint, and `withInternal()` returns a copy that the serialiser
     * never sees. The resource is resolved once per request, so this is scoped to
     * exactly the response being built.
     */
    protected bool $keyJustMinted = false;

    public function __construct(
        protected Translator $translator,
        protected UrlGenerator $url
    ) {
    }

    protected function keyVisible(Context $context): bool
    {
        return $context->creating() || $this->keyJustMinted;
    }

    public function type(): string
    {
        return 'chat-webhooks';
    }

    public function model(): string
    {
        return Webhook::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        // Belt and braces alongside the per-endpoint checks: even a mistakenly
        // unguarded endpoint cannot leak rows to a non-admin.
        if (! $context->getActor()->isAdmin()) {
            $query->whereRaw('1 = 0');
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->visible(fn (Context $context) => $context->getActor()->isAdmin())
                ->defaultInclude(['channel'])
                ->defaultSort('-createdAt'),

            Endpoint\Show::make()
                ->authenticated()
                ->visible(fn (Webhook $webhook, Context $context) => $context->getActor()->isAdmin())
                ->defaultInclude(['channel']),

            Endpoint\Create::make()
                ->authenticated()
                ->visible(fn (Context $context) => $context->getActor()->isAdmin())
                ->defaultInclude(['channel']),

            Endpoint\Update::make()
                ->authenticated()
                ->visible(fn (Webhook $webhook, Context $context) => $context->getActor()->isAdmin())
                ->defaultInclude(['channel']),

            Endpoint\Delete::make()
                ->authenticated()
                ->visible(fn (Webhook $webhook, Context $context) => $context->getActor()->isAdmin()),

            // Rotating invalidates whatever is configured on the sending side, so
            // it is a deliberate action rather than a side effect of an update.
            Endpoint\Endpoint::make('rotate')
                ->route('POST', '/{id}/rotate')
                ->authenticated()
                ->visible(fn (Webhook $webhook, Context $context) => $context->getActor()->isAdmin())
                ->action(function (Context $context) {
                    /** @var Webhook $webhook */
                    $webhook = $context->model;

                    $webhook->key = Webhook::generateKey();
                    $webhook->save();

                    $this->keyJustMinted = true;

                    return $webhook;
                })
                ->defaultInclude(['channel']),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name')
                ->requiredOnCreate()
                ->writable()
                ->maxLength(100),

            Schema\Str::make('description')
                ->nullable()
                ->writable()
                ->maxLength(255),

            // Overrides for how a delivered message is attributed in the stream.
            Schema\Str::make('username')
                ->nullable()
                ->writable()
                ->maxLength(60),

            Schema\Str::make('emoji')
                ->nullable()
                ->writable()
                ->maxLength(64),

            Schema\Boolean::make('active')
                ->writable(),

            Schema\Integer::make('channelId')
                ->requiredOnCreate()
                ->writable()
                ->set(function (Webhook $webhook, $value) {
                    $channel = Channel::query()->whereNull('deleted_at')->find((int) $value);

                    if ($channel === null) {
                        throw new ValidationException([
                            'channelId' => $this->translator->trans('ramon-chat.api.channel_not_found'),
                        ]);
                    }

                    $webhook->channel_id = $channel->id;
                }),

            Schema\Integer::make('deliveriesCount'),
            Schema\DateTime::make('lastDeliveredAt')->nullable(),
            Schema\DateTime::make('createdAt'),

            // Only ever populated right after the key is minted: on create and on
            // rotate. A listing returns null, so the key cannot be harvested from a
            // cached index response, a log, or a screenshot of the webhook list.
            Schema\Str::make('key')
                ->nullable()
                ->get(fn (Webhook $webhook, Context $context) => $this->keyVisible($context) ? $webhook->key : null),

            // The full URL to configure on the sending side, so an admin never has
            // to assemble it by hand from a key and a route pattern.
            Schema\Str::make('url')
                ->nullable()
                ->get(fn (Webhook $webhook, Context $context) => $this->keyVisible($context)
                    ? $this->url->to('api')->route('chat.webhooks.deliver', ['key' => $webhook->key])
                    : null),

            Schema\Relationship\ToOne::make('channel')->type('chat-channels')->includable(),
            Schema\Relationship\ToOne::make('creator')->type('users')->includable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt'),
            SortColumn::make('name'),
            SortColumn::make('deliveriesCount'),
        ];
    }

    public function newModel(OriginalContext $context): Webhook
    {
        $webhook = new Webhook();

        // Explicit defaults: an unset boolean serialises as null, and an unset key
        // would make the webhook undeliverable.
        $webhook->key = Webhook::generateKey();
        $webhook->active = true;
        $webhook->deliveries_count = 0;
        $webhook->creator_id = $context->getActor()->id;

        return $webhook;
    }

    public function deleteModel(object $model, OriginalContext $context): void
    {
        $model->delete();
    }
}
