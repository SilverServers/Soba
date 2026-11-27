<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Database\Database;
use Cms\Helpers\Paging;
use Cms\Helpers\Template;
use Cms\Helpers\Theme;
use Cms\Models\Meta;
use Cms\Models\Term;
use Cms\SEO\Breadcrumbs;
use Cms\SEO\Head;
use Cms\SEO\Schema;
use PDO;

/**
 * EVERYTHING FILED UNDER ONE TERM — `/{taxonomy}/{term}`. Migration 048.
 *
 * ── THE THING A VISITOR WOULD ACTUALLY DO WITH A TAXONOMY ───────────────────
 * Taxonomies could be created, attached to types, given terms and assigned to
 * entries, and every route that touched them was under `/admin` or `/api`. So
 * "show me everything tagged Campus" — the one use a visitor has for a
 * classification — was not possible on the site at all.
 *
 * ── PUBLISHED ONLY, AND THAT IS NOT THE SAME RULE AS THE ADMIN'S ────────────
 * An entry list in the admin shows drafts because the person reading it wrote
 * them. This is a public archive: `private` means 404 for a visitor, so listing
 * one here would advertise a page that refuses to open — which is worse than
 * not listing it, because the visitor blames the site.
 */
class TermController
{
    public function __construct(private string $taxonomyKey)
    {
    }

    public function archive(array $vars = []): void
    {
        $taxonomy = TaxonomyRegistry::get($this->taxonomyKey);
        $slug     = (string) ($vars['term'] ?? '');

        if ($taxonomy === null || $slug === '') {
            $this->notFound();
            return;
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, name, slug, description
               FROM terms
              WHERE taxonomy_key = :tax AND slug = :slug
              LIMIT 1'
        );
        $stmt->execute([':tax' => $this->taxonomyKey, ':slug' => $slug]);
        $term = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($term === false) {
            // A TERM THAT DOES NOT EXIST IS A 404, not an empty archive. An
            // empty page at a made-up address is a page search engines index
            // and visitors bookmark.
            $this->notFound();
            return;
        }

        // `Helpers\Paging` — the rule every public list reads.
        //
        // ONE TYPE, ITS NUMBER (migration 090). A taxonomy attached to a single
        // Content Type pages at that type's size, so /articles and its
        // category pages page alike. One shared by several types has no one
        // type to ask, and takes the site's. Never the first-page extra: that
        // is the type archive's own.
        $types   = array_values((array) ($taxonomy['content_types'] ?? []));
        $perPage = count($types) === 1 ? Registry::perPage((string) $types[0]) : Paging::perPage();
        $page    = Paging::current();
        [$limit, $offset] = Paging::window($page, $perPage);

        // ── A PILLAR INCLUDES ITS CHILDREN ──────────────────────────────────
        // A category archive lists what is filed under the category's
        // children as well, and a site's parent categories are
        // built on that: an entry is filed at its most specific level, and the
        // parent is the pillar that gathers them. Listing only what is filed
        // under the term itself would leave a pillar whose entries sit one
        // level down an EMPTY page — which the `robots` rule below would then
        // `noindex`. A flat taxonomy has no children to include.
        $termIds = [(int) $term['id']];
        if (!empty($taxonomy['hierarchical'])) {
            $termIds = array_merge($termIds, Term::getDescendantIds((int) $term['id'], $this->taxonomyKey));
        }
        // AN ENTRY FILED UNDER THE PILLAR AND A CHILD IS ONE ENTRY. Matched with
        // `IN (…)` rather than joined, so it cannot come back once per term.
        $filed = 'p.id IN (SELECT ptr.post_id FROM post_term_relationships ptr WHERE ptr.term_id IN ('
               . implode(',', array_fill(0, count($termIds), '?')) . '))';
        // ONLY ENTRIES WITH PAGES OF THEIR OWN (`Registry::hasPages()`): a card
        // links to its entry's page, and an entry whose type has none is data
        // for the site's own templates, not a page to list.
        [$paged, $pagedTypes] = Registry::withPagesSql('p');
        $filed .= $paged;
        $params = array_merge($termIds, $pagedTypes);

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM posts p WHERE $filed AND " . \Cms\Models\Post::live('p')
        );
        $countStmt->execute($params);
        $total      = (int) $countStmt->fetchColumn();
        $totalPages = Paging::pages($total, $perPage);

        // PAST THE LAST PAGE IS A 404 — the type archive's rule. Page 1 of an
        // empty term still answers (and is `noindex`, below): the term exists;
        // page 9 of it does not.
        if ($page > $totalPages) {
            $this->notFound();
            return;
        }

        // The page exists — so the other ending of its address 301s here now, and
        // not before: a 404 stays a 404 (`Url::settle()`).
        \Cms\Helpers\Url::settle();

