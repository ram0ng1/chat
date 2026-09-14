<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Formatter;

use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Collection;
use s9e\TextFormatter\Utils;

/**
 * Lets a chat model survive formatter callbacks written for forum posts.
 *
 * Every model that uses HasFormattedContent is handed to every render and
 * unparse callback any extension registers, and some of those (fof/move-posts
 * among them) assume the context is a Post and read `$context->mentionsPosts`
 * without checking. A Post has that relation; a chat message did not, so a
 * pasted quote made `find()` fatal on null and took the channel down with it.
 *
 * The accessor stands in for the relation: it resolves the posts the content
 * actually mentions, so a callback that corrects post numbers after a move
 * still does its job, and an empty collection is returned when nothing is
 * mentioned. Memoised per instance because unparse and render each call it.
 *
 * @property-read Collection<int, Post> $mentionsPosts
 */
trait ResolvesMentionedPosts
{
    /**
     * @var Collection<int, Post>|null
     */
    protected ?Collection $resolvedMentionedPosts = null;

    /**
     * @return Collection<int, Post>
     */
    public function getMentionsPostsAttribute(): Collection
    {
        if ($this->resolvedMentionedPosts !== null) {
            return $this->resolvedMentionedPosts;
        }

        $ids = $this->mentionedPostIds();

        $posts = $ids === []
            ? new Collection()
            : Post::query()->whereIn('id', $ids)->get();

        return $this->resolvedMentionedPosts = $posts;
    }

    /**
     * Ids of the posts the parsed content mentions, from the POSTMENTION tags.
     *
     * @return list<int>
     */
    public function mentionedPostIds(): array
    {
        $xml = $this->attributes['content'] ?? null;

        if (! is_string($xml) || $xml === '' || ! str_contains($xml, '<POSTMENTION')) {
            return [];
        }

        $ids = [];

        foreach (Utils::getAttributeValues($xml, 'POSTMENTION', 'id') as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * The content as escaped plain text, for when rendering it threw.
     *
     * Built from the parsed XML directly rather than from `$this->content`,
     * because that getter runs the unparse callbacks and the fallback must not
     * depend on the very pipeline that just failed.
     */
    public function fallbackContentHtml(): string
    {
        $xml = $this->attributes['content'] ?? null;

        if (! is_string($xml) || $xml === '') {
            return '';
        }

        try {
            $text = Utils::removeFormatting($xml);
        } catch (\Throwable) {
            $text = strip_tags($xml);
        }

        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
