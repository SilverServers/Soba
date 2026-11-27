<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\ContentType\ArchetypeDefaults;
use Cms\ContentType\Children;
use Cms\ContentType\CopyRules;
use Cms\ContentType\ListColumns;
use Cms\ContentType\Patterns;
use Cms\ContentType\ContentTypeSchema;
use Cms\ContentType\FieldRenderer;
use Cms\ContentType\Registry;
use Cms\Forms\Logic;
use Cms\Helpers\Dates;
use Cms\Helpers\Words;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Models\Meta;
use Cms\Models\Post;
use Cms\Models\Revision;
use Cms\Models\Term;
use Cms\Models\User;
use Cms\Helpers\Url;
use Cms\SEO\Head;
use Cms\Ui\Status;

/**
 * Generic Content Type controller — handles list, create, edit, update, and delete
 * for any registered Content Type.
 *
 * Receives the content type key via its constructor, which is injected
 * by the index.php dispatcher from the 3-element handler array:
 *   [ContentTypeController::class, 'index', 'event']
 */
class ContentTypeController
{
    protected array $contentType;

    /** @var array<string, array<string, mixed>> Taxonomies attached to this Content Type, keyed by taxonomy key */
    protected array $taxonomies = [];

    /** The title its pattern made for the save being checked, when it was left blank (`validateAndSanitize()`). */
    private string $madeTitle = '';

    public function __construct(?string $contentTypeKey = null)
    {
        if ($contentTypeKey !== null) {
            // Code-registered fields merged with active DB field groups.
            // See ContentTypeSchema — deliberately shared, so the admin UI and any
            // programmatic consumer never disagree about a Content Type's shape.
            $contentType = ContentTypeSchema::resolve($contentTypeKey);
            if ($contentType === null) {
                http_response_code(404);
                exit("Content Type '{$contentTypeKey}' is not registered.");
            }

            $this->contentType = $contentType;

            // Load taxonomies attached to this Content Type for sidebar panel injection
            $this->taxonomies = TaxonomyRegistry::getForContentType($contentTypeKey);
        }
    }

    // ----------------------------------------------------------------
    // Template path hooks — override in subclasses for custom templates
    // ----------------------------------------------------------------

    protected function listTemplatePath(): string
    {
        return ROOT_PATH . '/admin/templates/content-type/list.php';
    }

    protected function editTemplatePath(): string
    {
        return ROOT_PATH . '/admin/templates/content-type/edit.php';
    }

    // ----------------------------------------------------------------
    // List
    // ----------------------------------------------------------------

    public function index(array $vars = []): void
    {
        Auth::requireAuth();
        $user        = Auth::user();
        $contentType = $this->contentType;

        // Authors see only their own posts; editors and admins see all.
        $authorFilter = Auth::hasRole('editor') ? null : (int) $user['id'];

        // ── THE LIST'S QUESTION, ASKED OF THE DATABASE ──────────────────────
        // The database filters the list, not the browser. `listView()` reads
        // what this request asks for and checks each part against what can
        // exist; `adminList()` answers it one page at a time. Unsorted, it
        // keeps the public archive's order — a roster the owner arranged reads
        // the same on both screens.
        $view  = $this->listView($authorFilter);

        // AN EVENT TYPE'S UPCOMING AND PAST (`ListColumns::isEvent()`): its ids
        // under the list's filters, in its public list's order, and how many
        // each holds for its tab. With no sort chosen, the view's own order.
        $when       = $view['when'];
        $whenCounts = null;
        if ($when !== '') {
            $groups     = Post::eventGroups($view['query']);
            $whenCounts = ['upcoming' => count($groups['upcoming']), 'past' => count($groups['past'])];
            if ($when !== 'all') {
                $view['query']['ids']         = $groups[$when];
                $view['query']['ids_ordered'] = $view['query']['orderby'] === '';
            }
        }
        // GROUPED BY A CHOICE FIELD OR A TAXONOMY (`ContentType\StaffView::
        // groupBy()`): every entry under the list's filters, in its own order,
        // put into the group's, each value's together in the values' order, so
        // a group runs on from one page to the next.
        $group   = \Cms\ContentType\StaffView::groupBy((string) $contentType['key']);
        $groupOf = [];
        if ($group !== null) {
            $every   = Post::adminList(['page' => 1, 'per_page' => 1000000] + $view['query']);
            $grouped = \Cms\ContentType\StaffView::grouped($group, array_map(static fn (array $r): int => (int) $r['id'], $every['rows']));
            $view['query']['ids']         = $grouped['ids'];
            $view['query']['ids_ordered'] = true;
            $groupOf                      = $grouped['of'];
        }
        $found = Post::adminList($view['query']);

        // A PAGE PAST THE END shows the last page rather than an empty list
        // under a pager that says "Page 9 of 3" — what a bookmark to page 9
        // meets after a bulk trash.
        $perPage    = $view['query']['per_page'];
        $totalPages = max(1, (int) ceil($found['total'] / $perPage));
        if ($view['query']['page'] > $totalPages) {
            $view['query']['page'] = $totalPages;
            $found = Post::adminList($view['query']);
        }

        $posts     = $found['rows'];
        $total     = $found['total'];
        $page      = $view['query']['page'];
        $filters   = $view['filters'];
        $sort      = $view['sort'];
        $filtering = $view['filtering'];
        $listUrl   = $view['url'];
        // "3 of 40" needs the 40 — the same list with no filter, and an
        // author's own only, since those are all an author can see.
        $sorted    = $view['sorted'];
        $allCount  = $filtering || ($when !== '' && $when !== 'all') ? Post::countOfType($contentType['key'], $authorFilter) : $total;

        // WHAT THE FILTER BAR OFFERS: only months and people that would return
        // something, and each attached taxonomy's own tree.
        $months       = Post::listMonths($contentType['key'], $authorFilter);
        $listAuthors  = $authorFilter === null && \Cms\ContentType\StaffView::listPart((string) $contentType['key'], 'author_filter')
            ? Post::authorsOf($contentType['key']) : [];
        $cptTaxonomies = $this->taxonomies;
        $termTrees    = [];
        foreach ($this->taxonomies as $taxKey => $tax) {
            $termTrees[$taxKey] = Term::treeForTaxonomy((string) $taxKey);
        }

        // THE TERMS EACH ROW IS FILED UNDER — one query for the page, never one
        // per row. The row prints them, and bulk edit reads them to know which
        // boxes to show ticked across a selection.
        $rowTerms = Term::forPosts(
            array_map(static fn (array $r): int => (int) $r['id'], $posts),
            array_keys($this->taxonomies)
        );

        // BULK EDIT'S AUTHOR CHOICE is the entry form's: whoever sees its Author
        // panel. And what else of the list this role sees (`StaffView`).
        $bulkAuthors = \Cms\ContentType\StaffView::panel((string) $contentType['key'], 'author') ? User::authors() : null;
        $showAddress = \Cms\ContentType\StaffView::listPart((string) $contentType['key'], 'address');
        $showBulk    = \Cms\ContentType\StaffView::listPart((string) $contentType['key'], 'bulk');

        // ── A PRODUCT'S LINE: what it sells for, its code and its stock ────
        // For a page of products, read in two queries rather than two a row.
        $isProductType = in_array('product', (array) ($contentType['archetypes'] ?? []), true);
        $productLines  = [];
        if ($isProductType && $posts !== []) {
            $ids   = array_map(static fn (array $r): int => (int) $r['id'], $posts);
            $meta  = \Cms\Models\Meta::getAllFor($ids);
            $opts  = \Cms\Commerce\Variation::forProducts($ids);
            $lowAt = \Cms\Commerce\Stock::lowAt();
            foreach ($posts as $row) {
                $product = \Cms\Commerce\Product::fromPost($row, $opts[(int) $row['id']] ?? [], $meta[(int) $row['id']] ?? []);
                if ($product !== null) {
                    $productLines[(int) $row['id']] = \Cms\Commerce\Product::listLine($product, $lowAt);
                }
            }
        }

        // WHAT THE LAST BULK ACTION DID, once — the media library's flash
        // pattern: set before the redirect, read and cleared here.
        $bulkReport = $_SESSION['entry_bulk_report'] ?? null;
        unset($_SESSION['entry_bulk_report']);
        if (!is_array($bulkReport) || ($bulkReport['type'] ?? '') !== $contentType['key']) {
            $bulkReport = null;
        }

        // The list header shows Trash with its count, so the link says how much
        // is in there rather than making you open it to find out. Provided here
        // rather than queried from the template: a template that issues its own
        // SQL is a precedent this codebase should not set.
        $trashedCount = Post::countTrashed($contentType['key'], $authorFilter);

        // ── WHOSE IS IT? THE ROW SAYS SO ────────────────────────────────────
        // Migration 047. Not an indent, the way `pages/list.php` shows a child:
        // a page's parent is another page, so it is in the same list and the
        // indent has something to sit under. A CROSS-TYPE parent never is — an
        // event's parent is a news item, which this list does not contain — so
        // an indent would point at whatever row happened to be above it.
        //
        // The row names its parent instead. That also survives the filter, which
        // hides rows one at a time and would strand a child under a heading it
        // had just removed.
        [$parentType, $parentOptions] = $this->parentContext(null);
        $parentTitles = $parentType === null ? [] : Post::titlesByIds(
            array_map(static fn (array $r): int => (int) ($r['parent_id'] ?? 0), $posts)
        );

        // WHAT THE LIST SHOWS BESIDE EACH TITLE (`ListColumns`), and each
        // row's cells; a taxonomy shown as a column is not repeated under the
        // title.
        $listColumns = ListColumns::forType((string) $contentType['key']);
        $columnCells = ListColumns::cells($listColumns, array_map(static fn (array $r): int => (int) $r['id'], $posts));

        // ── A HAND-ORDERED LIST, ARRANGED BY DRAG (`list-arrange.js`) ───────
        // In its own order, with no search, filter or sort, every entry on one
        // page, a child type's within one parent, and for someone who may
        // change every one of them: a grip on each row and Up one and Down one
        // among its actions. More rows than a page holds: the address that
        // brings them onto one (`$arrangeAll`).
        $arrange    = null;
        $arrangeAll = null;
        if (ArchetypeDefaults::isHandOrdered($contentType['archetypes'] ?? []) && $view['plain'] && $authorFilter === null
            && ($parentType === null || $filters['parent'] > 0) && $posts !== []) {
            if ($total > $perPage) {
                $arrangeAll = $listUrl(['per_page' => min(999, $total), 'page' => null]);
            } elseif (array_filter($posts, static fn (array $r): bool => !Auth::canChange($r)) === []) {
                $arrange = ['url' => Url::admin((string) $contentType['admin_slug'] . '/order'), 'parent' => (int) $filters['parent']];
            }
        }

        require $this->listTemplatePath();
    }

    // ----------------------------------------------------------------
    // Create
    // ----------------------------------------------------------------

    public function showCreate(array $vars = []): void
    {
        Auth::requireAuth();
        $user            = Auth::user();
        $contentType     = $this->contentType;
        $post            = null;
        $meta            = [];
        $errors          = [];
        $cptTaxonomies   = $this->taxonomies;
        $assignedTerms   = []; // empty on create — no post ID yet
        $featuredImageId = 0;
        $seoTitle        = null;
        $metaDescription = null;
        [$parentType, $parentOptions, $parentId] =
            $this->parentContext($post ?? null);
        $authors = self::authorChoices($post ?? null, (string) $this->contentType['key']);
        require $this->editTemplatePath();
    }

