<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\Content\Feed;
use Cms\Helpers\Template;

/**
 * GET /{type}/feed.xml — a type's newest live entries as a feed
 * (`Content\Feed`).
 */
class EntryFeedController
{
    public function __construct(private ?string $contentTypeKey = null)
    {
    }

    public function show(array $vars = []): void
    {
        $key = (string) $this->contentTypeKey;
        if (!Feed::has($key)) {
            Template::notFound();
            return;
        }
        header('Content-Type: application/rss+xml; charset=utf-8');
        echo Feed::rss($key);
    }
}
