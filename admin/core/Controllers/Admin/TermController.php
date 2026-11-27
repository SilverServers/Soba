<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\ContentType\FieldRenderer;
use Cms\ContentType\TaxonomyRegistry;
use Cms\ContentType\TermFields;
use Cms\Forms\Logic;
use Cms\Models\Term;

/**
 * TermController — admin CRUD for terms within a specific taxonomy.
 *
 * Receives the taxonomy key via constructor (3-element route handler pattern,
 * same as ContentTypeController). All routes are under
 * /admin/taxonomies/{admin_slug}/terms, registered per taxonomy at bootstrap
 * from the REGISTRY — so a taxonomy in `config/taxonomies.php` gets the same
 * screens as one created through the admin, from the same code. One path in:
 * there is no privileged back door and no read-only category.
 *
 * `requireRole('editor')` and not `'admin'`: filing a story under a category is
 * ordinary editorial work, where creating the taxonomy itself is structural.
 * That line is the difference between the two, and it is deliberate.
 */
class TermController
{
    private array $taxonomy;

    public function __construct(?string $taxonomyKey = null)
    {
        $taxonomy = $taxonomyKey !== null ? TaxonomyRegistry::get($taxonomyKey) : null;

        if ($taxonomy === null) {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            exit;
        }

        $this->taxonomy = $taxonomy;
    }

    // ----------------------------------------------------------------
    // List
    // ----------------------------------------------------------------