    public function handleCreate(array $vars = []): void
    {
        Auth::requireAuth();
        $user        = Auth::user();
        $contentType = $this->contentType;
        // Every line break posted is a LF, as stored (`Words::lineFeeds()`).
        $_POST = Words::lineFeeds($_POST);

        [$errors, $meta] = $this->validateAndSanitize($_POST);

        if (!empty($errors)) {
            $post            = null;
            $cptTaxonomies   = $this->taxonomies;
            $assignedTerms   = [];
            $featuredImageId = (int) ($_POST['featured_image_id'] ?? 0);
            $seoTitle        = trim($_POST['seo_title'] ?? '') ?: null;
            $metaDescription = trim($_POST['meta_description'] ?? '') ?: null;
            [$parentType, $parentOptions, $parentId] =
            $this->parentContext($post ?? null);
            $authors = self::authorChoices($post ?? null, (string) $this->contentType['key']);
        require $this->editTemplatePath();
            return;
        }

        [$status, $passwordHash] = $this->resolveStatusAndPassword($_POST, null);
        // The date the owner chose, or none — and a live entry with none is
        // stamped by the model with the moment it went live. A PRIVATE entry
        // is no longer stamped just for being saved: that stamp became its
        // publish date weeks later, back-dating the article to its draft day.
        $publishedAt = self::publishedAtFrom(null);

        // Slug: typed by hand, kept as typed (a taken one was refused above);
        // made from the title as it was typed (`slug_auto`, the title card),
        // made unique here, -2 and on; or none, made from the title.
        // A TITLE LEFT BLANK IS ITS PATTERN'S, and an address not typed its
        // pattern's when the type has one (`ContentType\Patterns`).
        $rawSlug = self::sanitizeSlugInput($_POST['slug'] ?? '');
        $made    = ($_POST['slug_auto'] ?? '') === '1';
        $title   = $this->titleFrom($_POST);
        $slug    = $rawSlug !== '' && !$made ? $rawSlug
            : Post::generateSlug($this->slugMade($meta, $_POST, null, $title) ?? ($rawSlug !== '' ? $rawSlug : $title));

        $id = Post::create([
            'post_type'         => $contentType['key'],
            'title'             => $title,
            'slug'              => $slug,
            'content'           => in_array('content', $contentType['supports'], true)
                                     ? ($_POST['content'] ?? '') : '',
            'status'            => $status,
            'password_hash'     => $passwordHash,
            'featured_image_id' => !empty($_POST['featured_image_id']) ? (int) $_POST['featured_image_id'] : null,
            'published_at'      => $publishedAt,
            'unpublish_at'      => self::unpublishAtFrom(null),
            // A new entry goes at the END of a hand-ordered list, not the front.
            // Defaulting to 0 would drop every new team member above the
            // president, which is the sort of thing an owner notices
            // immediately and cannot explain.
            //
            // An EMPTY box is "last", not zero. `??` alone would not catch it:
            // the input posts `''`, which is present, and `(int) ''` is 0 — so
            // leaving the field alone, which the placeholder invites, would have
            // done the exact opposite of what it says.
            'menu_order'        => trim((string) ($_POST['menu_order'] ?? '')) !== ''
                                     ? (int) $_POST['menu_order']
                                     : Post::nextPosition($contentType['key']),
            'author_id'         => $user['id'],
        ] + self::excerptFrom($contentType));
        $this->numberInPatterns($id, trim((string) ($_POST['title'] ?? '')) === '', !($rawSlug !== '' && !$made), $meta, $_POST, $title, '');

        self::saveParent($id, $contentType);
        self::saveAuthor($id, (string) $this->contentType['key']);

        Meta::saveFields($id, $contentType['fields'], $_POST);

        // Save SEO meta. The title box starts from the Entries pattern, so a
        // title still saying exactly that is stored as none (`Head::ownTitle()`)
        // and goes on following the pattern. Only where the panel is drawn: a
        // type whose entries have no pages has none (`Registry::hasPages()`),
        // and a role the type does not show it to (`StaffView`) leaves what it
        // holds as it is.
        if (Registry::hasPages((string) $this->contentType['key']) && \Cms\ContentType\StaffView::panel((string) $this->contentType['key'], 'seo')) {
            Meta::set($id, '_seo_title',        Head::ownTitle((string) ($_POST['seo_title'] ?? ''), 'content_type') ?: null);
            Meta::set($id, '_meta_description', trim($_POST['meta_description'] ?? '') ?: null);
            self::saveSearchAndSocial($id);
        }

        // Save taxonomy term assignments (sidebar panels)
        foreach ($this->taxonomies as $taxKey => $tax) {
            $rawIds  = $_POST['taxonomy_' . $taxKey] ?? [];
            $termIds = is_array($rawIds) ? array_map('intval', $rawIds) : [];
            Term::setPostTerms($id, $taxKey, $termIds, (string) $this->contentType['key']);
        }

        // ── AND ITS OPTIONS, ON THE FIRST SAVE ──────────────────────────────
        // The Options panel is on a new product's screen too, so "Make
        // options" can be pressed before the product exists; the rows are kept
        // here on the same terms as `handleUpdate()` keeps them — the panel's
        // marker says it was drawn, and an empty list then means none.
        if (!empty($_POST['variations_panel'])
            && in_array('product', (array) ($contentType['archetypes'] ?? []), true)) {
            $rows = $_POST['variations'] ?? [];
            \Cms\Commerce\Variation::sync($id, is_array($rows) ? $rows : []);
        }
        // A NEW PRODUCT'S FIRST COUNTS are the history's first lines.
        if (in_array('product', (array) ($contentType['archetypes'] ?? []), true)) {
            \Cms\Commerce\StockLog::edited([], \Cms\Commerce\Stock::countsOf($id), (string) (Auth::user()['name'] ?? Auth::user()['username'] ?? ''));
        }

        header('Location: ' . ADMIN_URL . '/' . $contentType['admin_slug'] . '/' . $id . '/edit');
        exit;
    }

    // ----------------------------------------------------------------
    // Edit
    // ----------------------------------------------------------------

    public function showEdit(array $vars = []): void
    {
        Auth::requireAuth();
        $user        = Auth::user();
        $contentType = $this->contentType;
        $post        = $this->entryFor($vars);

        if ($post === null) {
            return;
        }

        $meta            = Meta::getAll((int) $vars['id']);
        $errors          = [];
        $cptTaxonomies   = $this->taxonomies;
        $featuredImageId = (int) ($post['featured_image_id'] ?? 0);
        $seoTitle        = $meta['_seo_title']        ?? null;
        $metaDescription = $meta['_meta_description'] ?? null;

        // Load currently assigned terms per taxonomy (full rows for chip data-name attributes)
        $assignedTerms = [];
        foreach ($this->taxonomies as $taxKey => $tax) {
            $assignedTerms[$taxKey] = Term::getPostTerms((int) $vars['id'], $taxKey);
        }

        // Revision history for the sidebar card (newest first).
        $revisions = Revision::listFor(Revision::TYPE_POST, (int) $vars['id']);

        // Inject _post_id into taxonomy-type custom fields so FieldRenderer can read assignments
        foreach ($this->contentType['fields'] as &$field) {
            if (($field['type'] ?? '') === 'taxonomy') {
                $field['_post_id'] = $post['id'];
            }
        }
        unset($field);

        [$parentType, $parentOptions, $parentId] =
            $this->parentContext($post ?? null);
        $authors = self::authorChoices($post ?? null, (string) $this->contentType['key']);
        require $this->editTemplatePath();
    }

