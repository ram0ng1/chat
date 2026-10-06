<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Flarum\Post\Exception\FloodingException;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Draft;
use Ramon\Chat\Service\ActionThrottle;
use Ramon\Chat\Thread;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * Stores (or clears) the actor's composer draft for a channel or thread scope.
 *
 * Drafts live server-side rather than in localStorage so they follow the user
 * across devices, which is what Discourse does.
 */
class DraftController implements RequestHandlerInterface
{
    /**
     * The floor for the length cap. A draft may run past what one message
     * allows, since people write long and trim, but it is a column in a shared
     * table rather than free storage; twice the message limit, never less than
     * this, leaves room to edit without becoming somewhere to park a file.
     */
    protected const MIN_DRAFT_LENGTH = 20000;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Translator $translator,
        protected ActionThrottle $throttle
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        // The composer saves on a 1.2 second debounce, so a person tops out
        // around fifty a minute; this is the ceiling for a script, not for them.
        if (! $this->throttle->attempt('draft.'.$actor->id, 60, 60)) {
            throw new FloodingException();
        }

        $body = $request->getParsedBody();
        $attributes = (array) Arr::get($body, 'data.attributes', []);

        $channelId = (int) Arr::get($attributes, 'channelId');

        /** @var Channel|null $channel */
        $channel = Channel::query()->whereVisibleTo($actor)->find($channelId);

        if ($channel === null) {
            throw new ForbiddenException();
        }

        $threadId = Arr::get($attributes, 'threadId');
        $thread = null;

        if ($threadId !== null && $threadId !== '') {
            /** @var Thread|null $thread */
            $thread = Thread::query()
                ->whereVisibleTo($actor)
                ->where('channel_id', $channel->id)
                ->find((int) $threadId);

            if ($thread === null) {
                throw new ForbiddenException();
            }
        }

        $content = Arr::get($attributes, 'content');

        if ($content !== null && ! is_scalar($content)) {
            throw new ValidationException(['content' => $this->translator->trans('ramon-chat.api.message_empty')]);
        }

        $max = max(self::MIN_DRAFT_LENGTH, 2 * (int) $this->settings->get('ramon-chat.max_message_length', 3000));

        if ($content !== null && mb_strlen((string) $content) > $max) {
            throw new ValidationException([
                'content' => $this->translator->trans('ramon-chat.api.draft_too_long', ['max' => $max]),
            ]);
        }

        $draft = Draft::store($actor, $channel, $thread, $content === null ? null : (string) $content);

        // An empty draft is a deletion, not an empty record.
        if ($draft === null) {
            return new EmptyResponse(204);
        }

        return new JsonResponse([
            'data' => [
                'type'       => 'chat-drafts',
                'id'         => (string) $draft->id,
                'attributes' => [
                    'channelId' => $draft->channel_id,
                    'threadId'  => $draft->thread_id,
                    'content'   => $draft->content,
                ],
            ],
        ]);
    }
}
