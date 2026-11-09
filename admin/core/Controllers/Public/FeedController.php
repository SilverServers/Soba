<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\Commerce\ProductFeed;

/**
 * GET /feed/products.xml — the shop's products, as Google Merchant Center and
 * Meta's catalogue read them (`Commerce\ProductFeed`).
 *
 * Public, as the product pages it lists are, and kept out of search results:
 * it is a file for those two to fetch, not a page.
 */
class FeedController
{
    public function products(array $vars = []): void
    {
        header('Content-Type: application/rss+xml; charset=utf-8');
        header('X-Robots-Tag: noindex');
        header('Cache-Control: public, max-age=900');
        echo ProductFeed::xml();
    }
}