    public function index(array $vars = []): void
    {
        Auth::requireRole('editor');

        $taxonomy            = $this->taxonomy;
        $terms               = Term::allForTaxonomy($taxonomy['key']);
        $termTree            = $taxonomy['hierarchical'] ? Term::treeForTaxonomy($taxonomy['key']) : [];
        $pageTitle           = $taxonomy['label'];
        $currentPage         = 'taxonomies';
        $currentTaxonomySlug = $this->taxonomy['admin_slug'];
        // Add only for whoever may add a term here (`StaffView::addsTerms()`).
        $canAdd              = \Cms\ContentType\StaffView::addsTerms((string) $taxonomy['key']);

        // What the last bulk Delete or Merge did — once.
        $bulkReport = $_SESSION['term_bulk_report'] ?? null;
        unset($_SESSION['term_bulk_report']);
        if (!is_array($bulkReport) || ($bulkReport['taxonomy'] ?? '') !== $taxonomy['key']) {
            $bulkReport = null;
        }

        ob_start();
        require ROOT_PATH . '/admin/templates/terms/list.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    // ----------------------------------------------------------------
    // Create
    /**
     * WHO MAY ADD A TERM HERE is the taxonomy's (`ContentType\StaffView::
     * addsTerms()`), asked by the entry's row and the API alike; anyone else is
     * sent back to the terms, where editing the ones there are stays an
     * editor's. Nothing is made.
     */
    private function requireAdds(): void
    {
        if (!\Cms\ContentType\StaffView::addsTerms((string) $this->taxonomy['key'])) {
            header('Location: ' . ADMIN_URL . '/taxonomies/' . $this->taxonomy['admin_slug'] . '/terms', true, 303);
            exit;
        }
    }

    // ----------------------------------------------------------------

    public function showCreate(array $vars = []): void
    {
        Auth::requireRole('editor');
        $this->requireAdds();

        $taxonomy            = $this->taxonomy;
        $term                = null;
        $formData            = [];
        $errors              = [];
        $allTerms            = $taxonomy['hierarchical'] ? Term::allForTaxonomy($taxonomy['key']) : [];
        $pageTitle           = 'Add ' . $taxonomy['label_single'];
        $currentPage         = 'taxonomies';
        $currentTaxonomySlug = $this->taxonomy['admin_slug'];

        ob_start();
        require ROOT_PATH . '/admin/templates/terms/edit.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    public function handleCreate(array $vars = []): void
    {
        Auth::requireRole('editor');
        $this->requireAdds();

        [$errors, $data] = $this->validateAndSanitize($_POST);

        if (!empty($errors)) {
            $taxonomy            = $this->taxonomy;
            $term                = null;
            $formData            = $_POST;
            $allTerms            = $taxonomy['hierarchical'] ? Term::allForTaxonomy($taxonomy['key']) : [];
            $pageTitle           = 'Add ' . $taxonomy['label_single'];
            $currentPage         = 'taxonomies';
            $currentTaxonomySlug = $this->taxonomy['admin_slug'];

            ob_start();
            require ROOT_PATH . '/admin/templates/terms/edit.php';
            $pageContent = ob_get_clean();

            require ROOT_PATH . '/admin/templates/layout.php';
            return;
        }

        $meta = $data['meta'];
        unset($data['meta']);
        Term::setMeta(Term::create($data), $meta);

        $adminSlug = $this->taxonomy['admin_slug'];
        header('Location: ' . ADMIN_URL . '/taxonomies/' . $adminSlug . '/terms');
        exit;
    }

    // ----------------------------------------------------------------
    // Edit
    // ----------------------------------------------------------------

    public function showEdit(array $vars = []): void
    {
        Auth::requireRole('editor');

        $taxonomy = $this->taxonomy;
        $term     = Term::find((int) $vars['id']);

        if ($term === null || $term['taxonomy_key'] !== $taxonomy['key']) {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }

        // For hierarchical: build parent selector (excluding self + descendants)
        $allTerms = [];
        if ($taxonomy['hierarchical']) {
            $excludeIds = array_merge(
                [(int) $term['id']],
                Term::getDescendantIds((int) $term['id'], $taxonomy['key'])
            );
            $allTerms = array_filter(
                Term::allForTaxonomy($taxonomy['key']),
                fn(array $t): bool => !in_array((int) $t['id'], $excludeIds, true)
            );
        }

        $formData            = [];
        $errors              = [];
        $pageTitle           = 'Edit ' . $taxonomy['label_single'];
        $currentPage         = 'taxonomies';
        $currentTaxonomySlug = $this->taxonomy['admin_slug'];

        ob_start();
        require ROOT_PATH . '/admin/templates/terms/edit.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    public function handleUpdate(array $vars = []): void
    {
        Auth::requireRole('editor');

        $taxonomy = $this->taxonomy;
        $term     = Term::find((int) $vars['id']);

        if ($term === null || $term['taxonomy_key'] !== $taxonomy['key']) {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }

        [$errors, $data] = $this->validateAndSanitize($_POST, (int) $vars['id']);

        if (!empty($errors)) {
            $allTerms = [];
            if ($taxonomy['hierarchical']) {
                $excludeIds = array_merge(
                    [(int) $term['id']],
                    Term::getDescendantIds((int) $term['id'], $taxonomy['key'])
                );
                $allTerms = array_filter(
                    Term::allForTaxonomy($taxonomy['key']),
                    fn(array $t): bool => !in_array((int) $t['id'], $excludeIds, true)
                );
            }

            $formData            = $_POST;
            $pageTitle           = 'Edit ' . $taxonomy['label_single'];
            $currentPage         = 'taxonomies';
            $currentTaxonomySlug = $this->taxonomy['admin_slug'];

            ob_start();
            require ROOT_PATH . '/admin/templates/terms/edit.php';
            $pageContent = ob_get_clean();

            require ROOT_PATH . '/admin/templates/layout.php';
            return;
        }

        $meta = $data['meta'];
        unset($data['meta']);
        Term::update((int) $vars['id'], $data);
        Term::setMeta((int) $vars['id'], $meta);

        // A PUBLIC TERM, RENAMED, LEAVES A 301 BEHIND IT — the same rule as an
        // entry (Redirects::moved()). Its archive's address is the one thing a
        // visitor bookmarks; `$term` is still the row as it was before.
        // A term is both languages' (`Site\Language`), so its page in the
        // second language, under its prefix, leaves one too.
        $taxPublic = (string) ($taxonomy['public_slug'] ?? '');
        if ($taxPublic !== '' && (string) $data['slug'] !== (string) $term['slug']) {
            foreach ($this->languagePrefixes() as $in) {
                \Cms\SEO\Redirects::moved($in . '/' . $taxPublic . '/' . $term['slug'], $in . '/' . $taxPublic . '/' . $data['slug']);
            }
        }

        $term     = Term::find((int) $vars['id']);
        $formData = [];
        $errors   = [];
        $saved    = true;
        $allTerms = [];
        if ($taxonomy['hierarchical']) {
            $excludeIds = array_merge(
                [(int) $term['id']],
                Term::getDescendantIds((int) $term['id'], $taxonomy['key'])
            );
            $allTerms = array_filter(
                Term::allForTaxonomy($taxonomy['key']),
                fn(array $t): bool => !in_array((int) $t['id'], $excludeIds, true)
            );
        }

        $pageTitle           = 'Edit ' . $taxonomy['label_single'];
        $currentPage         = 'taxonomies';
        $currentTaxonomySlug = $this->taxonomy['admin_slug'];

        ob_start();
        require ROOT_PATH . '/admin/templates/terms/edit.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    // ----------------------------------------------------------------
    // Delete
    // ----------------------------------------------------------------

    public function handleDelete(array $vars = []): void
    {
        Auth::requireRole('editor');

        $term = Term::find((int) $vars['id']);

        if ($term === null || $term['taxonomy_key'] !== $this->taxonomy['key']) {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }

        Term::delete((int) $vars['id']);

        $adminSlug = $this->taxonomy['admin_slug'];
        header('Location: ' . ADMIN_URL . '/taxonomies/' . $adminSlug . '/terms');
        exit;
    }

    // ----------------------------------------------------------------
    // Bulk — delete several, or merge them into one
    // ----------------------------------------------------------------

    /**
     * POST /admin/taxonomies/{slug}/terms/bulk
     *
     *   delete  each ticked term is deleted
     *   merge   fold each ticked term into one target — `Term::merge()`: its
     *           entries are filed under the target through the taxonomy's rule,
     *           its children move under the target, its menu items point at the
     *           target, and it is deleted. Then its ARCHIVE'S ADDRESS 301s to the
     *           target's, the rule a rename already follows, so links to the
     *           merged category's address keep working.
     *
     * Only this taxonomy's terms are acted on; an id from another is ignored.
     */
    public function handleBulk(array $vars = []): void
    {
        Auth::requireRole('editor');
        $taxonomy = $this->taxonomy;
        $action   = (string) ($_POST['bulk_action'] ?? '');

        $terms = [];
        foreach (is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [] as $raw) {
            $term = Term::find((int) $raw);
            if ($term !== null && (string) $term['taxonomy_key'] === (string) $taxonomy['key']) {
                $terms[(int) $term['id']] = $term;
            }
        }

        $report = ['taxonomy' => (string) $taxonomy['key'], 'action' => $action,
                   'done' => 0, 'into' => '', 'skipped' => []];

        if ($action === 'delete') {
            foreach ($terms as $id => $term) {
                Term::delete($id);
                $report['done']++;
            }
        } elseif ($action === 'merge') {
            $into = Term::find((int) ($_POST['merge_into'] ?? 0));
            if ($into === null || (string) $into['taxonomy_key'] !== (string) $taxonomy['key']) {
                $report['skipped'][] = ['title' => '', 'reason' => 'Choose the '
                    . strtolower((string) $taxonomy['label_single']) . ' to merge them into.'];
            } else {
                $report['into'] = (string) $into['name'];
                $public = (string) ($taxonomy['public_slug'] ?? '');
                foreach ($terms as $id => $term) {
                    // The target among the ticked ones is where the rest go, not
                    // one of them.
                    if ($id === (int) $into['id']) {
                        continue;
                    }
                    $refused = Term::merge($id, (int) $into['id']);
                    if ($refused !== []) {
                        foreach ($refused as $why) {
                            $report['skipped'][] = [
                                'title'  => (string) $term['name'] . ($why['title'] !== '' ? ' — ' . $why['title'] : ''),
                                'reason' => $why['reason'],
                            ];
                        }
                        continue;
                    }
                    if ($public !== '') {
                        foreach ($this->languagePrefixes() as $in) {
                            \Cms\SEO\Redirects::moved($in . '/' . $public . '/' . $term['slug'], $in . '/' . $public . '/' . $into['slug']);
                        }
                    }
                    $report['done']++;
                }
            }
        }

        $_SESSION['term_bulk_report'] = $report;
        header('Location: ' . ADMIN_URL . '/taxonomies/' . $taxonomy['admin_slug'] . '/terms');
        exit;
    }

    // ----------------------------------------------------------------
    // Validation
    // ----------------------------------------------------------------

    /**
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    private function validateAndSanitize(array $post, ?int $excludeId = null): array
    {
        $errors      = [];
        $taxonomy    = $this->taxonomy;
        $taxonomyKey = $taxonomy['key'];

        $name        = trim($post['name']        ?? '');
        // THE SAME SHAPE AS EVERY OTHER SLUG (Helpers\Slug). This stored what
        // was typed — "Club News" with its space and capitals — which made a
        // public term address that nothing could reach.
        $slug        = \Cms\Helpers\Slug::clean((string) ($post['slug'] ?? ''));
        $description = trim($post['description'] ?? '');
        $sortOrder   = max(0, (int) ($post['sort_order'] ?? 0));

        // parent_id — only relevant for hierarchical taxonomies
        $parentId = null;
        if ($taxonomy['hierarchical']) {
            $rawParent = trim($post['parent_id'] ?? '');
            if ($rawParent !== '' && $rawParent !== '0') {
                $parentId = (int) $rawParent;
            }
        }

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        // Auto-generate slug from name if blank
        if ($slug === '' && $name !== '') {
            $slug = Term::generateSlug($name, $taxonomyKey, $excludeId);
        } elseif ($slug !== '') {
            // Validate uniqueness of manually entered slug
            $existing = Term::findBySlug($taxonomyKey, $slug);
            if ($existing !== null && (int) $existing['id'] !== $excludeId) {
                $errors['slug'] = 'This slug is already in use within this taxonomy.';
            }
        }

        // Validate parent_id belongs to the same taxonomy
        if ($parentId !== null) {
            $parentTerm = Term::find($parentId);
            if ($parentTerm === null || $parentTerm['taxonomy_key'] !== $taxonomyKey) {
                $errors['parent_id'] = 'Invalid parent term.';
                $parentId = null;
            }
        }

        // ITS OWN FIELDS (`TermFields`), checked as an entry's are, in two
        // passes: every one sanitized, then those its logic shows validated,
        // since a rule may name a field further down.
        $fields = TermFields::fields((string) $taxonomyKey);
        $meta   = [];
        foreach ($fields as $field) {
            $meta[(string) $field['key']] = FieldRenderer::sanitizeField($field, $post);
        }
        foreach ($fields as $field) {
            if (!Logic::isVisible(Logic::parse($field['logic'] ?? null), $meta)) {
                continue;
            }
            $error = FieldRenderer::validateField($field, $meta[(string) $field['key']]);
            if ($error !== null) {
                $errors['field_' . $field['key']] = $error;
            }
        }

        $data = [
            'taxonomy_key' => $taxonomyKey,
            // RAW, NOT ESCAPED — every reader escapes at output. Storing escaped
            // text would show `&amp;` where the author typed `&`, and because a
            // save re-posts what the form rendered, each save would escape the
            // previous escape: `&` → `&amp;` → `&amp;amp;`, compounding with
            // nothing to stop it. Escape at OUTPUT, never on the way in.
            'name'         => $name,
            'slug'         => $slug,
            'description'  => $description,
            'parent_id'    => $parentId,
            'sort_order'   => $sortOrder,
            'meta'         => $meta,
        ];

        return [$errors, $data];
    }

    /**
     * The prefixes this taxonomy's term pages are at: '' for the site's own
     * language, and the second language's (`/fr`) when the site has one and
     * a type the taxonomy files is translated.
     *
     * @return list<string>
     */
    private function languagePrefixes(): array
    {
        $second = \Cms\Site\Language::prefix();

        return $second !== '' && \Cms\Content\Translation::taxonomyTranslates((string) $this->taxonomy['key']) ? ['', '/' . $second] : [''];
    }
}
