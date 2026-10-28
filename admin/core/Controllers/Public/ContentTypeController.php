<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Helpers\Paging;
use Cms\Helpers\Template;
use Cms\Helpers\Theme;
use Cms\SEO\Head;
use Cms\SEO\Schema;
use Cms\SEO\Breadcrumbs;
use PDO;

/**
 * Handles public archive (listing) pages for registered Content Types.
 * One archive route is auto-generated per Content Type in index.php:
 *   GET /{public_slug}  →  ContentTypeController::archive
 */
class ContentTypeController
{
    private string $contentTypeKey;

    public function __construct(?string $contentTypeKey = null)
    {
        $this->contentTypeKey = $contentTypeKey ?? '';
    }

    public function archive(array $vars = []): void
    {
        $contentType = Registry::get($this->contentTypeKey);

        if ($contentType === null) {
            Template::notFound($this->contentTypeKey);
            return;
        }

        // HOW MANY TO A PAGE, AND WHICH PAGE — `Helpers\Paging`, the one rule
        // every public list reads, rather than a number of its own in each.
        //
        // …AND EACH TYPE ITS OWN NUMBER (migration 090): its own, or the site's;
        // 0 lists every entry on one page. Page 1 of the type's own archive can
        // carry a few more (firstPageExtra()) and page 2 carries on after them.
        // `Paging::window()` and `pages()` are the one rule for both numbers.
        $pdo     = Database::getInstance();
        $perPage = Registry::perPage($this->contentTypeKey);
        $extra   = $perPage > 0 ? Registry::firstPageExtra($this->contentTypeKey) : 0;
        $page    = Paging::current();
        [$limit, $offset] = Paging::window($page, $perPage, $extra);

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM posts WHERE post_type = :pt AND " . \Cms\Models\Post::live()
        );
        $countStmt->execute([':pt' => $this->contentTypeKey]);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = Paging::pages($total, $perPage, $extra);

        // PAST THE LAST PAGE IS NOT A PAGE: a 404, not an empty list at an
        // address that exists only because someone typed a bigger number.
        if ($page > $totalPages) {
            Template::notFound($this->contentTypeKey);
            return;
        }

        // The page exists — so the other ending of its address 301s here now, and
        // not before: a 404 stays a 404 (`Url::settle()`).
        \Cms\Helpers\Url::settle();

        // ── WHAT ORDER A LIST IS IN, AND WHO DECIDES ────────────────────────
        //
        // A feed is chronological; a roster and a price list are not. Nobody
        // wants the board listed by the day their profile was written, and no
        // owner would accept "the cheapest plan is last because I typed it
        // first". Those are HAND-ORDERED, by the column that exists for it:
        // `posts.menu_order`, added by migration 030. Without it, an owner who
        // sees two cards in the wrong order has no way to swap them short of
        // editing a template.
        //
        // Archetype decides, not the type key, so a `listing` registered next
        // year is hand-ordered without touching this file.
        $archetypes  = $contentType['archetypes'] ?? [];
        $handOrdered = \Cms\ContentType\ArchetypeDefaults::isHandOrdered($archetypes);

        // `published_at` stays as the tiebreaker rather than `id`: two entries
        // at the same position should read newest-first, which is what every
        // other list here does.
        //
        // …AND `id` BREAKS THE LAST TIE. Entries published on the same second —
        // an import's whole batch, typically — would have no order at all, and
        // MySQL may return a tie differently for each LIMIT/OFFSET, so page 2
        // could repeat an entry from page 1 and skip another. Newest id first,
        // as the term archive does.
        // Two literals this file wrote, chosen by a boolean — nothing from the
        // request reaches the SQL. Anything a visitor supplies reaches SQL only
        // as a bound parameter, never interpolated.
        $order = $handOrdered
            ? 'p.menu_order ASC, p.published_at DESC, p.id DESC'
            : 'p.published_at DESC, p.id DESC';

