<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Frontend;

use Flarum\Frontend\Document;
use Flarum\Http\Exception\RouteNotFoundException;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Content callback for every `/chat/*` route: 404 for anyone who cannot use the
 * chat.
 *
 * Without it these paths answer 200 and serve the forum shell, and the client then
 * renders an empty chat — a page that exists, looks broken, and tells a
 * logged-out visitor nothing. Flarum's own not-found page is the honest answer,
 * and it is what every other gated route on the forum gives.
 *
 * Applies to guests and to anyone whose group lacks `ramon-chat.use`; the
 * per-channel checks still happen at the API, so this is the outer gate rather
 * than the only one.
 */
class RequireChatAccess
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $actor = RequestUtil::getActor($request);

        if (! $actor->hasPermissionLike('ramon-chat.use')) {
            throw new RouteNotFoundException();
        }
    }
}