    public function handleUpdate(array $vars = []): void
    {
        Auth::requireAuth();
        $user        = Auth::user();
        $contentType = $this->contentType;
        $post        = $this->entryFor($vars);

        if ($post === null) {
            return;
        }

        // ── A SAVE THAT CHANGES NOTHING CHANGES NOTHING ─────────────────────
        // A browser posts a textarea's line breaks as CRLF whatever the box
        // held, so every line break posted is a LF first (`Words::lineFeeds()`);
        // the date boxes keep the stored second (`publishedAtFrom()`); and
        // after the save, an entry found exactly as it was keeps its "last
        // updated" and leaves no revision behind (`state()`).
        $_POST = Words::lineFeeds($_POST);

        [$errors, $sanitizedMeta] = $this->validateAndSanitize($_POST, (int) $vars['id'], (string) $post['lang']);

        if (!empty($errors)) {
            $meta            = Meta::getAll((int) $vars['id']);
            $cptTaxonomies   = $this->taxonomies;
            $assignedTerms   = [];
            foreach ($this->taxonomies as $taxKey => $tax) {
                $assignedTerms[$taxKey] = Term::getPostTerms((int) $vars['id'], $taxKey);
            }
            $featuredImageId = (int) ($_POST['featured_image_id'] ?? $post['featured_image_id'] ?? 0);
            $seoTitle        = trim($_POST['seo_title'] ?? '') ?: ($meta['_seo_title'] ?? null);
            $metaDescription = trim($_POST['meta_description'] ?? '') ?: ($meta['_meta_description'] ?? null);
            [$parentType, $parentOptions, $parentId] =
            $this->parentContext($post ?? null);
            $authors = self::authorChoices($post ?? null, (string) $this->contentType['key']);
        require $this->editTemplatePath();
            return;
        }

        [$status, $passwordHash] = $this->resolveStatusAndPassword($_POST, $post);
        $publishedAt = self::publishedAtFrom($post);

        // Slug: user-supplied or auto-generated from title.
        // An address emptied is made again, from its pattern when the type
        // has one, else from the title; a title left blank is its pattern's.
        $rawSlug = self::sanitizeSlugInput($_POST['slug'] ?? '');
        $title   = $this->titleFrom($_POST);
        $slug    = $rawSlug !== ''
            ? $rawSlug
            : Post::generateSlug($this->slugMade($sanitizedMeta, $_POST, (int) $vars['id'], $title) ?? $title, (int) $vars['id'], (string) $post['lang']);

        // Capture what is about to be overwritten, so the edit is reversible.
        // Pruned once the save is known to have changed something (below).
        $before   = $this->state((int) $vars['id']);
        $revision = Revision::snapshot(Revision::TYPE_POST, (int) $vars['id'], $user['id']);

        Post::update((int) $vars['id'], [
            'title'             => $title,
            'slug'              => $slug,
            'content'           => in_array('content', $contentType['supports'], true)
                                     ? ($_POST['content'] ?? '') : '',
            'status'            => $status,
            'password_hash'     => $passwordHash,
            'featured_image_id' => !empty($_POST['featured_image_id']) ? (int) $_POST['featured_image_id'] : null,
            'published_at'      => $publishedAt,
            'unpublish_at'      => self::unpublishAtFrom($post),
            // The Position control only renders for hand-ordered archetypes, so
            // on a feed there is nothing in POST — and `?? 0` would be right
            // there anyway, since a feed has no order to lose. Where the control
            // IS shown, a blank box keeps the stored position rather than
            // resetting it to 0.
            'menu_order'        => trim((string) ($_POST['menu_order'] ?? '')) !== ''
                                     ? (int) $_POST['menu_order']
                                     : (int) ($post['menu_order'] ?? 0),
        ] + self::excerptFrom($contentType));

        // ── THE OLD ADDRESS KEEPS WORKING ──────────────────────────────────
        // A live entry, renamed, leaves a 301 behind it (Redirects::moved()).
        // Without one, a renamed article 404s every link and every search
        // result that points at it — silently. Not for a private or scheduled
        // entry: nobody outside could have linked to it. Nor for an entry
        // whose type has no pages (`Registry::hasPages()`): it had no address.
        $publicSlug = (string) Registry::publicSlug((string) $this->contentType['key']);
        if ($publicSlug !== '' && $slug !== (string) $post['slug']
            && in_array((string) $post['status'], ['published', 'password'], true)
            && !Post::isScheduled($post)) {
            // Under its language's prefix, for a translation (/fr/news/…).
            $in = (string) $post['lang'] !== '' ? '/' . $post['lang'] : '';
            \Cms\SEO\Redirects::moved($in . '/' . $publicSlug . '/' . $post['slug'], $in . '/' . $publicSlug . '/' . $slug);
        }

        self::saveParent((int) $vars['id'], $contentType);
        self::saveAuthor((int) $vars['id'], (string) $this->contentType['key']);

        // COUNTS TYPED OVER ON THE PRODUCT are lines in its stock history:
        // read before the save and after it, and each that moved is one.
        // A TRANSLATION OF ONE THAT SELLS keeps no product field or option of
        // its own (`Content\Translation::sellsOriginal()`): its original's are sold.
        $sellsOriginal = \Cms\Content\Translation::sellsOriginal($post);
        $isProduct     = in_array('product', (array) ($contentType['archetypes'] ?? []), true) && !$sellsOriginal;
        $countsBefore  = $isProduct ? \Cms\Commerce\Stock::countsOf((int) $vars['id']) : [];
        Meta::saveFields((int) $vars['id'], $sellsOriginal ? self::withoutProductFields($contentType['fields']) : $contentType['fields'], $_POST);

        // ── AND THE OPTIONS THIS PRODUCT IS SOLD IN ────────────────────────
        // THE MARKER, NOT THE ROWS, IS WHAT SAYS THE PANEL WAS THERE. A form
        // whose every option row has been removed posts no `variations` key at
        // all — exactly like a form that never had the panel — and the two mean
        // opposite things.
        //
        // Keying on the rows would leave the variants in the database after an
        // owner deleted every one of them, on a save that reported success.
        // Keying on the marker, an empty list means empty and a missing panel
        // means leave it alone.
        if (!empty($_POST['variations_panel']) && $isProduct) {
            $rows = $_POST['variations'] ?? [];
            \Cms\Commerce\Variation::sync(
                (int) $vars['id'],
                is_array($rows) ? $rows : []
            );
        }
        if ($isProduct) {
            \Cms\Commerce\StockLog::edited($countsBefore, \Cms\Commerce\Stock::countsOf((int) $vars['id']), (string) (Auth::user()['name'] ?? Auth::user()['username'] ?? ''));
        }

        // Save SEO meta — a title still saying the pattern is stored as none;
        // only where the panel is drawn, as on create.
        if (Registry::hasPages((string) $this->contentType['key']) && \Cms\ContentType\StaffView::panel((string) $this->contentType['key'], 'seo')) {
            Meta::set((int) $vars['id'], '_seo_title',        Head::ownTitle((string) ($_POST['seo_title'] ?? ''), 'content_type') ?: null);
            Meta::set((int) $vars['id'], '_meta_description', trim($_POST['meta_description'] ?? '') ?: null);
            self::saveSearchAndSocial((int) $vars['id']);
        }

        // Save taxonomy term assignments (sidebar panels)
        foreach ($this->taxonomies as $taxKey => $tax) {
            $rawIds  = $_POST['taxonomy_' . $taxKey] ?? [];
            $termIds = is_array($rawIds) ? array_map('intval', $rawIds) : [];
            Term::setPostTerms((int) $vars['id'], $taxKey, $termIds, (string) $this->contentType['key']);
        }

        // NOTHING CHANGED: no version to go back to, and "last updated" stays
        // where it was. Something did: the history keeps its newest 25, and an
        // entry whose fields, terms or options changed while its row did not
        // is "updated now" all the same (the row's date moves only with it).
        $after = $this->state((int) $vars['id']);
        if ($after === $before) {
            if ($revision !== null) {
                Revision::forget($revision);
            }
        } else {
            Revision::prune(Revision::TYPE_POST, (int) $vars['id']);
            if ((Post::find((int) $vars['id'])['updated_at'] ?? null) === ($post['updated_at'] ?? null)) {
                Post::touch((int) $vars['id']);
            }
        }

        $post            = Post::find((int) $vars['id']);
        $meta            = Meta::getAll((int) $vars['id']);
        $errors          = [];
        $saved           = true;
        $cptTaxonomies   = $this->taxonomies;
        $featuredImageId = (int) ($post['featured_image_id'] ?? 0);
        $seoTitle        = $meta['_seo_title']        ?? null;
        $metaDescription = $meta['_meta_description'] ?? null;
        $assignedTerms   = [];
        foreach ($this->taxonomies as $taxKey => $tax) {
            $assignedTerms[$taxKey] = Term::getPostTerms((int) $vars['id'], $taxKey);
        }

        // Inject _post_id into taxonomy-type custom fields after save
        foreach ($this->contentType['fields'] as &$field) {
            if (($field['type'] ?? '') === 'taxonomy') {
                $field['_post_id'] = $post['id'];
            }
        }
        unset($field);

        [$parentType, $parentOptions, $parentId] =
            $this->parentContext($post ?? null);
        $authors = self::authorChoices($post ?? null, (string) $this->contentType['key']);
        require $this->editTemplatePath();
    }

    // ----------------------------------------------------------------
    // Trash / Restore / Permanent delete
    // ----------------------------------------------------------------

    public function handleTrash(array $vars = []): void
    {
        Auth::requireAuth();
        if ($this->entryFor($vars) === null) {
            return;
        }
        Post::trash((int) $vars['id']);
        header('Location: ' . ADMIN_URL . '/' . $this->contentType['admin_slug']);
        exit;
    }

    // ----------------------------------------------------------------
    // Its rows on its parent's editor (`ContentType\Children`)
    // ----------------------------------------------------------------

    /**
     * POST /admin/{type}/rows — a row added in place on its parent's editor:
     * a new entry of this type, belonging to the parent posted, made through
     * this type's own checks from what the row holds and nothing else, as one
     * from Add new is: its type's status for a new entry, its address made
     * from its title, and its place after the parent's last. A refusal is the
     * type's own sentences, which the row shows.
     */
    public function handleRowCreate(array $vars = []): void
    {
        Auth::requireAuth();
        $key        = (string) $this->contentType['key'];
        $columns    = Children::columnsOf($key);
        $parentType = Registry::parentOf($key);
        $parentId   = is_scalar($_POST['parent_id'] ?? null) ? (int) $_POST['parent_id'] : 0;
        $parent     = $parentId > 0 ? Post::find($parentId) : null;
        if ($columns === null || $parentType === null || $parent === null
            || (string) $parent['post_type'] !== (string) $parentType['key'] || (string) $parent['status'] === 'trash') {
            self::rowAnswer(404, ['error' => 'There is no such ' . mb_strtolower((string) ($parentType['label_single'] ?? 'entry')) . ' for this to belong to.']);
        }

        $_POST = Words::lineFeeds($_POST);
        $kept  = [];
        foreach ($this->contentType['fields'] as $field) {
            if (!self::isColumn($columns, (string) $field['key'])) {
                $kept[(string) $field['key']] = null;
            }
        }
        $data = $this->rowData($columns, null);
        [$errors, $sanitized] = $this->validateAndSanitize($data, null, (string) $parent['lang'], $kept);
        if ($errors !== []) {
            self::rowRefused($errors);
        }

        $title = $this->titleFrom($data);
        $id    = Post::create([
            'post_type'  => $key,
            'title'      => $title,
            'slug'       => Post::generateSlug($this->slugMade($sanitized, $data, null, $title) ?? $title, null, (string) $parent['lang']),
            'lang'       => (string) $parent['lang'],
            'content'    => '',
            'status'     => Registry::defaultStatus($key),
            'menu_order' => Post::nextPositionUnder($key, $parentId),
            'author_id'  => (int) Auth::user()['id'],
        ]);
        Post::setParent($id, $parentId);
        $this->numberInPatterns($id, trim((string) $data['title']) === '', true, $sanitized, $data, $title, (string) $parent['lang']);
        foreach ($columns as $column) {
            if (($column['type'] ?? '') !== 'title') {
                Meta::set($id, (string) $column['key'], $sanitized[(string) $column['key']] ?? null);
            }
        }

        self::rowAnswer(200, ['data' => $this->rowFacts($id)]);
    }

    /**
     * POST /admin/{type}/{id}/row — a row changed on its parent's editor,
     * saved through this type's own checks as its editor's Save is, with a
     * revision as that Save keeps one: what the row shows is posted, and every
     * other field is checked as it is kept, so the row never holds what this
     * type's own editor would refuse. Only the row's columns are written.
     */
    public function handleRowSave(array $vars = []): void
    {
        Auth::requireAuth();
        $post    = $this->rowEntry($vars);
        $id      = (int) $post['id'];
        $columns = Children::columnsOf((string) $this->contentType['key']) ?? [];
        if ($columns === []) {
            self::rowAnswer(404, ['error' => 'These are not shown on another entry\'s editor any more: change it on its own screen.']);
        }

        // A COLUMN IT DOES NOT POST stays as it is, as a field it does not show
        // does: a row's controls always post, so only a post made by hand
        // leaves one out.
        $_POST = Words::lineFeeds($_POST);
        $meta  = Meta::getAll($id);
        $kept  = [];
        foreach ($this->contentType['fields'] as $field) {
            $fieldKey = (string) $field['key'];
            if (!self::isColumn($columns, $fieldKey) || !array_key_exists('field_' . $fieldKey, $_POST)) {
                $kept[$fieldKey] = $meta[$fieldKey] ?? null;
            }
        }
        $data = $this->rowData($columns, $post);
        [$errors, $sanitized] = $this->validateAndSanitize($data, $id, (string) $post['lang'], $kept);
        if ($errors !== []) {
            self::rowRefused($errors);
        }

        // AS ITS EDITOR'S SAVE: the version before kept first, and forgotten
        // again when nothing changed.
        $before   = $this->state($id);
        $revision = Revision::snapshot(Revision::TYPE_POST, $id, (int) Auth::user()['id']);
        foreach ($columns as $column) {
            if (($column['type'] ?? '') === 'title') {
                if (array_key_exists('title', $_POST)) {
                    Post::update($id, ['title' => $this->titleFrom($data)]);
                }
            } elseif (!array_key_exists((string) $column['key'], $kept)) {
                Meta::set($id, (string) $column['key'], $sanitized[(string) $column['key']] ?? null);
            }
        }
        $after = $this->state($id);
        if ($after === $before) {
            if ($revision !== null) {
                Revision::forget($revision);
            }
        } else {
            Revision::prune(Revision::TYPE_POST, $id);
            if ((Post::find($id)['updated_at'] ?? null) === ($post['updated_at'] ?? null)) {
                Post::touch($id);
            }
        }

        self::rowAnswer(200, ['data' => $this->rowFacts($id)]);
    }

    /** POST /admin/{type}/{id}/row/trash — a row's Trash: to the trash, as the list's own Trash does. */
    public function handleRowTrash(array $vars = []): void
    {
        Auth::requireAuth();
        $post = $this->rowEntry($vars);
        Post::trash((int) $post['id']);
        self::rowAnswer(200, ['data' => ['id' => (int) $post['id']]]);
    }