        $stmt = $pdo->prepare(
            'SELECT p.*, ' . \Cms\Models\User::bylineSql('u') . ' AS author_name
             FROM posts p
             JOIN users u ON u.id = p.author_id
             WHERE p.post_type = :pt AND ' . \Cms\Models\Post::live('p') . '
             ORDER BY ' . $order
             // No LIMIT at all for a type listed whole (0 to a page).
             . ($limit > 0 ? ' LIMIT :lim OFFSET :off' : '')
        );
        $stmt->bindValue(':pt',  $this->contentTypeKey, PDO::PARAM_STR);
        if ($limit > 0) {
            $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Archive cards render archetype fields (price, event date, job title), so
        // each row needs its meta — in ONE query for the page, since the page
        // size is a setting and can reach 100 (Meta::getAllFor()).
        $metaById = \Cms\Models\Meta::getAllFor(array_map(static fn (array $r): int => (int) $r['id'], $posts));
        foreach ($posts as &$row) {
            $row['meta'] = $metaById[(int) $row['id']] ?? [];
        }
        unset($row);

        // Event archives read chronologically, not by publish date — nobody wants
        // next week's webinar listed above tomorrow's. Sorted here rather than in
        // SQL because the date lives in post_meta, not a column.
        if (in_array('event', $contentType['archetypes'] ?? [], true)) {
            usort($posts, static function (array $a, array $b): int {
                $aDate = strtotime((string) ($a['meta']['event_start_date'] ?? '')) ?: PHP_INT_MAX;
                $bDate = strtotime((string) ($b['meta']['event_start_date'] ?? '')) ?: PHP_INT_MAX;
                return $aDate <=> $bDate;
            });
        }

        $navPages  = [];
        $pageTitle = $contentType['label'];

        // SEO
        $ctLabel    = $contentType['label'];
        $publicSlug = $contentType['public_slug'] ?? $this->contentTypeKey;

        $tokens = Head::globalTokens();
        $tokens['content_type_label'] = $ctLabel;
        if ($page > 1) {
            $tokens['page_num'] = 'Page ' . $page;
        }
        $title = Head::buildTitle(Head::titlePattern('archive'), $tokens);

        // PAGE 1'S ADDRESS, and every page's from it. Each page names itself,
        // and its neighbours as `rel="prev"`/`"next"` — canonicalising page 2
        // to page 1 would tell a search engine the later pages are duplicates
        // of the first. The template's pager reads the same `$pageBase`, so its
        // links and these cannot disagree.
        $pageBase = \Cms\Helpers\Url::page($publicSlug);
        $paged    = Paging::head($pageBase, $page, $totalPages);

        $breadcrumbItems = [
            ['label' => 'Home',    'url' => BASE_URL . '/'],
            ['label' => $ctLabel,  'url' => $pageBase],
        ];
        Breadcrumbs::set($breadcrumbItems);

        $graph   = Schema::siteWide();
        $graph[] = Schema::breadcrumbList($breadcrumbItems);

        Head::set([
            'title'       => $title,
            'canonical'   => $paged['canonical'],
            'prev'        => $paged['prev'],
            'next'        => $paged['next'],
            'og_type'     => 'website',
            'schema_json' => Schema::encode($graph),
        ]);

        // A WHOLE-PAGE THEME renders the entire page when it has a template
        // for it (`Helpers\Theme`), handed the rows and the SEO by the names
        // a site's own theme reads.
        $themed = Theme::template('archive', $this->contentTypeKey);
        if ($themed !== null) {
            Theme::render($themed, [
                'contentType'    => $contentType,
                'contentTypeKey' => $this->contentTypeKey,
                'posts'          => $posts,
                'page'           => $page,
                'perPage'        => $perPage,
                'firstPageExtra' => $extra,
                'total'          => $total,
                'totalPages'     => $totalPages,
                'title'          => $title,
                'canonical'      => $paged['canonical'],
                'prev'           => $paged['prev'],
                'next'           => $paged['next'],
                'schemaGraph'    => $graph,
                'breadcrumbs'    => $breadcrumbItems,
            ]);
            return;
        }

        ob_start();
        require Template::locate(...Template::forType('archive', $this->contentTypeKey));
        $pageContent = ob_get_clean();
        require Template::locate('base.php');
    }
}
