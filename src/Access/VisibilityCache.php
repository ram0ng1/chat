<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\User\User;
use WeakMap;

/**
 * Memoises "can this actor see row N" for the length of one request.
 *
 * Both policies answer visibility with an `EXISTS` query carrying the whole
 * visibility scope, and both are asked repeatedly for the same row: a message
 * resource resolves nine capability flags per row, several of which fall through
 * to `view()`, so a page of fifty messages ran the same query hundreds of times
 * for an answer that cannot change while the request is being served.
 *
 * Keyed on the User *instance* through a WeakMap rather than on the id in a
 * plain static array, for the reason flarum/tags keys its permission cache the
 * same way: a queue worker or an Octane process serves many actors from one PHP
 * process, and an id-keyed static would hand one user's answers to the next. A
 * WeakMap entry dies with the User object that owns it.
 *
 * Correctness rests on visibility being a read-only property of (actor, row)
 * within a request. Nothing in this extension grants or revokes access to a row
 * mid-request — joining a channel changes membership, which is a different
 * question and is not cached here.
 */
class VisibilityCache
{
    /**
     * Per actor, an ArrayObject rather than a plain array — and that is load
     * bearing, not a style choice.
     *
     * Resolvers nest: answering "can this actor see thread 5" asks "can they see
     * channel 1" from inside its own `remember()`. With a plain array the outer
     * call would be holding a *copy* taken before the inner one ran, and writing
     * its own answer back would discard whatever the inner call had stored. The
     * channel answer was therefore thrown away on every thread, and a list of
     * twenty threads in two channels ran twenty channel-visibility queries.
     *
     * An object is shared by reference, so a nested write is still there when the
     * outer call returns.
     *
     * @var WeakMap<User, \ArrayObject<string, bool>>
     */
    private WeakMap $entries;

    public function __construct()
    {
        $this->entries = new WeakMap();
    }

    /**
     * Returns the memoised answer for `$type:$id`, calling `$resolve` once.
     */
    public function remember(User $actor, string $type, int|string $id, callable $resolve): bool
    {
        $key = $type.':'.$id;
        $forActor = $this->entries[$actor] ??= new \ArrayObject();

        if ($forActor->offsetExists($key)) {
            return $forActor[$key];
        }

        return $forActor[$key] = (bool) $resolve();
    }

    /**
     * Drops everything. For long-lived processes that reuse the container across
     * jobs, where a User object can outlive the unit of work it was loaded for.
     */
    public function flush(): void
    {
        $this->entries = new WeakMap();
    }
}