    /**
     * POST /admin/{type}/order — this type's entries in the order arranged:
     * within one parent on its editor, or on the type's own list (`parent_id`
     * then names the parent); or a hand-ordered type's whole list, on its list
     * screen. `ids[]` is every one of them there, in their new order. The
     * whole order or none of it: a list that has changed since its screen was
     * drawn, or one holding an entry this person may not change, is refused
     * with a sentence.
     */
    public function handleOrder(array $vars = []): void
    {
        Auth::requireAuth();
        $ids = array_values(array_unique(array_filter(
            array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []),
            static fn (int $id): bool => $id > 0
        )));
        // ONE PARENT'S ENTRIES, or a hand-ordered type's whole list when it
        // belongs to no parent type (`list-arrange.js`).
        $parentId = is_scalar($_POST['parent_id'] ?? null) ? (int) $_POST['parent_id'] : 0;
        $key      = (string) $this->contentType['key'];
        $scope    = $parentId > 0 ? Post::childrenOfType($parentId, $key)
            : (Registry::parentOf($key) === null && ArchetypeDefaults::isHandOrdered($this->contentType['archetypes'] ?? []) ? Post::ofTypeInOrder($key) : []);

        $have = array_map(static fn (array $r): int => (int) $r['id'], $scope);
        $want = $ids;
        sort($have);
        sort($want);
        if ($scope === [] || $have !== $want) {
            self::rowAnswer(409, ['error' => 'These have changed since this screen was drawn. Reload it, then arrange them again.']);
        }
        foreach ($scope as $row) {
            if (!Auth::canChange($row)) {
                self::rowAnswer(403, ['error' => 'Only an editor can arrange these: “' . (string) $row['title'] . '” is someone else\'s.']);
            }
        }

