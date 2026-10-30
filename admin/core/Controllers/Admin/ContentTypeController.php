<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\ContentType\ArchetypeDefaults;
use Cms\ContentType\ContentTypeSchema;
use Cms\ContentType\FieldRenderer;
use Cms\ContentType\Registry;
use Cms\Forms\Logic;
use Cms\Helpers\Dates;
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
        $allCount  = $filtering ? Post::countOfType($contentType['key'], $authorFilter) : $total;

        // WHAT THE FILTER BAR OFFERS: only months and people that would return
        // something, and each attached taxonomy's own tree.
        $months       = Post::listMonths($contentType['key'], $authorFilter);
        $listAuthors  = $authorFilter === null ? Post::authorsOf($contentType['key']) : [];
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

        // BULK EDIT'S AUTHOR CHOICE is the entry form's: editors and up only.
        $bulkAuthors = Auth::hasRole('editor') ? User::authors() : null;

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
        [$parentType] = $this->parentContext(null);
        $parentTitles = $parentType === null ? [] : Post::titlesByIds(
            array_map(static fn (array $r): int => (int) ($r['parent_id'] ?? 0), $posts)
        );

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
        $authors = self::authorChoices($post ?? null);
        require $this->editTemplatePath();
    }

    public function handleCreate(array $vars = []): void
    {
        Auth::requireAuth();
        $user        = Auth::user();
        $contentType = $this->contentType;

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
            $authors = self::authorChoices($post ?? null);
        require $this->editTemplatePath();
            return;
        }

        [$status, $passwordHash] = $this->resolveStatusAndPassword($_POST, null);
        // The date the owner chose, or none — and a live entry with none is
        // stamped by the model with the moment it went live. A PRIVATE entry
        // is no longer stamped just for being saved: that stamp became its
        // publish date weeks later, back-dating the article to its draft day.
        $publishedAt = self::publishedAtFrom(null);

        // Slug: user-supplied or auto-generated from title.
        $rawSlug = self::sanitizeSlugInput($_POST['slug'] ?? '');
        $slug    = $rawSlug !== '' ? $rawSlug : Post::generateSlug(trim($_POST['title'] ?? ''));

        $id = Post::create([
            'post_type'         => $contentType['key'],
            'title'             => trim($_POST['title'] ?? ''),
            'slug'              => $slug,
            'content'           => in_array('content', $contentType['supports'], true)
                                     ? ($_POST['content'] ?? '') : '',
            'status'            => $status,
            'password_hash'     => $passwordHash,
            'featured_image_id' => !empty($_POST['featured_image_id']) ? (int) $_POST['featured_image_id'] : null,
            'published_at'      => $publishedAt,
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

        self::saveParent($id, $contentType);
        self::saveAuthor($id);

        Meta::saveFields($id, $contentType['fields'], $_POST);

        // Save SEO meta. The title box starts from the Entries pattern, so a
        // title still saying exactly that is stored as none (`Head::ownTitle()`)
        // and goes on following the pattern.
        Meta::set($id, '_seo_title',        Head::ownTitle((string) ($_POST['seo_title'] ?? ''), 'content_type') ?: null);
        Meta::set($id, '_meta_description', trim($_POST['meta_description'] ?? '') ?: null);
        self::saveSearchAndSocial($id);

        // Save taxonomy term assignments (sidebar panels)
        foreach ($this->taxonomies as $taxKey => $tax) {
            $rawIds  = $_POST['taxonomy_' . $taxKey] ?? [];
            $termIds = is_array($rawIds) ? array_map('intval', $rawIds) : [];
            Term::setPostTerms($id, $taxKey, $termIds);
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
        $authors = self::authorChoices($post ?? null);
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

        [$errors, $sanitizedMeta] = $this->validateAndSanitize($_POST, (int) $vars['id']);

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
            $authors = self::authorChoices($post ?? null);
        require $this->editTemplatePath();
            return;
        }

        [$status, $passwordHash] = $this->resolveStatusAndPassword($_POST, $post);
        $publishedAt = self::publishedAtFrom($post);

        // Slug: user-supplied or auto-generated from title.
        $rawSlug = self::sanitizeSlugInput($_POST['slug'] ?? '');
        $slug    = $rawSlug !== ''
            ? $rawSlug
            : Post::generateSlug(trim($_POST['title'] ?? ''), (int) $vars['id']);

        // Capture what is about to be overwritten, so the edit is reversible.
        Revision::snapshot(Revision::TYPE_POST, (int) $vars['id'], $user['id']);
        Revision::prune(Revision::TYPE_POST, (int) $vars['id']);

        Post::update((int) $vars['id'], [
            'title'             => trim($_POST['title'] ?? ''),
            'slug'              => $slug,
            'content'           => in_array('content', $contentType['supports'], true)
                                     ? ($_POST['content'] ?? '') : '',
            'status'            => $status,
            'password_hash'     => $passwordHash,
            'featured_image_id' => !empty($_POST['featured_image_id']) ? (int) $_POST['featured_image_id'] : null,
            'published_at'      => $publishedAt,
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
        // entry: nobody outside could have linked to it.
        $publicSlug = (string) ($contentType['public_slug'] ?? '');
        if ($publicSlug !== '' && $slug !== (string) $post['slug']
            && in_array((string) $post['status'], ['published', 'password'], true)
            && !Post::isScheduled($post)) {
            \Cms\SEO\Redirects::moved('/' . $publicSlug . '/' . $post['slug'], '/' . $publicSlug . '/' . $slug);
        }

        self::saveParent((int) $vars['id'], $contentType);
        self::saveAuthor((int) $vars['id']);

        Meta::saveFields((int) $vars['id'], $contentType['fields'], $_POST);

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
        if (!empty($_POST['variations_panel'])
            && in_array('product', (array) ($contentType['archetypes'] ?? []), true)) {
            $rows = $_POST['variations'] ?? [];
            \Cms\Commerce\Variation::sync(
                (int) $vars['id'],
                is_array($rows) ? $rows : []
            );
        }

        // Save SEO meta — a title still saying the pattern is stored as none.
        Meta::set((int) $vars['id'], '_seo_title',        Head::ownTitle((string) ($_POST['seo_title'] ?? ''), 'content_type') ?: null);
        Meta::set((int) $vars['id'], '_meta_description', trim($_POST['meta_description'] ?? '') ?: null);
        self::saveSearchAndSocial((int) $vars['id']);

        // Save taxonomy term assignments (sidebar panels)
        foreach ($this->taxonomies as $taxKey => $tax) {
            $rawIds  = $_POST['taxonomy_' . $taxKey] ?? [];
            $termIds = is_array($rawIds) ? array_map('intval', $rawIds) : [];
            Term::setPostTerms((int) $vars['id'], $taxKey, $termIds);
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
        $authors = self::authorChoices($post ?? null);
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

        // WHAT THE EDIT PANEL ASKS FOR, read once for the whole selection.
        $request = $action === 'edit' ? $this->bulkEditRequest() : null;

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
     * @return array{terms: array<string, array{set?: int, on?: list<int>, keep?: list<int>}>,
     *               status: string, author: ?int}|null
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

        $status = in_array($_POST['bulk_status'] ?? '', ['published', 'private'], true)
            ? (string) $_POST['bulk_status'] : '';

        // Editors and up choose an author, as on the entry form; anyone else's
        // choice is not read at all.
        $author = null;
        if (Auth::hasRole('editor')) {
            $want = (int) ($_POST['bulk_author'] ?? 0);
            if ($want > 0 && User::find($want) !== null) {
                $author = $want;
            }
        }

        return ($terms === [] && $status === '' && $author === null)
            ? null
            : ['terms' => $terms, 'status' => $status, 'author' => $author];
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

            $problem = Term::countViolation((string) $taxKey, $new);
            if ($problem !== null) {
                return $problem;
            }
            $filings[(string) $taxKey] = $new;
        }

        if ($request['status'] !== '' && $request['status'] !== (string) $post['status']) {
            // Reversible like a save: the revision is taken first.
            Revision::snapshot(Revision::TYPE_POST, $id, (int) (Auth::user()['id'] ?? 0));
            Revision::prune(Revision::TYPE_POST, $id);
            // Leaving `password` drops the password, as the entry form does
            // (resolveStatusAndPassword() stores one only for that status).
            Post::update($id, ['status' => $request['status']]
                + ((string) $post['status'] === 'password' ? ['password_hash' => null] : []));
        }

        if ($request['author'] !== null && $request['author'] !== (int) $post['author_id']) {
            Post::setAuthor($id, (int) $request['author']);
        }

        foreach ($filings as $taxKey => $new) {
            Term::setPostTerms($id, $taxKey, $new);
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
     *               sort: array{orderby: string, order: string}, filtering: bool,
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
        // and up; for an author it could only ever narrow to nothing.
        $author = ($authorOnly === null && ctype_digit($get('author')) && (int) $get('author') > 0)
            ? (int) $get('author') : null;

        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $get('month')) === 1 ? $get('month') : '';

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

        // 1–999 a page.
        $perPage = (int) ($_SESSION['entry_list_per_page'] ?? 20);
        if (ctype_digit($get('per_page'))) {
            $perPage = (int) $get('per_page');
        }
        $perPage = max(1, min(999, $perPage));
        $_SESSION['entry_list_per_page'] = $perPage;

        // A column's FIRST click sorts the way that column is read: titles
        // A→Z, dates newest first.
        $orderby = in_array($get('orderby'), ['title', 'date'], true) ? $get('orderby') : '';
        $order   = '';
        if ($orderby !== '') {
            $order = in_array($get('order'), ['asc', 'desc'], true)
                ? $get('order') : ($orderby === 'title' ? 'asc' : 'desc');
        }

        $page = ctype_digit($get('page')) ? max(1, (int) $get('page')) : 1;

        // ── THE STATE A LINK CARRIES ───────────────────────────────────────
        // Sorting keeps the filters, paging keeps both, and filtering starts
        // again at page 1. Empty values are left out, so an untouched list's
        // address is just its address.
        $state = ['s' => $search, 'status' => $status, 'author' => $author, 'month' => $month];
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
                'terms'        => $branches,
                'orderby'      => $orderby,
                'order'        => $order,
                'hand_ordered' => ArchetypeDefaults::isHandOrdered($this->contentType['archetypes'] ?? []),
                'page'         => $page,
                'per_page'     => $perPage,
            ],
            'filters' => [
                's'        => $search,
                'status'   => $status,
                'author'   => $author,
                'month'    => $month,
                'terms'    => $terms,
                'per_page' => $perPage,
            ],
            'sort'      => ['orderby' => $orderby, 'order' => $order],
            'filtering' => $search !== '' || $status !== '' || $author !== null || $month !== '' || $terms !== [],
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
        $allowed = ['published', 'password', 'private'];
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
    private static function authorChoices(?array $post): ?array
    {
        if (!Auth::hasRole('editor')) {
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
    private static function saveAuthor(int $id): void
    {
        if (empty($_POST['author_panel']) || !Auth::hasRole('editor')) {
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
        if (!array_key_exists('published_local', $_POST) || is_array($_POST['published_local'])) {
            return $existing['published_at'] ?? null;
        }
        $utc = Dates::fromInput((string) $_POST['published_local']);
        return is_string($utc) ? $utc : null;
    }

    private static function sanitizeSlugInput(string $raw): string
    {
        return \Cms\Helpers\Slug::clean($raw);
    }

    /**
     * Validate base fields (title required) + all Content Type custom fields.
     *
     * @param array<string, mixed> $postData
     * @return array{0: string[], 1: array<string, mixed>}  [errors, sanitizedMetaValues]
     */
    private function validateAndSanitize(array $postData, ?int $excludeId = null): array
    {
        $errors = [];

        // ── AN ADDRESS SOMETHING ELSE ALREADY HAS ────────────────────────────
        // `posts.slug` is UNIQUE across every type and the trash, so a typed
        // slug that clashed reached the INSERT and came back as a 500 — the
        // Pages form refused it with a sentence, this one with a stack trace.
        // Refused here now, naming what is in the way.
        $typed = self::sanitizeSlugInput((string) ($postData['slug'] ?? ''));
        if ($typed !== '' && ($owner = Post::slugOwner($typed, $excludeId)) !== null) {
            $ownerType = Registry::get((string) $owner['post_type']);
            $what      = $ownerType !== null ? strtolower((string) ($ownerType['label_single'] ?? $owner['post_type'])) : (string) $owner['post_type'];
            $errors[]  = (string) $owner['status'] === 'trash'
                ? 'The address "' . $typed . '" belongs to the ' . $what . ' "' . $owner['title'] . '", which is in the trash. Restore it or empty the trash to use the address here.'
                : 'The address "' . $typed . '" is already used by the ' . $what . ' "' . $owner['title'] . '".';
        }

        if (trim((string) ($postData['title'] ?? '')) === '') {
            $errors[] = 'Title is required.';
        }

        // ── HOW MANY TERMS EACH TAXONOMY TAKES ──────────────────────────────
        // Exactly one, at least one, no more than N — the taxonomy's rule,
        // refused here with its words so the form comes back with what was
        // typed and ticked. `Term::setPostTerms()` refuses the same set for
        // every caller that does not ask first.
        foreach ($this->taxonomies as $taxKey => $tax) {
            $raw     = $postData['taxonomy_' . $taxKey] ?? [];
            $problem = Term::countViolation((string) $taxKey, is_array($raw) ? $raw : []);
            if ($problem !== null) {
                $errors[] = $problem;
            }
        }

        if (array_key_exists('published_local', $postData)
            && !is_array($postData['published_local'])
            && Dates::fromInput((string) $postData['published_local']) === false) {
            $errors[] = 'The publish date is not a date and time.';
        }

        // TWO PASSES, AND THE ORDER MATTERS. Sanitise everything first, then
        // decide what conditional logic actually showed, then validate only
        // that. It cannot be one pass: a rule may name a field further down the
        // group — "show Room number if Format is In person" reads fine in either
        // order in the editor — so visibility cannot be settled while the value
        // map is still half-built. Deciding as you go evaluates half the rules
        // against a missing value, which reads as "empty" and flips the answer.
        $sanitized = [];
        foreach ($this->contentType['fields'] as $field) {
            $sanitized[$field['key']] = FieldRenderer::sanitizeField($field, $postData);
        }

        foreach ($this->contentType['fields'] as $field) {
            // A FIELD LOGIC HID IS NOT VALIDATED. Enforcing `required` on
            // something the author never saw makes the entry unsavable with an
            // error pointing at nothing on screen. The VALUE is still stored:
            // unlike a public form, an author may legitimately flip the trigger
            // back and forth while drafting, and discarding the hidden value
            // would silently delete what they typed a moment ago.
            if (!Logic::isVisible(Logic::parse($field['logic'] ?? null), $sanitized)) {
                continue;
            }
            $error = FieldRenderer::validateField($field, $sanitized[$field['key']]);
            if ($error !== null) {
                $errors[] = $error;
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
