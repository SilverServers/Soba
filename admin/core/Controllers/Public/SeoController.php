<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\ContentType\Registry;
use Cms\Models\Option;
use Cms\SEO\Sitemap;

/**
 * Handles public SEO routes: sitemaps and robots.txt.
 */
class SeoController
{
    /**
     * GET /sitemap.xml — the sitemap index, or the one map while there is one
     * (`Sitemap::root()`)
     */
    public function sitemapIndex(array $vars = []): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        echo Sitemap::root();
    }

    /**
     * GET /sitemap-pages.xml — pages sitemap
     */
    public function sitemapPages(array $vars = []): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        echo Sitemap::pages();
    }

    /**
     * GET /sitemap-{slug}.xml — per-Content Type sitemap
     */
    public function sitemapContentType(array $vars = []): void
    {
        $slug = $vars['slug'] ?? '';

        // THE TYPE PUBLISHED AT THIS ADDRESS. A type with no public address has
        // no pages, so it has no map: its key is not an address, and a map
        // under it would list `/{entry}` addresses that are all not found.
        // Such a type is left out of the index, the routes and search alike.
        $contentTypeKey = null;
        foreach (Registry::all() as $key => $ct) {
            $public = trim((string) ($ct['public_slug'] ?? ''));
            if ($public !== '' && $public === $slug) {
                $contentTypeKey = $key;
                break;
            }
        }

        // A TAXONOMY'S MAP, when no Content Type answers to the slug: the term
        // archives of a taxonomy with a public address. A blank public slug
        // never matches — that taxonomy has no pages to map.
        if ($contentTypeKey === null && $slug !== '') {
            foreach (\Cms\ContentType\TaxonomyRegistry::all() as $key => $tax) {
                $public = trim((string) ($tax['public_slug'] ?? ''));
                if ($public !== '' && Sitemap::fileSlug($public) === $slug) {
                    header('Content-Type: application/xml; charset=utf-8');
                    echo Sitemap::taxonomy((string) $key);
                    return;
                }
            }
        }

        if ($contentTypeKey === null) {
            http_response_code(404);
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
            return;
        }

        header('Content-Type: application/xml; charset=utf-8');
        echo Sitemap::contentType($contentTypeKey);
    }

    /**
     * GET /robots.txt — dynamically served robots.txt
     */
    public function robotsTxt(array $vars = []): void
    {
        header('Content-Type: text/plain; charset=utf-8');

        $default = "User-agent: *\nAllow: /\n\nSitemap: "
                 . (defined('BASE_URL') ? BASE_URL : '') . "/sitemap.xml\n";

        $content = Option::get('seo_robots_txt', $default) ?? $default;

        // Replace {site_url} token (used in the default seed value)
        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        echo str_replace('{site_url}', $baseUrl, $content);
    }
}
