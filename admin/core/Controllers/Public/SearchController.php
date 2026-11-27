<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Helpers\Template;
use Cms\Helpers\Words;
use Cms\Http\Cache;
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
 * ── THE WORDS ON THE PAGE, NOT ITS MARKUP ──────────────────────────────────
 * A body is matched on what a visitor reads (`Helpers\Words`), with no regard
 * to capitals or accents. Matched on the HTML as stored, a search for "table"
 * or "strong" found every page holding that tag, and "free lunch" missed a
 * page where "lunch" was bold.
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

    /** How many rows are read at a time while a search finds its first LIMIT. */
    private const CHUNK = 200;

    public function index(array $vars = []): void
    {
        // The search page always exists, so its address's other ending always
        // 301s here (`Url::settle()`).
        \Cms\Helpers\Url::settle();

        // A STATIC COPY'S SEARCH (`Export\StaticCopy`) is this page with
        // nothing searched yet, and `search-copy.js`, which searches the
        // copy's index in the visitor's browser: every page and entry this
        // finds, each drawn as this page draws it, from `?index=1`.
        $copy = defined('CMS_EXPORT');
        if ($copy && ($_GET['index'] ?? '') === '1') {
            self::copyIndex();
            return;
        }

        $q       = $copy ? '' : mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $results = $q === '' ? [] : self::find($q);
        $capped  = count($results) >= self::LIMIT;

        Head::set([
            'title'     => $q === '' ? \Cms\Site\Words::of('search') : \Cms\Site\Words::of('search_title', ['q' => $q]),
            'canonical' => \Cms\Helpers\Url::search(),
            // A results page is not content, and indexing one invites every
            // query string on the internet into the search engine's copy.
            'robots'    => 'noindex, follow',
        ]);

        ob_start();
        require Template::locate('search.php');
        $pageContent = ob_get_clean();
        if ($copy) {
            $pageContent .= '<div class="search-copy" aria-live="polite" data-search-index="'
                . htmlspecialchars(\Cms\Helpers\Url::path('search/index.js')) . '" data-search-limit="' . self::LIMIT . '"></div>'
                . '<script src="' . htmlspecialchars(\Cms\Helpers\Asset::sitePath('assets/js/search-copy.js')) . '"></script>';
        }
        require Template::locate('base.php');
    }

    /**
     * A STATIC COPY'S SEARCH INDEX, as JSON: everything a search here can
     * find, with what it is matched on and how this page draws it.
     *
     *   t  the title, which a match in ranks first
     *   x  the rest of its words: an entry's excerpt and text, a page's text
     *   d  its date, which orders matches within a rank
     *   h  its result, drawn by `partials/post-card.php` or
     *      `partials/page-result.php` as the site draws it
     */
    private static function copyIndex(): void
    {
        $items = [];
        foreach (self::find(null) as $result) {
            $row = $result['row'];
            ob_start();
            if ($result['kind'] === 'entry' && is_array($result['type'])) {
                $p        = $row;
                $cardType = $result['type'];
                require Template::locate('partials/post-card.php');
                $text = (string) ($row['excerpt'] ?? '') . ' ' . (string) ($row['content'] ?? '');
            } else {
                require Template::locate('partials/page-result.php');
                $text = (string) ($row['content'] ?? '');
            }
            $items[] = [
                't' => self::squash((string) $row['title']),
                'x' => mb_substr(Words::of($text), 0, 20000),
                'd' => $result['date'],
                'h' => trim((string) ob_get_clean()),
            ];
        }
        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();
        echo json_encode(['items' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Pages and entries matching `$q`, title matches first, then newest, the
     * first LIMIT of them; every one there is, for a null `$q`.
     *
     * @return list<array{kind: string, row: array<string, mixed>, type: ?array<string, mixed>, inTitle: bool, date: string}>
     */
    private static function find(?string $q): array
    {
        // WHAT IS LOOKED FOR, in the form every title and text is compared in.
        $want = $q === null ? null : Words::fold(self::squash($q));
        if ($want === '') {
            return [];
        }
        // The database ranks by the title as stored, so a page arrives in the
        // order it is listed in. `%` and `_` typed are literal characters, not
        // wildcards: "50%" is a search for fifty per cent.
        $rank = $q === null ? [] : ['%' . addcslashes(self::squash($q), '%_\\') . '%'];
        $out  = [];

        // A PAGE'S SUMMARY IS SEARCHED TOO (migration 135), as an entry's excerpt is.
        [$narrow, $args] = self::narrowing($q, ['title', 'excerpt', 'content']);
        $pages = self::kept(
            "SELECT id, title, slug, lang, content, excerpt, featured_image_id, updated_at
               FROM pages
              WHERE " . \Cms\Models\Page::live() . $narrow . "
           ORDER BY " . ($q === null ? '' : '(title LIKE ?) DESC, ') . 'updated_at DESC, id DESC',
            array_merge($args, $rank),
            $want,
            static fn (array $row): string => (string) ($row['excerpt'] ?? '') . ' ' . (string) $row['content']
        );
        foreach ($pages as [$row, $inTitle]) {
            $out[] = [
                'kind'    => 'page',
                'row'     => $row,
                'type'    => null,
                'inTitle' => $inTitle,
                'date'    => (string) $row['updated_at'],
            ];
        }

        // ONLY TYPES WITH PAGES ON THE SITE — the question `www/index.php`
        // asks to give a type its public routes (`Registry::hasPages()`).
        $types = [];
        foreach (Registry::withPages() as $key => $type) {
            $types[(string) $key] = $type;
        }

        if ($types !== []) {
            // THE EXCERPT IS SEARCHED TOO (migration 053): it is the owner's own
            // summary, and a word that appears only there is a result that would
            // otherwise be missing.
            [$narrow, $args] = self::narrowing($q, ['p.title', 'p.excerpt', 'p.content']);
            $posts = self::kept(
                "SELECT p.*
                   FROM posts p
                  WHERE " . \Cms\Models\Post::live('p') . "
                    AND p.post_type IN (" . implode(',', array_fill(0, count($types), '?')) . ")" . $narrow . "
               ORDER BY " . ($q === null ? '' : '(p.title LIKE ?) DESC, ') . 'p.published_at DESC, p.id DESC',
                array_merge(array_map('strval', array_keys($types)), $args, $rank),
                $want,
                static fn (array $row): string => (string) ($row['excerpt'] ?? '') . ' ' . (string) ($row['content'] ?? '')
            );
            foreach ($posts as [$row, $inTitle]) {
                $row['meta'] = Meta::getAll((int) $row['id']);
                $out[] = [
                    'kind'    => 'entry',
                    'row'     => $row,
                    'type'    => $types[(string) $row['post_type']] ?? null,
                    'inTitle' => $inTitle,
                    'date'    => (string) ($row['published_at'] ?? $row['updated_at']),
                ];
            }
        }

        usort($out, static fn (array $x, array $y): int =>
            [$y['inTitle'], $y['date']] <=> [$x['inTitle'], $x['date']]);

        return $q === null ? $out : array_slice($out, 0, self::LIMIT);
    }

    /**
     * THE DATABASE NARROWS, `Words` DECIDES. A row is read only when every
     * word typed is somewhere in `$columns` as stored, which a row whose
     * words hold the search always is ("&", "<" and ">" are looked for as the
     * markup writes them, too). Nothing typed reaches the SQL but as a
     * parameter.
     *
     * @param list<string> $columns
     * @return array{0: string, 1: list<string>} " AND …" for the WHERE, and its parameters
     */
    private static function narrowing(?string $q, array $columns): array
    {
        if ($q === null) {
            return ['', []];
        }
        $sql  = '';
        $args = [];
        foreach (array_unique(preg_split('/ /', self::squash($q)) ?: []) as $word) {
            $any = [];
            foreach (array_unique([$word, htmlspecialchars($word, ENT_NOQUOTES | ENT_HTML5, 'UTF-8')]) as $form) {
                foreach ($columns as $column) {
                    $any[]  = $column . ' LIKE ?';
                    $args[] = '%' . addcslashes($form, '%_\\') . '%';
                }
            }
            // A word in the business's details is stored as the shortcode
            // that prints it ([phone]), so a row naming one is read too.
            foreach (\Cms\Site\Business::shortcodesFor($word) as $tag) {
                foreach ($columns as $column) {
                    $any[]  = $column . ' LIKE ?';
                    $args[] = '%' . addcslashes($tag, '%_\\') . '%';
                }
            }
            $sql .= ' AND (' . implode(' OR ', $any) . ')';
        }

        return [$sql, $args];
    }

    /**
     * The rows of `$sql` whose title or words hold `$want`, each with whether
     * its title does, in the query's order, the first LIMIT of them; every row,
     * for a null `$want`. Read CHUNK rows at a time, so a word in the markup
     * of a thousand pages costs reading them, not holding them.
     *
     * @param list<string>                         $args
     * @param \Closure(array<string, mixed>): string $text a row's text as stored
     * @return list<array{0: array<string, mixed>, 1: bool}>
     */
    private static function kept(string $sql, array $args, ?string $want, \Closure $text): array
    {
        $pdo = Database::getInstance();
        if ($want === null) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);

            return array_map(static fn (array $row): array => [$row, false], $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        $kept = [];
        for ($offset = 0; ; $offset += self::CHUNK) {
            $stmt = $pdo->prepare($sql . ' LIMIT ' . self::CHUNK . ' OFFSET ' . $offset);
            $stmt->execute($args);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $inTitle = str_contains(Words::fold(self::squash((string) $row['title'])), $want);
                if ($inTitle || str_contains(Words::fold(Words::of($text($row))), $want)) {
                    $kept[] = [$row, $inTitle];
                    if (count($kept) >= self::LIMIT) {
                        return $kept;
                    }
                }
            }
            if (count($rows) < self::CHUNK) {
                return $kept;
            }
        }
    }

    /** Spaces run together and the ends trimmed: "free  lunch" is "free lunch". */
    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
