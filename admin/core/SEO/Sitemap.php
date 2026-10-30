<?php

declare(strict_types=1);

namespace Cms\SEO;

use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Database\Database;
use Cms\Models\Term;
use PDO;

/**
 * Sitemap — generates XML sitemaps dynamically.
 *
 * Routes:
 *   /sitemap.xml          → sitemap index — or, while there is only one map,
 *                           that map itself (`root()`)
 *   /sitemap-pages.xml    → pages sitemap
 *   /sitemap-{type}.xml   → per-Content Type sitemap
 *   /sitemap-{tax}.xml    → a public taxonomy's term archives (same route:
 *                           a Content Type's slug is looked for first)
 *
 * Only includes published, indexed, non-excluded entries.
 */
class Sitemap
{
    /**
     * What `/sitemap.xml` serves: the index of every map — or, while the site
     * has only one, that map itself.
     *
     * ── ONE MAP IS SERVED AS ITSELF ─────────────────────────────────────────
     * A site with nothing to map but its pages had an index naming one child,
     * `sitemap-pages.xml`, so its sitemap opened in a browser, an SEO tool or
     * Search Console before Google read the child looked empty. Both shapes
     * are valid at this address (sitemaps.org), so a single map is served
     * whole, and the index comes back by itself once there is a second map.
     * Decided by how many maps there are, never by which one it is.
     */
    public static function root(): string
    {
        $maps = self::maps();

        return count($maps) === 1 ? ($maps[0]['render'])() : self::indexOf($maps);
    }

    /**
     * Render the sitemap index XML.
     */
    public static function index(): string
    {
        return self::indexOf(self::maps());
    }

    /**
     * Every map the site has, with how to draw it.
     *
     * @return list<array{loc: string, lastmod: ?string, render: callable(): string}>
     */
    private static function maps(): array
    {
        $siteUrl    = defined('BASE_URL') ? BASE_URL : '';
        $sitemaps   = [];

        // Pages sitemap. Not listed when the pages module is switched off (its
        // route is gone too; Helpers\Modules), nor while no page is published:
        // a map of nothing is not worth a request.
        $pagesLastmod = \Cms\Helpers\Modules::enabled('pages') ? self::lastModified('pages') : null;
        if ($pagesLastmod !== null) {
            $sitemaps[] = [
                'loc'     => $siteUrl . '/sitemap-pages.xml',
                'lastmod' => $pagesLastmod,
                'render'  => static fn (): string => self::pages(),
            ];
        }

        // Per Content Type sitemaps — skip CTs with no public URL (admin-only
        // types like gallery_entry / media_item) so we don't emit
        // sitemap-.xml entries or leak their existence.
        foreach (Registry::all() as $key => $ct) {
            $publicSlug = $ct['public_slug'] ?? null;
            if ($publicSlug === null || $publicSlug === '') {
                continue;
            }
            $lastmod = self::lastModifiedPosts($key);
            if ($lastmod !== null) {
                $sitemaps[] = [
                    'loc'     => $siteUrl . '/sitemap-' . $publicSlug . '.xml',
                    'lastmod' => $lastmod,
                    'render'  => static fn (): string => self::contentType((string) $key),
                ];
            }
        }

        // Per taxonomy — every taxonomy with a public address has term
        // archives, and a map of them. Left out while no archive
        // lists anything: a map of nothing indexable is not worth a request.
        foreach (TaxonomyRegistry::all() as $key => $tax) {
            $publicSlug = trim((string) ($tax['public_slug'] ?? ''));
            if ($publicSlug === '') {
                continue;
            }
            $rows = self::termRows((string) $key, !empty($tax['hierarchical']));
            if ($rows !== []) {
                $sitemaps[] = [
                    'loc'     => $siteUrl . '/sitemap-' . self::fileSlug($publicSlug) . '.xml',
                    'lastmod' => date('Y-m-d', strtotime(max(array_column($rows, 'lastmod')))),
                    'render'  => static fn (): string => self::taxonomy((string) $key),
                ];
            }
        }

        return $sitemaps;
    }

    /**
     * An index naming these maps.
     *
     * @param list<array{loc: string, lastmod: ?string}> $sitemaps
     */
    private static function indexOf(array $sitemaps): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($sitemaps as $sm) {
            $xml .= '  <sitemap>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($sm['loc']) . '</loc>' . "\n";
            if ($sm['lastmod'] !== null) {
                $xml .= '    <lastmod>' . $sm['lastmod'] . '</lastmod>' . "\n";
            }
            $xml .= '  </sitemap>' . "\n";
        }

