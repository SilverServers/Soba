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

        // ── AN EVENTS LIST IS IN THE ORDER OF ITS DATES, ACROSS EVERY PAGE ──
        // What is still to come first, soonest first, then what is over,
        // latest first (`Content\Events`). Decided for the whole type before
        // the page is cut: sorted within one page, which page an event fell on
        // was decided by when it was published.
        $events = in_array('event', $archetypes, true) ? \Cms\Content\Events::ordered($this->contentTypeKey) : null;

        if ($events !== null) {
            $posts = self::rowsInOrder(array_column($limit > 0 ? array_slice($events, $offset, $limit) : $events, 'id'));
        } else {
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
        }

        // Archive cards render archetype fields (price, event date, job title), so
        // each row needs its meta — in ONE query for the page, since the page
        // size is a setting and can reach 100 (Meta::getAllFor()).
        $metaById = \Cms\Models\Meta::getAllFor(array_map(static fn (array $r): int => (int) $r['id'], $posts));
        foreach ($posts as &$row) {
            $row['meta'] = $metaById[(int) $row['id']] ?? [];
        }
        unset($row);

        // ── A SHOP'S LIST: EVERY CARD PRICED IN ONE MORE QUERY ───────────────
        // Each card asks `Commerce\Product` what its product costs, which reads
        // its options; primed here, a page of cards reads them once.
        $shopTools = null;
        $shopQuery = '';
        if (\Cms\Commerce\Product::isSellableType($this->contentTypeKey)) {
            \Cms\Commerce\Variation::prime(array_map(static fn (array $r): int => (int) $r['id'], $posts));
            // NO SORT OR FILTER IN A STATIC COPY (`Export\StaticCopy`): each is
            // a question in the address, which a static host does not read.
            $shopTools = defined('CMS_EXPORT') ? null : $this->shopTools();

            // ── SORTED OR FILTERED: THE WHOLE TYPE, THEN THE PAGE ────────────
            // What a product costs NOW (its sale, its dates, its options) and
            // whether a size is still to be had are decided in PHP, by the same
            // door the product page uses, not in SQL over meta strings. So a
            // sorted or filtered list reads every live product of the type,
            // keeps and orders them here, and cuts the same page window the
            // SQL would have. A shop's catalogue is hundreds, not millions.
            if ($shopTools !== null && $shopTools['active']) {
                $shopQuery = $shopTools['query'];
                [$posts, $total] = $this->shopList($shopTools, $order, $limit, $offset,
                    $events !== null ? array_flip(array_column($events, 'id')) : null);
                $totalPages = Paging::pages($total, $perPage, $extra);
                if ($page > $totalPages) {
                    Template::notFound($this->contentTypeKey);
                    return;
                }
            }
        }

        // WHERE THE PAST BEGINS ON THIS PAGE, for its heading: the first event
        // that is over, when every one after it is over too. A list a shop has
        // sorted by price mixes them, and gets no heading.
        $pastFrom = null;
        if ($events !== null) {
            $over  = array_column($events, 'past', 'id');
            $flags = array_map(static fn (array $p): bool => $over[(int) $p['id']] ?? false, $posts);
            $first = array_search(true, $flags, true);
            if ($first !== false && !in_array(false, array_slice($flags, (int) $first), true)) {
                $pastFrom = (int) $first;
            }
        }

        $navPages  = [];
        $pageTitle = \Cms\Site\Words::typeLabel($contentType);

        // SEO
        $ctLabel    = \Cms\Site\Words::typeLabel($contentType);
        $publicSlug = $contentType['public_slug'] ?? $this->contentTypeKey;

        $tokens = Head::globalTokens();
        $tokens['content_type_label'] = $ctLabel;
        if ($page > 1) {
            $tokens['page_num'] = \Cms\Site\Words::of('page_n', ['n' => $page]);
        }
        $title = Head::buildTitle(Head::titlePattern('archive'), $tokens);

        // PAGE 1'S ADDRESS, and every page's from it. Each page names itself,
        // and its neighbours as `rel="prev"`/`"next"` — canonicalising page 2
        // to page 1 would tell a search engine the later pages are duplicates
        // of the first. The template's pager reads the same `$pageBase`, so its
        // links and these cannot disagree.
        $pageBase = \Cms\Helpers\Url::archive($publicSlug);
        $paged    = Paging::head($pageBase, $page, $totalPages);

        // A SORTED OR FILTERED VIEW IS THE SAME LIST, to a search engine: its
        // canonical is the list itself and it names no neighbours, so the
        // shop is not indexed once per size and colour. Its own pager keeps
        // the choice — `Paging::url()` adds `&page=` to an address with a query.
        if ($shopQuery !== '') {
            $paged    = ['canonical' => $pageBase, 'prev' => null, 'next' => null];
            $pageBase = $pageBase . $shopQuery;
        }

        $breadcrumbItems = [
            ['label' => \Cms\Site\Words::of('home'), 'url' => \Cms\Helpers\Url::home()],
            ['label' => $ctLabel,  'url' => $pageBase],
        ];
        Breadcrumbs::set($breadcrumbItems);

        $graph   = Schema::siteWide();
        $graph[] = Schema::breadcrumbList($breadcrumbItems);

        Head::set([
            'title'       => $title,
            'canonical'   => $paged['canonical'],
            // A translated type's archive is in both languages; a later page, in its own.
            'alternates'  => $page === 1 && \Cms\Content\Translation::translates((string) $this->contentTypeKey)
                ? \Cms\Site\Language::versions(static fn (string $lang): string => \Cms\Helpers\Url::archive((string) $publicSlug, $lang)) : [],
            'prev'        => $paged['prev'],
            'next'        => $paged['next'],
            'og_type'     => 'website',
            'schema_json' => Schema::encode($graph),
            'feeds'       => [$this->contentTypeKey],
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
                'pastFrom'       => $pastFrom,
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

    /** How a shop's list can be ordered, and the word for each. '' is the owner's own order. */
    private const SORTS = [
        ''           => 'Featured',
        'price'      => 'Price, low to high',
        'price-desc' => 'Price, high to low',
        'newest'     => 'Newest',
    ];

    /**
     * The sort and the size and colour filters a shop's list offers, with what
     * this request chose. A filter is one of the type's option taxonomies
     * (`Variation::optionTaxonomies()`), named by its key in the address and
     * by a term's slug as its value — `?size=m&colour=navy` — and anything else
     * in the address is ignored rather than trusted.
     *
     * @return array{active: bool, query: string, sort: string, sorts: array<string, string>,
     *               filters: array<string, array{label: string, terms: array<string, string>, current: string, id: int}>}
     */
    private function shopTools(): array
    {
        $sort = is_string($_GET['sort'] ?? null) && isset(self::SORTS[$_GET['sort']]) ? $_GET['sort'] : '';
        $query = $sort !== '' ? ['sort' => $sort] : [];

        $filters = [];
        foreach (\Cms\Commerce\Variation::optionTaxonomies($this->contentTypeKey) as $taxKey => $tax) {
            $terms = [];
            $ids   = [];
            foreach (\Cms\Models\Term::allForTaxonomy((string) $taxKey) as $t) {
                $terms[(string) $t['slug']] = (string) $t['name'];
                $ids[(string) $t['slug']]   = (int) $t['id'];
            }
            $asked   = is_string($_GET[$taxKey] ?? null) ? (string) $_GET[$taxKey] : '';
            $current = isset($terms[$asked]) ? $asked : '';
            if ($current !== '') {
                $query[(string) $taxKey] = $current;
            }
            $filters[(string) $taxKey] = [
                'label'   => (string) ($tax['label_single'] ?? $tax['label'] ?? $taxKey),
                'terms'   => $terms,
                'current' => $current,
                'id'      => $current !== '' ? $ids[$current] : 0,
            ];
        }

        return [
            'active'  => $query !== [],
            'query'   => $query !== [] ? '?' . http_build_query($query) : '',
            'sort'    => $sort,
            'sorts'   => self::SORTS,
            'filters' => $filters,
        ];
    }

    /**
     * The type's live products, filtered and ordered as asked, cut to one page.
     *
     * A filter keeps a product with an option it can still sell in that size
     * AND that colour — one option matching both, so "M" and "Navy" do not
     * keep a tee whose only medium is Oat. A product not sold in options has
     * no size to match, and a filter leaves it out.
     *
     * @return array{0: list<array<string, mixed>>, 1: int} the page's rows, and how many in all
     */
    private function shopList(array $tools, string $order, int $limit, int $offset, ?array $rank = null): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT p.*, ' . \Cms\Models\User::bylineSql('u') . ' AS author_name
             FROM posts p
             JOIN users u ON u.id = p.author_id
             WHERE p.post_type = :pt AND ' . \Cms\Models\Post::live('p') . '
             ORDER BY ' . $order
        );
        $stmt->execute([':pt' => $this->contentTypeKey]);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ids  = array_map(static fn (array $r): int => (int) $r['id'], $all);
        $meta = \Cms\Models\Meta::getAllFor($ids);
        \Cms\Commerce\Variation::prime($ids);

        $want = array_filter(array_map(static fn (array $f): int => $f['id'], $tools['filters']));
        $kept = [];
        foreach ($all as $position => $row) {
            $row['meta'] = $meta[(int) $row['id']] ?? [];
            $product = \Cms\Commerce\Product::fromPost($row, null, $row['meta']);
            if ($product === null) {
                continue;
            }
            if ($want !== [] && !self::hasOption($product, $want)) {
                continue;
            }
            $kept[] = [
                'row'      => $row,
                // An events list's own order, where it is one (`Content\Events`).
                'position' => $rank !== null ? ($rank[(int) $row['id']] ?? PHP_INT_MAX) : $position,
                'price'    => \Cms\Commerce\Variation::bounds($product['variations'], $product)['low'],
                'date'     => (string) ($row['published_at'] ?? ''),
            ];
        }

        // THE OWNER'S ORDER BREAKS EVERY TIE, so two products at one price
        // keep the places the owner gave them, page after page.
        $by = $tools['sort'];
        usort($kept, static function (array $a, array $b) use ($by): int {
            $cmp = match ($by) {
                'price'      => $a['price'] <=> $b['price'],
                'price-desc' => $b['price'] <=> $a['price'],
                'newest'     => strcmp($b['date'], $a['date']),
                default      => 0,
            };
            return $cmp !== 0 ? $cmp : $a['position'] <=> $b['position'];
        });

        $page = $limit > 0 ? array_slice($kept, $offset, $limit) : $kept;

        return [array_map(static fn (array $k): array => $k['row'], $page), count($kept)];
    }

    /**
     * These entries, live, with their author's byline, in the order given.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function rowsInOrder(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT p.*, ' . \Cms\Models\User::bylineSql('u') . ' AS author_name
             FROM posts p
             JOIN users u ON u.id = p.author_id
             WHERE p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);
        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byId[(int) $row['id']] = $row;
        }

        return array_values(array_filter(array_map(static fn (int $id): ?array => $byId[$id] ?? null, $ids)));
    }

    /**
     * Does this product have an option it can still sell, made of every one of
     * these terms?
     *
     * @param array<string, int> $want taxonomy key => term id
     */
    private static function hasOption(array $product, array $want): bool
    {
        foreach ((array) $product['variations'] as $v) {
            if (!\Cms\Commerce\Variation::isPurchasable($v)) {
                continue;
            }
            $terms = \Cms\Commerce\Variation::termsOf($v);
            $all   = true;
            foreach ($want as $tax => $term) {
                if (($terms[$tax] ?? 0) !== $term) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }
}
