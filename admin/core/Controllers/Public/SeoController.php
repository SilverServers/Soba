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
     * GET /sitemap.xml — sitemap index
     */
    public function sitemapIndex(array $vars = []): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        echo Sitemap::index();
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

        // Find the Content Type key from its public slug
        $contentTypeKey = null;
        foreach (Registry::all() as $key => $ct) {
            if (($ct['public_slug'] ?? $key) === $slug) {
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