        $xml .= '</sitemapindex>';
        return $xml;
    }

    /**
     * Render the pages sitemap XML.
     */
    public static function pages(): string
    {
        $pdo  = Database::getInstance();
        // `featured_image_id` IS SELECTED HERE FOR THE SAME REASON IT IS IN
        // ::contentType() BELOW — a page's featured image belongs in the image
        // sitemap exactly as an entry's does.
        $stmt = $pdo->query(
            "SELECT slug, updated_at, featured_image_id FROM pages
             WHERE status = 'published' ORDER BY menu_order ASC, title ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $siteUrl = defined('BASE_URL') ? BASE_URL : '';

        return self::buildUrlSet($rows, function (array $row) use ($siteUrl) {
            $entry = [
                // `Url::page()`, the one builder of a page's address.
                'loc'     => \Cms\Helpers\Url::page((string) $row['slug']),
                'lastmod' => date('Y-m-d', strtotime($row['updated_at'])),
            ];
            if (!empty($row['featured_image_id'])) {
                $imageUrl = \Cms\Models\Media::getUrl((int) $row['featured_image_id']);
                if ($imageUrl !== '') {
                    $entry['image'] = $imageUrl;
                }
            }
            return $entry;
        });
    }

    /**
     * Render a per-Content Type sitemap XML.
     */
    public static function contentType(string $contentTypeKey): string
    {
        $ct = Registry::get($contentTypeKey);
        if ($ct === null) {
            return self::emptyUrlSet();
        }

        [$where, $params] = self::listedEntries($contentTypeKey);
        $stmt = Database::getInstance()->prepare(
            "SELECT slug, updated_at, featured_image_id FROM posts WHERE {$where} ORDER BY created_at DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $siteUrl    = defined('BASE_URL') ? BASE_URL : '';
        $publicSlug = $ct['public_slug'];

        return self::buildUrlSet($rows, function (array $row) use ($siteUrl, $publicSlug) {
            $entry = [
                'loc'     => \Cms\Helpers\Url::page($publicSlug . '/' . $row['slug']),
                'lastmod' => date('Y-m-d', strtotime($row['updated_at'])),
            ];

            // Include image if featured image exists
            if (!empty($row['featured_image_id'])) {
                $imageUrl = \Cms\Models\Media::getUrl((int) $row['featured_image_id']);
                if ($imageUrl !== '') {
                    $entry['image'] = $imageUrl;
                }
            }

            return $entry;
        });
    }

    /**
     * A taxonomy's term archives — `/sitemap-{public slug}.xml`.
     *
     * ONLY THE ARCHIVES THAT LIST SOMETHING. `Public\TermController` marks an
     * empty archive `noindex`, and a sitemap that names a page it has told
     * search engines not to index contradicts itself. A pillar counts what is
     * filed under its children, exactly as its page does, and its
     * `lastmod` is the newest live entry that page shows.
     */
    public static function taxonomy(string $taxonomyKey): string
    {
        $tax        = TaxonomyRegistry::get($taxonomyKey);
        $publicSlug = trim((string) ($tax['public_slug'] ?? ''));
        if ($tax === null || $publicSlug === '') {
            return self::emptyUrlSet();
        }

        $siteUrl = defined('BASE_URL') ? BASE_URL : '';

        return self::buildUrlSet(
            self::termRows($taxonomyKey, !empty($tax['hierarchical'])),
            static fn (array $row): array => [
                'loc'     => \Cms\Helpers\Url::page($publicSlug . '/' . $row['slug']),
                'lastmod' => date('Y-m-d', strtotime($row['lastmod'])),
            ]
        );
    }

    /**
     * The name a taxonomy's map is served under: its public address with the
     * slashes of a nested one (`articles/category`) as hyphens,
     * because the sitemap route's `{slug}` is one path segment. The
     * controller maps a request back with this same function.
     */
    public static function fileSlug(string $publicSlug): string
    {
        return str_replace('/', '-', trim($publicSlug, '/'));
    }

    /**
     * Every term of a taxonomy whose archive lists a live entry, with the
     * newest such entry's `updated_at`. One query for the direct filings; a
     * hierarchical term then takes the newest across its whole branch.
     *
     * @return list<array{slug: string, lastmod: string}>
     */
    private static function termRows(string $taxonomyKey, bool $hierarchical): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT ptr.term_id, MAX(p.updated_at) AS lastmod
               FROM post_term_relationships ptr
               JOIN terms t ON t.id = ptr.term_id
               JOIN posts p ON p.id = ptr.post_id
              WHERE t.taxonomy_key = ? AND " . \Cms\Models\Post::live('p') . "
           GROUP BY ptr.term_id"
        );
        $stmt->execute([$taxonomyKey]);
        $direct = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $direct[(int) $row['term_id']] = (string) $row['lastmod'];
        }

        $terms    = Term::allForTaxonomy($taxonomyKey);
        $children = [];
        foreach ($terms as $term) {
            $children[(int) ($term['parent_id'] ?? 0)][] = (int) $term['id'];
        }

        $out = [];
        foreach ($terms as $term) {
            // The term and, on a nested taxonomy, its whole branch — walked
            // with a seen-set, so a parent loop in the data cannot hang this.
            $queue  = [(int) $term['id']];
            $seen   = [];
            $latest = null;
            while ($queue !== []) {
                $id = array_pop($queue);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                if (isset($direct[$id]) && ($latest === null || $direct[$id] > $latest)) {
                    $latest = $direct[$id];
                }
                if ($hierarchical) {
                    array_push($queue, ...($children[$id] ?? []));
                }
            }
            if ($latest !== null) {
                $out[] = ['slug' => (string) $term['slug'], 'lastmod' => $latest];
            }
        }

        return $out;
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param callable                         $mapper  Returns ['loc' => ..., 'lastmod' => ..., 'image' => ...]
     */
    private static function buildUrlSet(array $rows, callable $mapper): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        $xml .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        foreach ($rows as $row) {
            $entry = $mapper($row);
            $xml  .= '  <url>' . "\n";
            $xml  .= '    <loc>' . htmlspecialchars($entry['loc']) . '</loc>' . "\n";
            if (!empty($entry['lastmod'])) {
                $xml .= '    <lastmod>' . $entry['lastmod'] . '</lastmod>' . "\n";
            }
            if (!empty($entry['image'])) {
                $xml .= '    <image:image>' . "\n";
                $xml .= '      <image:loc>' . htmlspecialchars($entry['image']) . '</image:loc>' . "\n";
                $xml .= '    </image:image>' . "\n";
            }
            $xml .= '  </url>' . "\n";
        }

        $xml .= '</urlset>';
        return $xml;
    }

    private static function emptyUrlSet(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
    }

    private static function lastModified(string $table): ?string
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            "SELECT MAX(updated_at) FROM {$table} WHERE status = 'published'"
        );
        $val = $stmt->fetchColumn();
        return $val ? date('Y-m-d', strtotime($val)) : null;
    }

    /** The newest of what the type's map lists: null when it lists nothing, which keeps the map out of the index. */
    private static function lastModifiedPosts(string $postType): ?string
    {
        [$where, $params] = self::listedEntries($postType);
        $stmt = Database::getInstance()->prepare("SELECT MAX(updated_at) FROM posts WHERE {$where}");
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val ? date('Y-m-d', strtotime($val)) : null;
    }

    /**
     * WHICH OF A TYPE'S ENTRIES ITS MAP LISTS, as SQL and its parameters. The
     * map and the index's lastmod both ask this, so the index never names a map
     * that comes back empty: a type whose every entry is left out of search
     * results has no map at all.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function listedEntries(string $contentTypeKey): array
    {
        $params = [':type' => $contentTypeKey];
        // A scheduled entry is not on the site yet, so it is not in the map.
        // ── NOR IS ONE THE OWNER TOLD SEARCH ENGINES TO LEAVE OUT ──────────
        // "Show in search results: No" puts `noindex` on the page;
        // a sitemap that still lists it asks a search engine to index what the
        // page itself says not to.
        $where  = "post_type = :type AND " . \Cms\Models\Post::live() . "
                   AND id NOT IN (SELECT post_id FROM post_meta
                                   WHERE meta_key = '_seo_robots' AND meta_value LIKE '%noindex%')";

        // Optional meta filter — e.g. 'entry' CT restricts sitemap to view=article only.
        $filterMeta = Registry::get($contentTypeKey)['sitemap_filter_meta'] ?? null;
        if ($filterMeta !== null) {
            $where .= " AND id IN (SELECT post_id FROM post_meta WHERE meta_key = :mk AND meta_value = :mv)";
            $params[':mk'] = $filterMeta['key'];
            $params[':mv'] = $filterMeta['value'];
        }

        return [$where, $params];
    }
}
