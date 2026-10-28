<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Helpers\Template;
use Cms\Models\Meta;
use Cms\SEO\Head;
use PDO;

/**
 * SITE SEARCH — `/search?q=`.
 *
 * The menu's Search toggle block and its Search replacement both submit to the
 * site's own search; without one, a search box in the menu would be a control
 * that led to a 404. This is the smallest honest search: published pages, and
 * published entries of every type that has pages on the site, matched on title
 * and body, with title matches first.
 *
 * ── WHAT IT DOES NOT DO ────────────────────────────────────────────────────
 * No private, draft or password-protected content, ever: a result that refuses
 * to open is worse than no result. No types without public pages — there is
 * nowhere to link to. And no relevance scoring beyond "in the title": a small
 * site's search is a finding aid, and a ranking nobody can explain is a
 * question nobody can answer.
 *
 * ── AND ONE ADDRESS IT TAKES ───────────────────────────────────────────────
 * `/search` is registered before pages, as `/cart` is, so a page whose slug is
 * "search" is shadowed by it. There is no reserved-slug list in this codebase;
 * the cart has the same edge.
 */
final class SearchController
{
    /** The most results one search returns. A finding aid, not a catalogue. */
    private const LIMIT = 50;

    public function index(array $vars = []): void
    {
        // The search page always exists, so its address's other ending always
        // 301s here (`Url::settle()`).
        \Cms\Helpers\Url::settle();

        $q       = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $results = $q === '' ? [] : self::find($q);
        $capped  = count($results) >= self::LIMIT;

        Head::set([
            'title'     => $q === '' ? 'Search' : 'Search: ' . $q,
            'canonical' => \Cms\Helpers\Url::page('search'),
            // A results page is not content, and indexing one invites every
            // query string on the internet into the search engine's copy.
            'robots'    => 'noindex, follow',
        ]);

        ob_start();
        require Template::locate('search.php');
        $pageContent = ob_get_clean();
        require Template::locate('base.php');
    }

    /**
     * Pages and entries matching `$q`, title matches first, then newest.
     *
     * @return list<array{kind: string, row: array<string, mixed>, type: ?array<string, mixed>, inTitle: bool, date: string}>
     */
    private static function find(string $q): array
    {
        $pdo  = Database::getInstance();
        // `%` and `_` in what somebody typed are literal characters, not
        // wildcards — "50%" is a search for fifty per cent.
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $out  = [];

        $pages = $pdo->prepare(
            "SELECT id, title, slug, content, featured_image_id, updated_at,
                    (title LIKE :t) AS in_title
               FROM pages
              WHERE status = 'published' AND (title LIKE :a OR content LIKE :b)
           ORDER BY in_title DESC, updated_at DESC
              LIMIT " . self::LIMIT
        );
        $pages->execute([':t' => $like, ':a' => $like, ':b' => $like]);
        foreach ($pages->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'kind'    => 'page',
                'row'     => $row,
                'type'    => null,
                'inTitle' => (bool) $row['in_title'],
                'date'    => (string) $row['updated_at'],
            ];
        }

        // ONLY TYPES WITH PAGES ON THE SITE — the same test `www/index.php`
        // uses to give a type its public routes.
        $types = [];
        foreach (Registry::all() as $key => $type) {
            if (($type['public_slug'] ?? '') !== '' && $type['public_slug'] !== null) {
                $types[(string) $key] = $type;
            }
        }

        if ($types !== []) {
            $in    = implode(',', array_fill(0, count($types), '?'));
            $posts = $pdo->prepare(
                "SELECT p.*, (p.title LIKE ?) AS in_title
                   FROM posts p
                  WHERE " . \Cms\Models\Post::live('p') . "
                    AND p.post_type IN ({$in})
                    AND (p.title LIKE ? OR p.excerpt LIKE ? OR p.content LIKE ?)
               ORDER BY in_title DESC, p.published_at DESC, p.id DESC
                  LIMIT " . self::LIMIT
            );
            // THE EXCERPT IS SEARCHED TOO (migration 053): it is the owner's own
            // summary, and a word that appears only there is a result that would
            // otherwise be missing.
            $posts->execute(array_merge([$like], array_keys($types), [$like, $like, $like]));
            foreach ($posts->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['meta'] = Meta::getAll((int) $row['id']);
                $out[] = [
                    'kind'    => 'entry',
                    'row'     => $row,
                    'type'    => $types[(string) $row['post_type']] ?? null,
                    'inTitle' => (bool) $row['in_title'],
                    'date'    => (string) ($row['published_at'] ?? $row['updated_at']),
                ];
            }
        }

        usort($out, static fn (array $x, array $y): int =>
            [$y['inTitle'], $y['date']] <=> [$x['inTitle'], $x['date']]);

        return array_slice($out, 0, self::LIMIT);
    }
}
