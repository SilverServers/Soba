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
            $rows = self::taxonomyRows((string) $key, $tax);
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
        // EACH LANGUAGE'S (`Site\Language`), a page and its translation each
        // naming the other.
        $rows = [];
        foreach (self::languages() as $lang) {
            $stmt = $pdo->query(
                "SELECT id, slug, lang, translation_of, updated_at, featured_image_id FROM pages
                 WHERE " . \Cms\Models\Page::live('', $lang) . " ORDER BY menu_order ASC, title ASC"
            );
            array_push($rows, ...$stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        $versions = self::versions($rows, static fn (array $row): string => \Cms\Helpers\Url::pageOf($row));

        $siteUrl = defined('BASE_URL') ? BASE_URL : '';

        return self::buildUrlSet($rows, function (array $row) use ($siteUrl, $versions) {
            $entry = [
                // `Url::pageOf()`, the one builder of a Page's address, in its language.
                'loc'        => \Cms\Helpers\Url::pageOf($row),
                'lastmod'    => date('Y-m-d', strtotime($row['updated_at'])),
                'alternates' => $versions[(int) $row['id']] ?? [],
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
        if ($ct === null || trim((string) ($ct['public_slug'] ?? '')) === '') {
            return self::emptyUrlSet();
        }

        // EACH LANGUAGE THE TYPE IS IN (`Content\Translation`), an entry and
        // its translation each naming the other.
        $rows = [];
        foreach (self::languagesOf($contentTypeKey) as $lang) {
            [$where, $params] = self::listedEntries($contentTypeKey, $lang);
            $stmt = Database::getInstance()->prepare(
                "SELECT id, slug, lang, translation_of, updated_at, featured_image_id FROM posts WHERE {$where} ORDER BY created_at DESC"
            );
            $stmt->execute($params);
            array_push($rows, ...$stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        $siteUrl    = defined('BASE_URL') ? BASE_URL : '';
        $publicSlug = $ct['public_slug'];
        $versions   = self::versions($rows, static fn (array $row): string => \Cms\Helpers\Url::entry($row, (string) $publicSlug));

        return self::buildUrlSet($rows, function (array $row) use ($siteUrl, $publicSlug, $versions) {
            $entry = [
                'loc'        => \Cms\Helpers\Url::entry($row, $publicSlug),
                'lastmod'    => date('Y-m-d', strtotime($row['updated_at'])),
                'alternates' => $versions[(int) $row['id']] ?? [],
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

        // A TERM IS BOTH LANGUAGES' (`Site\Language`): its page in each that
        // lists something, each naming the other when both do.
        $rows  = self::taxonomyRows($taxonomyKey, $tax);
        $langs = [];
        foreach ($rows as $row) {
            $langs[(string) $row['slug']][] = (string) $row['lang'];
        }
        $second = \Cms\Site\Language::prefix();

        return self::buildUrlSet(
            $rows,
            static fn (array $row): array => [
                'loc'        => \Cms\Helpers\Url::term($row, $publicSlug, (string) $row['lang']),
                'lastmod'    => date('Y-m-d', strtotime($row['lastmod'])),
                'alternates' => $second !== '' && count($langs[(string) $row['slug']] ?? []) === 2
                    ? \Cms\Site\Language::versions(static fn (string $lang): string => \Cms\Helpers\Url::term($row, $publicSlug, $lang))
                    : [],
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
     * In `$lang` ('' the site's own language): a term's page lists that
     * language's entries.
     *
     * @return list<array{slug: string, lastmod: string}>
     */
    private static function termRows(string $taxonomyKey, bool $hierarchical, string $lang = ''): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT ptr.term_id, MAX(p.updated_at) AS lastmod
               FROM post_term_relationships ptr
               JOIN terms t ON t.id = ptr.term_id
               JOIN posts p ON p.id = ptr.post_id
              WHERE t.taxonomy_key = ? AND " . \Cms\Models\Post::live('p', $lang) . "
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
        $entries = array_map($mapper, $rows);
        // THE PAGE IN EACH LANGUAGE (`alternates`, tag => address, the site's
        // own first): named only in a map with a page in two.
        $paired = array_filter($entries, static fn (array $entry): bool => !empty($entry['alternates'])) !== [];

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        $xml .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"'
              . ($paired ? ' xmlns:xhtml="http://www.w3.org/1999/xhtml"' : '') . '>' . "\n";

        foreach ($entries as $entry) {
            $xml  .= '  <url>' . "\n";
            $xml  .= '    <loc>' . htmlspecialchars($entry['loc']) . '</loc>' . "\n";
            if (!empty($entry['alternates'])) {
                foreach ($entry['alternates'] as $tag => $href) {
                    $xml .= '    <xhtml:link rel="alternate" hreflang="' . htmlspecialchars((string) $tag) . '" href="' . htmlspecialchars((string) $href) . '"/>' . "\n";
                }
                $xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . htmlspecialchars((string) reset($entry['alternates'])) . '"/>' . "\n";
            }
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
        // Of the languages the map lists (`Post::inLanguage()`).
        $in   = array_map(static fn (string $lang): string => \Cms\Models\Post::inLanguage('', $lang), self::languages());
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            "SELECT MAX(updated_at) FROM {$table} WHERE status = 'published' AND (" . implode(' OR ', $in) . ')'
        );
        $val = $stmt->fetchColumn();
        return $val ? date('Y-m-d', strtotime($val)) : null;
    }

    /** The newest of what the type's map lists: null when it lists nothing, which keeps the map out of the index. */
    private static function lastModifiedPosts(string $postType): ?string
    {
        $newest = null;
        foreach (self::languagesOf($postType) as $lang) {
            [$where, $params] = self::listedEntries($postType, $lang);
            $stmt = Database::getInstance()->prepare("SELECT MAX(updated_at) FROM posts WHERE {$where}");
            $stmt->execute($params);
            $val = $stmt->fetchColumn();
            if ($val && ($newest === null || (string) $val > $newest)) {
                $newest = (string) $val;
            }
        }
        return $newest !== null ? date('Y-m-d', strtotime($newest)) : null;
    }

    /**
     * WHICH OF A TYPE'S ENTRIES ITS MAP LISTS, as SQL and its parameters. The
     * map and the index's lastmod both ask this, so the index never names a map
     * that comes back empty: a type whose every entry is left out of search
     * results has no map at all.
     *
     * In `$lang` ('' the site's own language).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function listedEntries(string $contentTypeKey, string $lang = ''): array
    {
        $params = [':type' => $contentTypeKey];
        // A scheduled entry is not on the site yet, so it is not in the map.
        // ── NOR IS ONE THE OWNER TOLD SEARCH ENGINES TO LEAVE OUT ──────────
        // "Show in search results: No" puts `noindex` on the page;
        // a sitemap that still lists it asks a search engine to index what the
        // page itself says not to.
        $where  = "post_type = :type AND " . \Cms\Models\Post::live('', $lang) . "
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

    /** The languages a map lists: the site's own, and the second's when the site has one. */
    private static function languages(): array
    {
        $second = \Cms\Site\Language::prefix();

        return $second !== '' ? ['', $second] : [''];
    }

    /** The languages a type's map lists: the second's too when it is translated (`Content\Translation`). */
    private static function languagesOf(string $contentTypeKey): array
    {
        return \Cms\Content\Translation::translates($contentTypeKey) ? self::languages() : [''];
    }

    /**
     * A taxonomy's term rows in each language it has term pages in, each
     * with its `lang`.
     *
     * @param array<string, mixed> $tax
     * @return list<array<string, string>>
     */
    private static function taxonomyRows(string $taxonomyKey, array $tax): array
    {
        $rows  = [];
        $langs = \Cms\Content\Translation::taxonomyTranslates($taxonomyKey) ? self::languages() : [''];
        foreach ($langs as $lang) {
            foreach (self::termRows($taxonomyKey, !empty($tax['hierarchical']), $lang) as $row) {
                $row['lang'] = $lang;
                $rows[]      = $row;
            }
        }

        return $rows;
    }

    /**
     * EACH LISTED ROW'S VERSIONS, tag => address, the site's own first: for an
     * original and its translation both in `$rows`, under both their ids. A
     * row whose counterpart is not listed has none.
     *
     * @param list<array<string, mixed>>              $rows each with id, lang and translation_of
     * @param callable(array<string, mixed>): string $url
     * @return array<int, array<string, string>>
     */
    private static function versions(array $rows, callable $url): array
    {
        $second = \Cms\Site\Language::prefix();
        if ($second === '') {
            return [];
        }
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $out = [];
        foreach ($rows as $row) {
            $of = (int) ($row['translation_of'] ?? 0);
            if ((string) $row['lang'] !== $second || !isset($byId[$of])) {
                continue;
            }
            $pair = [\Cms\Site\Language::own() => $url($byId[$of]), \Cms\Site\Language::second() => $url($row)];
            $out[$of]              = $pair;
            $out[(int) $row['id']] = $pair;
        }

        return $out;
    }
}