        // LIMIT AND OFFSET ARE INTEGERS THIS METHOD PRODUCED, never bound —
        // MySQL will not take a placeholder for either under every driver
        // setting, and these two are `(int)` cast from arithmetic above.
        $stmt = $pdo->prepare(
            "SELECT p.*, " . \Cms\Models\User::bylineSql('u') . " AS author_name
               FROM posts p
               JOIN users u ON u.id = p.author_id
              WHERE $filed AND " . \Cms\Models\Post::live('p') . "
           ORDER BY p.published_at DESC, p.id DESC"
            // No LIMIT for a type listed whole (0 to a page).
            . ($limit > 0 ? " LIMIT " . (int) $limit . " OFFSET " . (int) $offset : "")
        );
        $stmt->execute($params);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // THE ARCHETYPE FIELDS COME WITH THE ROWS, because the card that renders
        // a listing entry needs its photo and its role — the same thing
        // `ContentTypeController::archive()` loads and for the same reason. A
        // field the admin offers and no template renders is a box the owner
        // types into that changes nothing on their site.
        // One query for the page's meta (Meta::getAllFor()), since the page
        // size is a setting.
        $metaById = Meta::getAllFor(array_map(static fn (array $r): int => (int) $r['id'], $posts));
        foreach ($posts as &$row) {
            $row['meta'] = $metaById[(int) $row['id']] ?? [];
            $row['content_type'] = Registry::get((string) $row['post_type']);
        }
        unset($row);

        // ── ONLY THE KEYS `Head` ACTUALLY READS ─────────────────────────────
        // A key `Head` does not know, such as `noindex`, is silently ignored.
        // Its own docblock lists what it takes; `robots` is the key for that
        // job.
        //
        // PAGE 2 CANONICALISES TO ITSELF, not to page one: they hold different
        // entries, and pointing them at the same address tells a search engine
        // the later pages are duplicates of the first. `Helpers\Paging` builds
        // it — and `rel="prev"`/`"next"` — from page 1's address, which the
        // template's pager reads too.
        $pageBase = \Cms\Helpers\Url::term($term, (string) $taxonomy['public_slug']);
        $paged    = Paging::head($pageBase, $page, $totalPages);

        $breadcrumbItems = [
            ['label' => \Cms\Site\Words::of('home'), 'url' => \Cms\Helpers\Url::home()],
            ['label' => \Cms\Site\Words::taxonomyLabel($taxonomy), 'url' => $pageBase],
            ['label' => (string) $term['name'], 'url' => $pageBase],
        ];
        Breadcrumbs::set($breadcrumbItems);

        $graph   = Schema::siteWide();
        $graph[] = Schema::breadcrumbList($breadcrumbItems);

        // A TITLE FROM A PATTERN, like every other page, not the term's name
        // alone — which would carry no site name, and give page 2 the same
        // title as page 1. `{term_name}`, `{taxonomy_label}` and `{page_num}`
        // are what the SEO screen's Term archives pattern offers.
        $tokens = Head::globalTokens();
        $tokens['term_name']      = (string) $term['name'];
        $tokens['taxonomy_label'] = \Cms\Site\Words::taxonomyLabel($taxonomy);
        if ($page > 1) {
            $tokens['page_num'] = \Cms\Site\Words::of('page_n', ['n' => $page]);
        }

        // Edit this term, for a signed-in editor (`Site\EditBar`).
        \Cms\Site\EditBar::forTerm($term + ['taxonomy_key' => $this->taxonomyKey]);

        Head::set([
            'title'       => Head::buildTitle(Head::titlePattern('term'), $tokens),
            'description' => (string) ($term['description'] ?? ''),
            'canonical'   => $paged['canonical'],
            // A translated taxonomy's term page is in both languages, when it
            // lists something in this one; a later page, in its own.
            'alternates'  => $page === 1 && $total > 0 && \Cms\Content\Translation::taxonomyTranslates((string) $taxonomy['key'])
                ? \Cms\Site\Language::versions(static fn (string $lang): string => \Cms\Helpers\Url::term($term, (string) $taxonomy['public_slug'], $lang)) : [],
            'prev'        => $paged['prev'],
            'next'        => $paged['next'],
            'og_type'     => 'website',
            // AN EMPTY ARCHIVE IS NOT WORTH INDEXING. A term nobody has used
            // yet is a page with a heading and nothing under it, and a site
            // that ships fifty of those is fifty thin pages in an index.
            'robots'      => $total === 0 ? 'noindex, follow' : '',
            'schema_json' => Schema::encode($graph),
            'feeds'       => array_values((array) ($taxonomy['content_types'] ?? [])),
        ]);

        // ── THE TEMPLATE IS THE PAGE'S BODY, NOT THE PAGE ───────────────────
        // Required directly, it would render with no header, no footer and no
        // stylesheet — correct content in a browser's default styles. Like
        // every other public controller, this one buffers its body and hands
        // it to `base.php`.
        // A WHOLE-PAGE THEME renders the entire page when it has a template
        // for it (`Helpers\Theme`), handed the rows and the SEO by the names
        // a site's own theme reads — and the title and description,
        // which a new theme should not have to recompute.
        $themed = Theme::template('term', $this->taxonomyKey);
        if ($themed !== null) {
            Theme::render($themed, [
                'taxonomy'    => $taxonomy,
                'taxonomyKey' => $this->taxonomyKey,
                'term'        => $term,
                'termIds'     => $termIds,
                'posts'       => $posts,
                'page'        => $page,
                'perPage'     => $perPage,
                'total'       => $total,
                'totalPages'  => $totalPages,
                'title'       => Head::buildTitle(Head::titlePattern('term'), $tokens),
                'description' => (string) ($term['description'] ?? ''),
                'canonical'   => $paged['canonical'],
                'prev'        => $paged['prev'],
                'next'        => $paged['next'],
                'schemaGraph' => $graph,
                'breadcrumbs' => $breadcrumbItems,
            ]);
            return;
        }

        ob_start();
        require Template::locate(...Template::forType('term', $this->taxonomyKey));
        $pageContent = ob_get_clean();
        require Template::locate('base.php');
    }

    private function notFound(): void
    {
        Template::notFound();
    }
}