        self::rowAnswer(200, ['data' => ['moved' => Post::setOrder($ids)]]);
    }

    /**
     * What a row posts, as this type's own save reads it: its title when it
     * shows one (else the entry's own), its columns' fields, and the entry's
     * terms as they are, since a row changes none.
     *
     * @param list<array<string, mixed>> $columns
     * @param array<string, mixed>|null  $post
     * @return array<string, mixed>
     */
    private function rowData(array $columns, ?array $post): array
    {
        $data = ['title' => (string) ($post['title'] ?? '')];
        foreach ($columns as $column) {
            if (($column['type'] ?? '') === 'title') {
                if (array_key_exists('title', $_POST)) {
                    $data['title'] = is_scalar($_POST['title']) ? (string) $_POST['title'] : '';
                }
                continue;
            }
            $name = 'field_' . $column['key'];
            if (array_key_exists($name, $_POST)) {
                $data[$name] = $_POST[$name];
            }
        }
        foreach (array_keys($this->taxonomies) as $taxKey) {
            $data['taxonomy_' . $taxKey] = $post === null ? [] : array_map(
                static fn (array $t): int => (int) $t['id'],
                Term::getPostTerms((int) $post['id'], (string) $taxKey)
            );
        }

        return $data;
    }

    /** The title a save writes: as typed, or, left blank, what its type's pattern made (`validateAndSanitize()`). */
    private function titleFrom(array $postData): string
    {
        $typed = trim((string) ($postData['title'] ?? ''));

        return $typed !== '' ? $typed : $this->madeTitle;
    }

    /**
     * What an address not typed is made from, by the type's address pattern
     * (`ContentType\Patterns`), or null when it has none, or nothing in it has
     * a value yet: then it is made from the title, as before.
     *
     * @param array<string, mixed> $values its fields as checked
     * @param array<string, mixed> $postData
     */
    private function slugMade(array $values, array $postData, ?int $id, string $title): ?string
    {
        $pattern = Patterns::slugPattern((string) $this->contentType['key']);
        $made    = $pattern === '' ? '' : Patterns::fill($pattern, (string) $this->contentType['key'], $values, $this->postedTerms($postData), $id, $title);

        return $made === '' ? null : $made;
    }

    /**
     * AN {id} IN A PATTERN IS KNOWN ONCE THE ENTRY IS FIRST SAVED: a title
     * its pattern made, and an address not typed, are written again with it.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $postData
     */
    private function numberInPatterns(int $id, bool $titleMade, bool $slugMade, array $values, array $postData, string $title, string $lang): void
    {
        $key  = (string) $this->contentType['key'];
        $data = [];
        if ($titleMade && Patterns::needsId(Patterns::titlePattern($key))) {
            $data['title'] = Patterns::fill(Patterns::titlePattern($key), $key, $values, $this->postedTerms($postData), $id) ?: $title;
        }
        if ($slugMade && Patterns::needsId(Patterns::slugPattern($key))) {
            $data['slug'] = Post::generateSlug($this->slugMade($values, $postData, $id, $data['title'] ?? $title) ?? ($data['title'] ?? $title), $id, $lang);
        }
        if ($data !== []) {
            Post::update($id, $data);
        }
    }

    /**
     * The terms a save posts, by taxonomy, as ids.
     *
     * @param array<string, mixed> $postData
     * @return array<string, list<int>>
     */
    private function postedTerms(array $postData): array
    {
        $out = [];
        foreach (array_keys($this->taxonomies) as $taxKey) {
            $raw                  = $postData['taxonomy_' . $taxKey] ?? [];
            $out[(string) $taxKey] = is_array($raw) ? array_values(array_map('intval', $raw)) : [];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $columns */
    private static function isColumn(array $columns, string $fieldKey): bool
    {
        foreach ($columns as $column) {
            if (($column['type'] ?? '') !== 'title' && (string) $column['key'] === $fieldKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entry a row names, if it is this type's, not in the trash, and this
     * person may change it; otherwise the refusal is answered.
     *
     * @return array<string, mixed>
     */
    private function rowEntry(array $vars): array
    {
        $post   = Post::find((int) ($vars['id'] ?? 0));
        $single = mb_strtolower((string) $this->contentType['label_single']);
        if ($post === null || (string) $post['post_type'] !== (string) $this->contentType['key'] || (string) $post['status'] === 'trash') {
            self::rowAnswer(404, ['error' => 'This ' . $single . ' is not here any more: it was moved to the trash or deleted.']);
        }
        if (!Auth::canChange($post)) {
            self::rowAnswer(403, ['error' => 'Only an editor can change this ' . $single . '.']);
        }

        return $post;
    }

    /** What a saved row shows of its entry: its id, its title, its status chip and its own screen. @return array<string, mixed> */
    private function rowFacts(int $id): array
    {
        $row = Post::find($id) ?? [];

        return [
            'id'     => $id,
            'title'  => (string) ($row['title'] ?? ''),
            'status' => Status::render(Status::forEntry($row)),
            'edit'   => Url::admin((string) $this->contentType['admin_slug'] . '/' . $id . '/edit'),
        ];
    }

    /**
     * A ROW REFUSED by this type's own checks: an answer as a saved one is
     * (200), since the row was heard and its words are the outcome, with
     * `success` false, its sentences as one `error` and each in `errors`.
     *
     * @param list<string> $errors
     */
    private static function rowRefused(array $errors): never
    {
        header('Content-Type: application/json; charset=utf-8');
        \Cms\Http\Cache::noStore();
        echo json_encode(['success' => false, 'error' => implode(' ', $errors), 'errors' => $errors], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * A row's answer, in the shape `CMS.api()` reads: `success`, and `data`
     * or `error`.
     *
     * @param array<string, mixed> $payload
     */
    private static function rowAnswer(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        \Cms\Http\Cache::noStore();
        echo json_encode(['success' => $status < 400] + $payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * An entry copied, to start the next one from, with its fields, terms,
     * picture and parent, as its type wants it (`ContentType\CopyRules`):
     * "(copy)" after its title, or the type's own words or none; Private, off
     * the site, or its original's status; and every field, or all but those
     * the type names. Its address is made from its title as a new entry's is.
     * A product's options and photos come too.
     *
     * A PRODUCT'S COPY HAS NO CODES AND NO STOCK. A SKU names one thing, so
     * each is left blank to be typed; and the copy is a different thing on
     * the shelf, so it holds nothing until its own stock is counted in.
     * Anything else may be changed on the copy's own screen, which is where
     * this leads.
     */
    public function handleCopy(array $vars = []): void
    {
        Auth::requireAuth();
        $post = $this->entryFor($vars);
        if ($post === null) {
            return;
        }
        if ((string) $post['status'] === 'trash') {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }

        // In the entry's own language, as a copy of a translation is one, and
        // as its type wants it.
        $rules = CopyRules::for((string) $post['post_type']);
        $lang  = (string) $post['lang'];
        $title = CopyRules::title((string) $post['title'], $rules['suffix']);
        $same  = $rules['status'] === 'same';
        $user  = Auth::user();
        $newId = Post::create([
            'post_type'         => (string) $post['post_type'],
            'title'             => $title,
            'slug'              => Post::generateSlug($title, null, $lang),
            'lang'              => $lang,
            'content'           => (string) ($post['content'] ?? ''),
            'excerpt'           => $post['excerpt'] ?? null,
            'status'            => $same ? (string) $post['status'] : 'private',
            'password_hash'     => $same && (string) $post['status'] === 'password' ? ($post['password_hash'] ?? null) : null,
            'featured_image_id' => $post['featured_image_id'] ?? null,
            'menu_order'        => (int) ($post['menu_order'] ?? 0),
            'author_id'         => (int) $user['id'],
        ]);
        // A CHILD STAYS WITH ITS PARENT: a club's event copied is the same
        // club's next event.
        if (!empty($post['parent_id'])) {
            Post::setParent($newId, (int) $post['parent_id']);
        }

        $id      = (int) $post['id'];
        $meta    = Meta::getAll($id);
        $counted = (string) ($meta[\Cms\Commerce\Stock::MANAGE] ?? '') === '1';
        foreach ($meta as $key => $value) {
            if (CopyRules::skips($rules['skip'], (string) $key)) {
                continue;
            }
            Meta::set($newId, (string) $key, match ((string) $key) {
                \Cms\Commerce\Product::SKU   => '',
                \Cms\Commerce\Stock::QTY    => '0',
                \Cms\Commerce\Product::STOCK => $counted ? \Cms\Commerce\Product::OUT_OF_STOCK : $value,
                default                       => $value,
            });
        }
        $copyRows = array_map(static function (array $v): array {
            unset($v['id']);
            $v['sku']       = '';
            $v['stock_qty'] = 0;
            return $v;
        }, \Cms\Commerce\Variation::forProduct($id));
        \Cms\Commerce\Variation::copyRows($newId, $copyRows);
        foreach (array_keys($this->taxonomies) as $taxKey) {
            Term::setPostTerms($newId, (string) $taxKey, array_map(
                static fn (array $t): int => (int) $t['id'],
                Term::forPosts([$id], [(string) $taxKey])[$id][(string) $taxKey] ?? []
            ));
        }

        header('Location: ' . ADMIN_URL . '/' . $this->contentType['admin_slug'] . '/' . $newId . '/edit', true, 303);
        exit;
    }

    /**
     * ITS VERSION IN THE SECOND LANGUAGE (`Content\Translation`): the one it
     * has, or a Private copy made now with its fields and terms, opened for
     * translating. Refused with no second language, for a translation, in the
     * trash and for what the shop sells: back to its own screen, whose
     * Translation panel says why.
     */
    public function handleTranslate(array $vars = []): void
    {
        Auth::requireAuth();
        $post = $this->entryFor($vars);
        if ($post === null) {
            return;
        }
        $back = ADMIN_URL . '/' . $this->contentType['admin_slug'] . '/';
        if (\Cms\Content\Translation::problem('posts', $post) !== null) {
            header('Location: ' . $back . (int) $post['id'] . '/edit#translation', true, 303);
            exit;
        }

        $id = \Cms\Content\Translation::entry($post, (int) Auth::user()['id']);
        header('Location: ' . $back . $id . '/edit', true, 303);
        exit;
    }

    /**
     * The fields without the product's (`product_…`, the `product` archetype's):
     * what a translation that sells its original's keeps.
     *
     * @param list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    private static function withoutProductFields(array $fields): array
    {
        return array_values(array_filter($fields, static fn (array $f): bool =>
            ($f['_archetype_key'] ?? '') !== 'product' && !str_starts_with((string) ($f['key'] ?? ''), 'product_')));
    }

    /**
     * Share it by a link (`Content\Share`), for as many days as chosen — an
     * entry a visitor cannot see yet. Back to its screen, where the link is.
     */
    public function handleShare(array $vars = []): void
    {
        Auth::requireAuth();
        $post = $this->entryFor($vars);
        if ($post === null) {
            return;
        }
        // NO PAGE, NO LINK: an entry whose type has no pages
        // (`Registry::hasPages()`) has nothing for a link to open.
        if (Registry::hasPages((string) $this->contentType['key'])) {
            \Cms\Content\Share::make('post', (int) $post['id'], (int) ($_POST['share_days'] ?? 0), (int) Auth::user()['id']);
        }
        header('Location: ' . ADMIN_URL . '/' . $this->contentType['admin_slug'] . '/' . (int) $post['id'] . '/edit#share', true, 303);
        exit;
    }

    /** Stop sharing it: its link stops working at once. */
    public function handleStopShare(array $vars = []): void
    {
        Auth::requireAuth();
        $post = $this->entryFor($vars);
        if ($post === null) {
            return;
        }
        \Cms\Content\Share::stop('post', (int) $post['id']);
        header('Location: ' . ADMIN_URL . '/' . $this->contentType['admin_slug'] . '/' . (int) $post['id'] . '/edit#share', true, 303);
        exit;
    }

    public function handleRestore(array $vars = []): void
    {
        Auth::requireAuth();
        if ($this->entryFor($vars) === null) {
            return;
        }
        Post::restore((int) $vars['id']);
        header('Location: ' . ADMIN_URL . '/' . $this->contentType['admin_slug'] . '/trash');
        exit;
    }

    public function showTrash(array $vars = []): void
    {
        Auth::requireAuth();
        $user        = Auth::user();
        $contentType = $this->contentType;
        // The same filter the list applies: an author's bin holds their own.
        // Listing everyone's would offer Restore and Delete on rows the
        // handlers now refuse — a button that answers 403 is a lie with a
        // colour on it.
        $posts       = Post::allTrashed(
            $contentType['key'],
            Auth::hasRole('editor') ? null : (int) $user['id']
        );

        // What a bulk Restore or Delete just did — index()'s pattern.
        $bulkReport = $_SESSION['entry_bulk_report'] ?? null;
        unset($_SESSION['entry_bulk_report']);
        if (!is_array($bulkReport) || ($bulkReport['type'] ?? '') !== $contentType['key']) {
            $bulkReport = null;
        }
        // What of the list this role sees (`ContentType\StaffView`).
        $showAddress = \Cms\ContentType\StaffView::listPart((string) $contentType['key'], 'address');
        $showBulk    = \Cms\ContentType\StaffView::listPart((string) $contentType['key'], 'bulk');
        require ROOT_PATH . '/admin/templates/content-type/trash.php';
    }

    /**
     * Permanently delete a post. Only reachable from the trash view.
     */
    public function handleDelete(array $vars = []): void
    {
        Auth::requireAuth();
        if ($this->entryFor($vars) === null) {
            return;
        }
        // revisions has no FK to posts (it spans posts and pages), so nothing
        // cascades for us — drop the history explicitly.
        Revision::deleteFor(Revision::TYPE_POST, (int) $vars['id']);
        Post::delete((int) $vars['id']); // CASCADE removes post_meta and relationship rows
        header('Location: ' . ADMIN_URL . '/' . $this->contentType['admin_slug'] . '/trash');
        exit;
    }

    // ----------------------------------------------------------------
    // Bulk — one action on many entries
    // ----------------------------------------------------------------

    /**
     * POST /admin/{type}/bulk — the list's and the trash's bulk actions.
     *
     *   edit     file the selection, credit it to an author, set its status
     *   trash    move it to the trash                        (the list)
     *   restore  bring it back — as private, Post::restore()  (the trash)
     *   delete   delete it for good                          (the trash)
     *
     * ── EVERY ENTRY THROUGH THE GATES ONE ENTRY GOES THROUGH ────────────────
     * `Auth::canChange()` per entry, so an author's bulk action reaches their
     * own entries and no one else's — what the row's own buttons allow.
     * Filing goes through `Term::countViolation()` and then `Term::setPostTerms()`,
     * the only writer of filings: a taxonomy that takes exactly one term refuses
     * a bulk edit that would leave an entry with two, and the refusal is the
     * rule's own sentence, by the entry's name.
     *
     * Delete and restore act only on what is in the trash, and edit and trash
     * only on what is not — the states each verb has on its own row. A tick box
     * posted from a stale page cannot delete a live entry.
     */
    public function handleBulk(array $vars = []): void
    {
        Auth::requireAuth();
        $type   = $this->contentType;
        $action = (string) ($_POST['bulk_action'] ?? '');
        $ids    = array_values(array_unique(array_filter(
            array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []),
            static fn (int $id): bool => $id > 0
        )));

        // A LIST THAT OFFERS THIS ROLE NO BULK ACTION refuses one
        // (`ContentType\StaffView`, migration 140): hiding the tick boxes is
        // not the only door shut.
        if (!\Cms\ContentType\StaffView::listPart((string) $type['key'], 'bulk')) {
            $_SESSION['entry_bulk_report'] = [
                'type'    => (string) $type['key'],
                'action'  => $action,
                'done'    => 0,
                'skipped' => [],
                'error'   => 'The ' . strtolower((string) $type['label']) . ' list offers your role no bulk actions.',
            ];
            header('Location: ' . $this->bulkReturn());
            exit;
        }

        // WHAT THE EDIT PANEL ASKS FOR, read once for the whole selection.
        $request = $action === 'edit' ? $this->bulkEditRequest() : null;

        // A PRICE OR A COUNT THAT IS NOT ONE refuses the whole edit, before any
        // entry is touched, in the sentence that says what one looks like.
        if ($request !== null && $request['error'] !== null) {
            $_SESSION['entry_bulk_report'] = [
                'type'    => (string) $type['key'],
                'action'  => $action,
                'done'    => 0,
                'skipped' => [],
                'error'   => $request['error'],
            ];
            header('Location: ' . $this->bulkReturn());
            exit;
        }

        $done    = 0;
        $skipped = [];
        $acts    = in_array($action, ['trash', 'restore', 'delete'], true)
                || ($action === 'edit' && $request !== null);

        foreach ($acts ? $ids : [] as $id) {
            $post = Post::find($id);
            // Gone since the page was drawn, or another type's: nothing to do,
            // and nothing to name.
            if ($post === null || (string) $post['post_type'] !== (string) $type['key']) {
                continue;
            }
            if (!Auth::canChange($post)) {
                $skipped[] = ['title' => (string) $post['title'], 'reason' => 'Only an editor can change this.'];
                continue;
            }

            $inTrash = (string) $post['status'] === 'trash';
            if ($inTrash !== in_array($action, ['restore', 'delete'], true)) {
                continue;
            }

            if ($action === 'edit') {
                $reason = $this->bulkEdit($post, $request);
                if ($reason !== null) {
                    $skipped[] = ['title' => (string) $post['title'], 'reason' => $reason];
                    continue;
                }
            } elseif ($action === 'trash') {
                Post::trash($id);
            } elseif ($action === 'restore') {
                Post::restore($id);
            } else {
                // revisions has no FK to posts, so the history goes explicitly —
                // handleDelete()'s two lines, for the same reason.
                Revision::deleteFor(Revision::TYPE_POST, $id);
                Post::delete($id);
            }
            $done++;
        }

        $_SESSION['entry_bulk_report'] = [
            'type'    => (string) $type['key'],
            'action'  => $action,
            'done'    => $done,
            'skipped' => $skipped,
        ];
        header('Location: ' . $this->bulkReturn());
        exit;
    }

    /**
     * What the bulk edit panel asks for, or null when it asks for nothing.
     *
     * A taxonomy counts only when its `changed` marker is set — the panel sets
     * it on the first touch. Left alone, a taxonomy is not rewritten at all, so
     * an entry filed before a rule tightened is not refused an author change
     * for a taxonomy nobody touched (the `parent_panel` rule: the marker, not
     * the value, says the control was used).
     *
     * Price, sale price and stock, on a type that sells (`Commerce\ProductBulk`):
     * each with how it changes and a figure, and the sentence refusing a
     * figure that is not one.
     *
     * @return array{terms: array<string, array{set?: int, on?: list<int>, keep?: list<int>}>,
     *               status: string, author: ?int, shop: array<string, array<string, mixed>>, error: ?string}|null
     */
    private function bulkEditRequest(): ?array
    {
        $ints = static fn (mixed $v): array => array_values(array_unique(array_filter(
            array_map('intval', is_array($v) ? $v : []),
            static fn (int $i): bool => $i > 0
        )));

        $raw   = is_array($_POST['bulk_terms'] ?? null) ? $_POST['bulk_terms'] : [];
        $terms = [];
        foreach ($this->taxonomies as $taxKey => $tax) {
            $in = $raw[$taxKey] ?? null;
            if (!is_array($in) || empty($in['changed'])) {
                continue;
            }
            if (array_key_exists('set', $in)) {
                // Exactly one: a radio. "No change" posts nothing to set.
                if ((int) $in['set'] > 0) {
                    $terms[(string) $taxKey] = ['set' => (int) $in['set']];
                }
                continue;
            }
            $terms[(string) $taxKey] = ['on' => $ints($in['on'] ?? []), 'keep' => $ints($in['keep'] ?? [])];
        }

        $status = in_array($_POST['bulk_status'] ?? '', ['published', 'private', Post::SAMPLE], true)
            ? (string) $_POST['bulk_status'] : '';

        // Whoever the entry form shows its Author panel to chooses an author
        // here too (`StaffView`); anyone else's choice is not read at all.
        $author = null;
        if (\Cms\ContentType\StaffView::panel((string) $this->contentType['key'], 'author')) {
            $want = (int) ($_POST['bulk_author'] ?? 0);
            if ($want > 0 && User::find($want) !== null) {
                $author = $want;
            }
        }

        $shop = ['changes' => [], 'error' => null];
        if (in_array('product', (array) ($this->contentType['archetypes'] ?? []), true)) {
            $shop = \Cms\Commerce\ProductBulk::request($_POST);
        }

        return ($terms === [] && $status === '' && $author === null && $shop['changes'] === [] && $shop['error'] === null)
            ? null
            : ['terms' => $terms, 'status' => $status, 'author' => $author, 'shop' => $shop['changes'], 'error' => $shop['error']];
    }

    /**
     * Apply the bulk edit to one entry — the reason it was refused, or null.
     *
     * ── AN ENTRY TAKES THE WHOLE EDIT OR NONE OF IT ─────────────────────────
     * Every taxonomy's rule is checked before anything is written. Otherwise a
     * refused category would leave the same entry's status and author changed,
     * and the report saying it was "not changed" would be false.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $request  from bulkEditRequest()
     */
    private function bulkEdit(array $post, array $request): ?string
    {
        $id      = (int) $post['id'];
        $filings = [];

        foreach ($request['terms'] as $taxKey => $change) {
            if (isset($change['set'])) {
                $new = [(int) $change['set']];
            } else {
                // Keep what the entry had among the terms left in the third
                // state, add every ticked one, and drop the rest.
                $current = array_map('intval', Term::getPostTermIds($id, (string) $taxKey));
                $new     = array_values(array_unique(array_merge(
                    array_intersect($current, $change['keep']),
                    array_diff($change['on'], $change['keep'])
                )));
            }

            $problem = Term::countViolation((string) $taxKey, $new, (string) $this->contentType['key']);
            if ($problem !== null) {
                return $problem;
            }
            $filings[(string) $taxKey] = $new;
        }

        // PRICES AND COUNTS, every figure worked out before anything is
        // written: one refused leaves the entry as it was.
        // Never on a translation that sells its original's (`Content\Translation::sellsOriginal()`).
        $shop = $request['shop'] !== [] && !\Cms\Content\Translation::sellsOriginal($post)
            ? \Cms\Commerce\ProductBulk::plan($id, $request['shop']) : null;
        if ($shop !== null && $shop['error'] !== null) {
            return $shop['error'];
        }
        $restatus = $request['status'] !== '' && $request['status'] !== (string) $post['status'];
        $reprice  = $shop !== null && ($shop['meta'] !== [] || $shop['options'] !== []);

        if ($restatus || $reprice) {
            // Reversible like a save: the revision is taken first.
            Revision::snapshot(Revision::TYPE_POST, $id, (int) (Auth::user()['id'] ?? 0));
            Revision::prune(Revision::TYPE_POST, $id);
        }
        // FIRST, so a count sold from since the plan leaves the entry whole.
        if ($shop !== null && \Cms\Commerce\ProductBulk::changes($shop)) {
            $user  = Auth::user();
            $raced = \Cms\Commerce\ProductBulk::write($id, $shop, (string) ($user['name'] ?? $user['username'] ?? ''));
            if ($raced !== null) {
                return $raced;
            }
        }

        if ($restatus) {
            // Leaving `password` drops the password, as the entry form does
            // (resolveStatusAndPassword() stores one only for that status).
            Post::update($id, ['status' => $request['status']]
                + ((string) $post['status'] === 'password' ? ['password_hash' => null] : []));
        }

        if ($request['author'] !== null && $request['author'] !== (int) $post['author_id']) {
            Post::setAuthor($id, (int) $request['author']);
        }

        foreach ($filings as $taxKey => $new) {
            Term::setPostTerms($id, $taxKey, $new, (string) $this->contentType['key']);
        }

        return null;
    }

    /**
     * Where a bulk action goes back to: the view it was made from — the same
     * filter, sort and page — which the form posts as `return`. Only this
     * type's own list or trash is accepted; anything else is the list, so the
     * field cannot send a signed-in editor somewhere else after a click.
     */
    private function bulkReturn(): string
    {
        $base = Url::admin((string) $this->contentType['admin_slug']);
        $want = is_string($_POST['return'] ?? null) ? (string) $_POST['return'] : '';

        if (preg_match('/[\r\n]/', $want) !== 1) {
            foreach ([$base, $base . '/trash'] as $allowed) {
                if ($want === $allowed || str_starts_with($want, $allowed . '?')) {
                    return $want;
                }
            }
        }

        return $base;
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * The entry a route names — IF this screen may act on it. Otherwise the
     * response has already been sent (the admin 404 or the admin 403) and this
     * returns null, so a handler's only job is to stop.
     *
     * ── WHY EVERY HANDLER GOES THROUGH HERE ─────────────────────────────────
     * Three handlers checked nothing but sign-in: trash, restore and delete
     * took the id from the address and acted on it. So an author could trash
     * an editor's entry, and `/admin/events/{a news id}/trash` trashed a news
     * item from the events screen. Edit and save checked the type and not the
     * owner. One gate, five callers, and a missing check cannot be forgotten
     * in one of them because none of them do their own.
     *
     * @param  array<string, mixed> $vars
     * @return array<string, mixed>|null
     */
    private function entryFor(array $vars): ?array
    {
        $post = Post::find((int) ($vars['id'] ?? 0));

        if ($post === null || $post['post_type'] !== $this->contentType['key']) {
            // THE ADMIN'S 404, not the public site's: that one rendered the
            // marketing masthead and "Back to home" inside the admin, which
            // reads as the CMS throwing you out (see admin/templates/404.php).
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return null;
        }

        if (!Auth::canChange($post)) {
            http_response_code(403);
            $backUrl   = ADMIN_URL . '/' . $this->contentType['admin_slug'];
            $backLabel = (string) $this->contentType['label'];
            require ROOT_PATH . '/admin/templates/403.php';
            return null;
        }

        return $post;
    }

    /**
     * WHAT THIS LIST REQUEST ASKS FOR — each part checked against what can
     * exist, so nothing the address carries reaches the query unexamined: a
     * status the registry knows, a term of a taxonomy attached to THIS type,
     * one of two sort columns, a month that is a month.
     *
     * The parameters have plain names (`s`, `author`, `orderby`, `order`,
     * `per_page`). A taxonomy is `tax_{key}` holding a term id: a taxonomy may
     * be keyed anything, and its bare key could be `status` or `page`.
     *
     * ── PER PAGE IS REMEMBERED; NOTHING ELSE IS ─────────────────────────────
     * The number of items per page is kept for the person and the filters are
     * forgotten — for the session, in the session. There is no per-user
     * preference screen to hold it yet (the appearance pane that owns such
     * things is unbuilt; see UserController::handleUpdate()), and a filter
     * that silently came back on the next visit would hide entries from
     * someone who had forgotten setting it.
     *
     * @return array{query: array<string, mixed>, filters: array<string, mixed>,
     *               sort: array{orderby: string, order: string}, sorted: array{orderby: string, order: string},
     *               when: string, filtering: bool,
     *               url: \Closure(array<string, mixed>=): string}
     */
    private function listView(?int $authorOnly): array
    {
        $get = static fn (string $k): string => is_string($_GET[$k] ?? null) ? trim((string) $_GET[$k]) : '';

        $search = mb_substr($get('s'), 0, 200);

        // What the chip can say, minus the trash, which has its own screen.
        $statuses = Status::filterLabels();
        unset($statuses['trash']);
        $status = array_key_exists($get('status'), $statuses) ? $get('status') : '';

        // AN AUTHOR'S LIST IS THEIRS ALREADY, so the filter exists for editors
        // and up; for an author it could only ever narrow to nothing. A type
        // whose list does not offer the filter to a role (`StaffView`) reads
        // none from its address either.
        $author = ($authorOnly === null && \Cms\ContentType\StaffView::listPart((string) $this->contentType['key'], 'author_filter')
                   && ctype_digit($get('author')) && (int) $get('author') > 0)
            ? (int) $get('author') : null;

        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $get('month')) === 1 ? $get('month') : '';

        // SOLD OUT OR RUNNING LOW, on a list of things for sale.
        $isProduct = in_array('product', (array) ($this->contentType['archetypes'] ?? []), true);
        $stock     = $isProduct && in_array($get('stock'), ['out', 'low'], true) ? $get('stock') : '';

        // ONE LANGUAGE (`Site\Language`): `own`, the site's, or the second
        // language's prefix.
        $second = \Cms\Site\Language::prefix();
        $lang   = $get('lang') === 'own' || ($second !== '' && $get('lang') === $second) ? $get('lang') : '';

        // A TERM FILTER IS A TERM OF THIS TYPE'S TAXONOMY, or it is ignored —
        // an id from another taxonomy would otherwise filter by a term the bar
        // cannot show. On a nested taxonomy it stands for its whole branch,
        // which is what its archive lists.
        $terms    = [];
        $branches = [];
        foreach ($this->taxonomies as $taxKey => $tax) {
            $raw  = $get('tax_' . $taxKey);
            $term = ctype_digit($raw) ? Term::find((int) $raw) : null;
            if ($term === null || (string) $term['taxonomy_key'] !== (string) $taxKey) {
                continue;
            }
            $terms[(string) $taxKey]    = (int) $term['id'];
            $branches[(string) $taxKey] = array_merge(
                [(int) $term['id']],
                !empty($tax['hierarchical']) ? Term::getDescendantIds((int) $term['id'], (string) $taxKey) : []
            );
        }

        // ONE PARENT'S, on a child type's list: an entry of its parent type
        // that is not in the trash, or none.
        $parentFilter = 0;
        $parentOf     = Registry::parentOf((string) $this->contentType['key']);
        if ($parentOf !== null && ctype_digit($get('parent'))) {
            $parentRow = Post::find((int) $get('parent'));
            if ($parentRow !== null && (string) $parentRow['post_type'] === (string) $parentOf['key'] && (string) $parentRow['status'] !== 'trash') {
                $parentFilter = (int) $parentRow['id'];
            }
        }

        // 1–999 a page.
        $perPage = (int) ($_SESSION['entry_list_per_page'] ?? 20);
        if (ctype_digit($get('per_page'))) {
            $perPage = (int) $get('per_page');
        }
        $perPage = max(1, min(999, $perPage));
        $_SESSION['entry_list_per_page'] = $perPage;

        // A column's FIRST click sorts the way that column is read: titles
        // A→Z, dates newest first.
        // A FIELD'S ORDER is `field:{key}`, a field of this type the list can
        // sort by (`ListColumns::sortable()`); any other `orderby` is none.
        $typeKey = (string) $this->contentType['key'];
        $orderby = $get('orderby');
        if (!in_array($orderby, ['title', 'date'], true)) {
            $orderby = str_starts_with($orderby, 'field:') && ListColumns::sortable($typeKey, substr($orderby, 6)) !== null ? $orderby : '';
        }
        $order = '';
        if ($orderby !== '') {
            $order = in_array($get('order'), ['asc', 'desc'], true)
                ? $get('order') : ($orderby === 'date' ? 'desc' : 'asc');
        }
        $sortField = str_starts_with($orderby, 'field:') ? ListColumns::sortable($typeKey, substr($orderby, 6)) : null;

        // AN EVENT TYPE'S LIST OPENS ON UPCOMING; Past and All beside it.
        $when = ListColumns::isEvent($typeKey) ? (in_array($get('when'), ['past', 'all'], true) ? $get('when') : 'upcoming') : '';

        // WHAT ORDER THE ROWS ARE IN when nobody has chosen, for the query and
        // for the heading that wears the arrow: the type's own sort, or, in
        // Upcoming and Past, by when each starts. Not in the address, which
        // carries only what was chosen.
        $sorted = ['orderby' => $orderby, 'order' => $order];
        $query  = ['orderby' => $orderby, 'order' => $order, 'numeric' => ($sortField['type'] ?? '') === 'number'];
        if ($orderby === '' && ($when === '' || $when === 'all')) {
            $default = ListColumns::defaultSort($typeKey);
            if ($default !== null) {
                $sorted = $default;
                $field  = str_starts_with($default['orderby'], 'field:') ? ListColumns::sortable($typeKey, substr($default['orderby'], 6)) : null;
                $query  = $default + ['numeric' => ($field['type'] ?? '') === 'number'];
            }
        } elseif ($orderby === '') {
            $sorted = ['orderby' => 'field:event_start_date', 'order' => $when === 'past' ? 'desc' : 'asc'];
        }

        $page = ctype_digit($get('page')) ? max(1, (int) $get('page')) : 1;

        // ── THE STATE A LINK CARRIES ───────────────────────────────────────
        // Sorting keeps the filters, paging keeps both, and filtering starts
        // again at page 1. Empty values are left out, so an untouched list's
        // address is just its address.
        $state = ['s' => $search, 'status' => $status, 'author' => $author, 'month' => $month, 'stock' => $stock, 'lang' => $lang,
                  'when' => $when === 'upcoming' ? '' : $when, 'parent' => $parentFilter > 0 ? $parentFilter : null];
        foreach ($terms as $taxKey => $termId) {
            $state['tax_' . $taxKey] = $termId;
        }
        $state += ['orderby' => $orderby, 'order' => $order];

        $base = Url::admin((string) $this->contentType['admin_slug']);
        $url  = static function (array $changes = []) use ($state, $base): string {
            $q = array_filter(
                $changes + $state,
                static fn ($v): bool => $v !== '' && $v !== null
            );
            if ((int) ($q['page'] ?? 1) <= 1) {
                unset($q['page']);
            }
            return $base . ($q === [] ? '' : '?' . http_build_query($q));
        };

        return [
            'query' => [
                'type'         => (string) $this->contentType['key'],
                'author_only'  => $authorOnly,
                'status'       => $status,
                'search'       => $search,
                'author'       => $author,
                'month'        => $month,
                'stock'        => $stock,
                'lang'         => $lang,
                'low_at'       => \Cms\Commerce\Stock::lowAt(),
                'terms'        => $branches,
                'parent'       => $parentFilter > 0 ? $parentFilter : null,
                'orderby'      => $query['orderby'],
                'order'        => $query['order'],
                'sort_numeric' => $query['numeric'],
                'hand_ordered' => ArchetypeDefaults::isHandOrdered($this->contentType['archetypes'] ?? []),
                'page'         => $page,
                'per_page'     => $perPage,
            ],
            'filters' => [
                's'        => $search,
                'status'   => $status,
                'author'   => $author,
                'month'    => $month,
                'stock'    => $stock,
                'lang'     => $lang,
                'terms'    => $terms,
                'parent'   => $parentFilter,
                'per_page' => $perPage,
            ],
            'sort'      => ['orderby' => $orderby, 'order' => $order],
            'sorted'    => $sorted,
            'when'      => $when,
            'filtering' => $search !== '' || $status !== '' || $author !== null || $month !== '' || $stock !== '' || $lang !== '' || $terms !== [] || $parentFilter > 0,
            // SHOWN IN ITS OWN ORDER, as a drag arranges it: no search, filter
            // or sort but one parent's, on a child type's list.
            'plain'     => $search === '' && $status === '' && $author === null && $month === '' && $stock === '' && $lang === '' && $terms === []
                           && $orderby === '' && $when === '',
            'url'       => $url,
        ];
    }

    /**
     * Resolve status and password_hash from POST data.
     *
     * @param array<string, mixed>      $postData
     * @param array<string, mixed>|null $existing  Existing post row (to preserve password on edit)
     * @return array{0: string, 1: string|null}  [status, password_hash]
     */
    private function resolveStatusAndPassword(array $postData, ?array $existing): array
    {
        $allowed = ['published', 'password', 'private', Post::SAMPLE];
        $status  = in_array($postData['status'] ?? '', $allowed, true)
            ? $postData['status']
            : 'private';   // unrecognised fails towards NOT public — see PageController

        $passwordHash = null;
        if ($status === 'password') {
            $newPassword = trim($postData['post_password'] ?? '');
            if ($newPassword !== '') {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            } elseif ($existing !== null) {
                $passwordHash = $existing['password_hash'] ?? null;
            }
        }

        return [$status, $passwordHash];
    }

    /**
     * Sanitize a user-supplied slug: lowercase, alphanumeric + hyphens, collapsed.
     */
    /**
     * Store which entry this one belongs to — migration 047.
     *
     * ── THE MARKER, NOT THE VALUE, SAYS THE CONTROL WAS THERE ───────────────
     * A type that declares no parent renders no control and posts no key, and a
     * type that DOES renders one whose "Nothing" option posts an empty string.
     * Keyed on the value alone those are the same POST and mean opposite
     * things — the second is the owner deliberately detaching an entry, and
     * treating it as the first leaves the old parent in place on a save that
     * reported success.
     *
     * `variations_panel` carries the same hidden marker for the same reason.
     *
     * @param array<string, mixed> $contentType
     */
    private static function saveParent(int $id, array $contentType): void
    {
        if (empty($_POST['parent_panel'])) {
            return;
        }

        // AND THE POSTED ID IS CHECKED AGAINST THE TYPE THAT MAY OWN IT. The
        // select offers only entries of the declared parent type; a hand-made
        // POST can name any row in `posts`, and a club event whose parent is a
        // news story is a row no screen can render.
        $parent = Registry::parentOf((string) $contentType['key']);
        $wanted = (int) ($_POST['parent_id'] ?? 0);

        if ($parent === null || $wanted <= 0) {
            Post::setParent($id, null);
            return;
        }

        $row = Post::find($wanted);
        $ok  = $row !== null
            && (string) $row['post_type'] === (string) $parent['key']
            && (string) $row['status'] !== 'trash';

        Post::setParent($id, $ok ? $wanted : null);
    }

    /**
     * What the Belongs-to control needs: the parent TYPE, the entries it may
     * point at, and the one currently chosen.
     *
     * ONE METHOD FOR ALL FIVE RENDER SITES — create, a failed create, edit, a
     * failed update, and the saved redirect. `pages/edit.php` computed one such
     * rule twice and the copies had drifted, with one of them wrong on exactly
     * the case the other handled; five copies would be worse arithmetic.
     *
     * @param  array<string, mixed>|null $post
     * @return array{0: array<string, mixed>|null, 1: list<array<string, mixed>>, 2: int}
     */
    private function parentContext(?array $post): array
    {
        $parentType = Registry::parentOf((string) $this->contentType['key']);

        if ($parentType === null) {
            return [null, [], 0];
        }

        // WHAT MAY BE CHOSEN, not everything that exists: trashed entries are
        // excluded, because offering one is offering to hang a club event off a
        // club the owner has already thrown away.
        $options = array_values(array_filter(
            Post::all((string) $parentType['key'], null, $parentType['archetypes'] ?? []),
            static fn (array $row): bool => (string) $row['status'] !== 'trash'
        ));

        // ── FOUND BY MORE THAN ITS TITLE ────────────────────────────────────
        // AN EVENT TYPE'S, IN ITS PUBLIC LIST'S ORDER (`Content\Events::order()`):
        // those to come first, soonest first, then those over, newest first.
        // And EACH NAMED AS THE CHILD TYPE SAYS (`'parent_label'`, migration
        // 148), its date, its place and its title, in the token form of titles
        // made from fields; else by its title.
        $parentKey = (string) $parentType['key'];
        $ids       = array_map(static fn (array $row): int => (int) $row['id'], $options);
        $metas     = Meta::getAllFor($ids);
        if (ListColumns::isEvent($parentKey) && $options !== []) {
            $byId = array_column($options, null, 'id');
            $rows = array_map(static fn (array $row): array => [
                'id'     => (int) $row['id'],
                'starts' => $metas[(int) $row['id']]['event_start_date'] ?? null,
                'ends'   => $metas[(int) $row['id']]['event_end_date'] ?? null,
            ], $options);
            $options = array_map(static fn (array $o): array => $byId[$o['id']], \Cms\Content\Events::order($rows, new \DateTimeImmutable('now')));
        }
        $pattern = trim((string) ($this->contentType['parent_label'] ?? ''));
        $terms   = $pattern === '' ? [] : Term::forPosts($ids, array_keys(TaxonomyRegistry::getForContentType($parentKey)));
        foreach ($options as &$option) {
            $id              = (int) $option['id'];
            $option['label'] = $pattern === '' ? '' : \Cms\ContentType\Patterns::fill($pattern, $parentKey, $metas[$id] ?? [],
                array_map(static fn (array $list): array => array_map(static fn (array $t): int => (int) $t['id'], $list), $terms[$id] ?? []),
                $id, (string) $option['title']);
            if ($option['label'] === '') {
                $option['label'] = (string) $option['title'];
            }
        }
        unset($option);

        // ON A FAILED SUBMIT THE TYPED CHOICE WINS, the same rule every other
        // control on this form follows — a validation error elsewhere must not
        // quietly put the parent back to what it was.
        $chosen = isset($_POST['parent_panel'])
            ? (int) ($_POST['parent_id'] ?? 0)
            : (int) ($post['parent_id'] ?? 0);

        return [$parentType, $options, $chosen];
    }

    /**
     * The excerpt this save writes — as a key to merge into Post::create() or
     * Post::update(), or NOTHING.
     *
     * ── THE KEY BEING POSTED IS WHAT SAYS THE BOX WAS THERE ─────────────────
     * A textarea always posts its name, empty or not. So a type that supports
     * an excerpt posts `excerpt` from this form, and anything that posts no
     * such key — a type without the support, a hand-made request that is only
     * changing the title — says nothing about it. Returning `[]` then leaves
     * the stored excerpt alone, because Post::update() writes only the columns
     * it is given. Treating "absent" as "empty" would clear it — the failure
     * the `variations_panel` and `parent_panel` markers guard against.
     *
     * @param  array<string, mixed> $contentType
     * @return array{excerpt?: string}
     */
    private static function excerptFrom(array $contentType): array
    {
        if (!in_array('excerpt', (array) ($contentType['supports'] ?? []), true)
            || !array_key_exists('excerpt', $_POST)) {
            return [];
        }
        return ['excerpt' => (string) $_POST['excerpt']];
    }

    /**
     * Who the Author control offers, and who it shows as chosen — or null,
     * for an author: they write as themselves, and a control that could only
     * ever hold one value is not a control.
     *
     * Chosen: on a failed save the typed choice (the rule every control on
     * this form follows), else the entry's own author, else — on a new entry
     * — the person creating it.
     *
     * @param  array<string, mixed>|null $post
     * @return array{0: list<array<string, mixed>>, 1: int}|null
     */
    private static function authorChoices(?array $post, string $type): ?array
    {
        // WHO SEES THE PANEL: an editor and up, unless the type says
        // (`ContentType\StaffView`, migration 140).
        if (!\Cms\ContentType\StaffView::panel($type, 'author')) {
            return null;
        }
        $chosen = isset($_POST['author_panel'])
            ? (int) ($_POST['author_id'] ?? 0)
            : (int) ($post['author_id'] ?? (Auth::user()['id'] ?? 0));
        return [User::authors(), $chosen];
    }

    /**
     * Credit the entry to the chosen user. Editor-and-up only — the control is
     * not drawn for an author, and a hand-made POST from one changes nothing.
     * Keyed on the `author_panel` marker, so a request that never had the
     * control says nothing about the author (the `parent_panel` rule).
     */
    private static function saveAuthor(int $id, string $type): void
    {
        if (empty($_POST['author_panel']) || !\Cms\ContentType\StaffView::panel($type, 'author')) {
            return;
        }
        $wanted = (int) ($_POST['author_id'] ?? 0);
        if ($wanted > 0 && User::find($wanted) !== null) {
            Post::setAuthor($id, $wanted);
        }
    }

    /**
     * "Show in search results" and the social image, both of which
     * `Public\PostController` reads.
     *
     *   `_seo_robots`  'noindex, follow' when the owner says No; for Yes the
     *                  row is removed and the reader's default, `index,
     *                  follow`, applies — a Yes stores nothing, like every
     *                  default here
     *   `_og_image`    the chosen picture's id, or the row removed
     *
     * THE KEY BEING POSTED IS WHAT SAYS THE CONTROL WAS THERE — the
     * `excerptFrom()` rule. A request that never had the panel (an import, a
     * hand-made POST) says nothing about either, and must not reset them.
     */
    private static function saveSearchAndSocial(int $id): void
    {
        if (array_key_exists('seo_index', $_POST)) {
            (string) $_POST['seo_index'] === '0'
                ? Meta::set($id, '_seo_robots', 'noindex, follow')
                : Meta::delete($id, '_seo_robots');
        }
        if (array_key_exists('og_image_id', $_POST)) {
            $image = (int) $_POST['og_image_id'];
            $image > 0
                ? Meta::set($id, '_og_image', (string) $image)
                : Meta::delete($id, '_og_image');
        }
    }

    /**
     * The publish date this save stores, in UTC.
     *
     * The box posts `published_local` — wall-clock time in the site's zone. An
     * empty box means "the moment it goes live" (null here; Post stamps it).
     * A request with NO such key never had the box, so the stored date stays.
     * An unreadable value never gets here: validateAndSanitize() refuses it,
     * because storing "now" in its place would publish on a date nobody chose.
     *
     * @param array<string, mixed>|null $existing
     */
    private static function publishedAtFrom(?array $existing): ?string
    {
        if (!array_key_exists('published_local', $_POST) || is_array($_POST['published_local'])
            || self::boxAsShown('published_local', $existing['published_at'] ?? null)) {
            return $existing['published_at'] ?? null;
        }
        $utc = Dates::fromInput((string) $_POST['published_local']);
        return is_string($utc) ? $utc : null;
    }

    /**
     * Whether a date box came back as it was drawn (`Dates::toInput()`). The
     * box shows the minute, so a date stored with its second (an imported
     * one, or one set by the clock) came back a few seconds earlier from a
     * save that changed nothing; posted back as shown, the stored date stays.
     */
    private static function boxAsShown(string $box, ?string $stored): bool
    {
        return $stored !== null && trim((string) $_POST[$box]) === Dates::toInput($stored);
    }

    /**
     * What a save can change on an entry, to tell whether one did: its row
     * but for the date that moves with it, its fields, its terms and, for a
     * product, its options. A field stored as NULL has no value (`Meta::set()`),
     * the same as one never stored: a first save writes the search title and
     * description that way for an entry that had none, and nothing changed.
     *
     * @return array<string, mixed>
     */
    private function state(int $id): array
    {
        $row = Post::find($id) ?? [];
        unset($row['updated_at']);
        $meta = array_filter(Meta::getAll($id), static fn (mixed $v): bool => $v !== null);
        ksort($meta);
        $terms = [];
        foreach (array_keys($this->taxonomies) as $taxKey) {
            $terms[$taxKey] = array_column(Term::getPostTerms($id, (string) $taxKey), 'id');
        }
        $options = in_array('product', (array) ($this->contentType['archetypes'] ?? []), true)
            ? \Cms\Commerce\Variation::forProduct($id)
            : [];

        return ['row' => $row, 'meta' => $meta, 'terms' => $terms, 'options' => $options];
    }

    /**
     * The unpublish date this save stores, in UTC, or null for none: the
     * `unpublish_local` box, read as `published_local` is. A request with no
     * such box keeps the stored date.
     *
     * @param array<string, mixed>|null $existing
     */
    /**
     * What is wrong with the unpublish box as posted, or null: a value that
     * is not a date and time, or one at or before the publish date, which
     * would take the entry off the site before it went on. The page screen
     * asks the same.
     *
     * @param array<string, mixed> $postData
     */
    public static function endingProblem(array $postData): ?string
    {
        if (!array_key_exists('unpublish_local', $postData) || is_array($postData['unpublish_local'])) {
            return null;
        }
        $ends = Dates::fromInput((string) $postData['unpublish_local']);
        if ($ends === false) {
            return 'The unpublish date is not a date and time.';
        }
        $starts = isset($postData['published_local']) && !is_array($postData['published_local'])
            ? Dates::fromInput((string) $postData['published_local'])
            : null;
        if (is_string($ends) && is_string($starts) && $ends <= $starts) {
            return 'The unpublish date is not after the publish date.';
        }
        return null;
    }

    private static function unpublishAtFrom(?array $existing): ?string
    {
        if (!array_key_exists('unpublish_local', $_POST) || is_array($_POST['unpublish_local'])
            || self::boxAsShown('unpublish_local', $existing['unpublish_at'] ?? null)) {
            return $existing['unpublish_at'] ?? null;
        }
        $utc = Dates::fromInput((string) $_POST['unpublish_local']);
        return is_string($utc) ? $utc : null;
    }

    private static function sanitizeSlugInput(string $raw): string
    {
        return \Cms\Helpers\Slug::clean($raw);
    }

    /**
     * Validate base fields (title required) + all Content Type custom fields.
     *
     * @param array<string, mixed>      $postData
     * @param array<string, mixed>|null $kept  fields a row does not post, by key, as kept
     * @return array{0: string[], 1: array<string, mixed>}  [errors, sanitizedMetaValues]
     */
    /**
     * WHAT A LOGIC RULE IS ASKED OF ON SAVE, as the browser reads the form
     * (`logic.js`): each field's value, a field of several values (a
     * relationship's entries, a taxonomy field's terms) as a list of them;
     * and each taxonomy panel's terms, by their ids as `taxonomy_{key}` and by
     * their slugs as `tax:{key}`. So a field shown only for one term, named by
     * its slug, is checked on save exactly when the editor shows it.
     *
     * @param list<array<string, mixed>>          $fields
     * @param array<string, mixed>                 $sanitized
     * @param array<string, array<string, mixed>>  $taxonomies
     * @param array<string, mixed>                 $postData
     * @return array<string, mixed>
     */
    public static function logicValues(array $fields, array $sanitized, array $taxonomies, array $postData): array
    {
        $values = $sanitized;
        foreach ($fields as $field) {
            $key = (string) ($field['key'] ?? '');
            if (in_array($field['type'] ?? '', ['relationship', 'taxonomy'], true) && is_string($values[$key] ?? null)) {
                $values[$key] = array_map('strval', array_values(array_filter((array) json_decode((string) $values[$key], true), 'is_scalar')));
            }
        }
        foreach (array_keys($taxonomies) as $taxKey) {
            $raw = $postData['taxonomy_' . $taxKey] ?? [];
            $ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw]), static fn (int $id): bool => $id > 0));
            $values['taxonomy_' . $taxKey] = array_map('strval', $ids);
            $values['tax:' . $taxKey]      = Term::slugsOf((string) $taxKey, $ids);
        }

        return $values;
    }

    private function validateAndSanitize(array $postData, ?int $excludeId = null, string $lang = '', ?array $kept = null): array
    {
        $errors = [];

        // ── AN ADDRESS SOMETHING ELSE ALREADY HAS ────────────────────────────
        // `posts.slug` is UNIQUE across every type and the trash within a
        // language (the entry's, `$lang`), so a typed slug that clashed
        // reached the INSERT and came back as a 500 — the Pages form refused
        // it with a sentence, this one with a stack trace. Refused here now,
        // naming what is in the way.
        // AN ADDRESS TYPED BY HAND that is taken is refused. One a new entry
        // made from its title (`slug_auto`) is made unique on save instead;
        // an existing entry's never moves, whatever is posted.
        $typed = self::sanitizeSlugInput((string) ($postData['slug'] ?? ''));
        $made  = $excludeId === null && ($postData['slug_auto'] ?? '') === '1';
        if ($typed !== '' && !$made && ($owner = Post::slugOwner($typed, $excludeId, $lang)) !== null) {
            $ownerType = Registry::get((string) $owner['post_type']);
            $what      = $ownerType !== null ? strtolower((string) ($ownerType['label_single'] ?? $owner['post_type'])) : (string) $owner['post_type'];
            $errors[]  = (string) $owner['status'] === 'trash'
                ? 'The address "' . $typed . '" belongs to the ' . $what . ' "' . $owner['title'] . '", which is in the trash. Restore it or empty the trash to use the address here.'
                : 'The address "' . $typed . '" is already used by the ' . $what . ' "' . $owner['title'] . '".';
        }

        // A TITLE LEFT BLANK is refused below, once the fields are known, unless
        // the type's pattern makes one from them; it is said in this place.
        $titleAt = count($errors);

        // ── HOW MANY TERMS EACH TAXONOMY TAKES ──────────────────────────────
        // Exactly one, at least one, no more than N — the type's own rule for
        // the taxonomy, else the taxonomy's (`Term::countRule()`), refused here
        // with its words so the form comes back with what was
        // typed and ticked. `Term::setPostTerms()` refuses the same set for
        // every caller that does not ask first.
        foreach ($this->taxonomies as $taxKey => $tax) {
            $raw     = $postData['taxonomy_' . $taxKey] ?? [];
            $problem = Term::countViolation((string) $taxKey, is_array($raw) ? $raw : [], (string) $this->contentType['key']);
            if ($problem !== null) {
                $errors[] = $problem;
            }
        }

        if (array_key_exists('published_local', $postData)
            && !is_array($postData['published_local'])
            && Dates::fromInput((string) $postData['published_local']) === false) {
            $errors[] = 'The publish date is not a date and time.';
        }
        $ending = self::endingProblem($postData);
        if ($ending !== null) {
            $errors[] = $ending;
        }

        // TWO PASSES, AND THE ORDER MATTERS. Sanitise everything first, then
        // decide what conditional logic actually showed, then validate only
        // that. It cannot be one pass: a rule may name a field further down the
        // group — "show Room number if Format is In person" reads fine in either
        // order in the editor — so visibility cannot be settled while the value
        // map is still half-built. Deciding as you go evaluates half the rules
        // against a missing value, which reads as "empty" and flips the answer.
        // A ROW ON ITS PARENT'S EDITOR posts its own columns alone, and every
        // other field is checked as it is kept (`$kept`, by key), so the row is
        // held to all that this type's own editor holds the entry to.
        $sanitized = [];
        foreach ($this->contentType['fields'] as $field) {
            $sanitized[$field['key']] = $kept !== null && array_key_exists((string) $field['key'], $kept)
                ? $kept[$field['key']]
                : FieldRenderer::sanitizeField($field, $postData);
        }
        $shown = self::logicValues($this->contentType['fields'], $sanitized, $this->taxonomies, $postData);

        foreach ($this->contentType['fields'] as $field) {
            // A FIELD LOGIC HID IS NOT VALIDATED. Enforcing `required` on
            // something the author never saw makes the entry unsavable with an
            // error pointing at nothing on screen. The VALUE is still stored:
            // unlike a public form, an author may legitimately flip the trigger
            // back and forth while drafting, and discarding the hidden value
            // would silently delete what they typed a moment ago.
            if (!Logic::isVisible(Logic::parse($field['logic'] ?? null), $shown)) {
                continue;
            }
            $error = FieldRenderer::validateField($field, $sanitized[$field['key']]);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        $this->madeTitle = '';
        if (trim((string) ($postData['title'] ?? '')) === '') {
            $pattern = Patterns::titlePattern((string) $this->contentType['key']);
            if ($pattern !== '') {
                $this->madeTitle = Patterns::fill($pattern, (string) $this->contentType['key'], $sanitized, $this->postedTerms($postData), $excludeId);
            }
            if ($this->madeTitle === '') {
                // IN THE TYPE'S OWN WORD for its title, such as "Name is required."
                array_splice($errors, $titleAt, 0, [(Registry::ownTitleLabel((string) $this->contentType['key']) ?? 'Title') . ' is required.']);
            }
        }

        // ── A SKU NAMES ONE THING ────────────────────────────────────────────
        // `Product::skuProblems()` has the reasoning. Only on a type that sells,
        // and the product's own SKU only when its type kept that field.
        if (\Cms\Commerce\Product::isSellableType((string) $this->contentType['key'])) {
            $rows = isset($postData['variations_panel']) && is_array($postData['variations'] ?? null)
                ? $postData['variations'] : [];
            foreach (\Cms\Commerce\Product::skuProblems(
                (string) ($sanitized[\Cms\Commerce\Product::SKU] ?? ''), $rows, $excludeId
            ) as $problem) {
                $errors[] = $problem;
            }
        }

        return [$errors, $sanitized];
    }
}
