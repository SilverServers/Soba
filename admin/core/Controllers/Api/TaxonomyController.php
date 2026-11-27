<?php

declare(strict_types=1);

namespace Cms\Controllers\Api;

use Cms\Auth\Auth;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Http\Cache;
use Cms\Models\Post;
use Cms\Models\Term;

/**
 * API TaxonomyController — JSON endpoints for taxonomy term operations.
 *
 * Provides:
 *   GET  /api/v1/taxonomies/{key}/terms          — list / search terms (typeahead)
 *   POST /api/v1/taxonomies/{key}/terms          — create a new term
 *   GET  /api/v1/posts/{postId}/terms/{key}      — get assigned terms for a post
 *   POST /api/v1/posts/{postId}/terms/{key}      — set assigned terms for a post
 */
class TaxonomyController
{
    // ----------------------------------------------------------------
    // GET /api/v1/taxonomies/{key}/terms
    // ----------------------------------------------------------------

    /**
     * List or search terms for a taxonomy.
     * Pass ?q=search_string for typeahead (min 1 char, returns up to 20 results).
     * Without ?q, returns all terms (useful for populating selects).
     */
    public function listTerms(array $vars = []): void
    {
        Auth::requireAuth();

        $key      = $vars['key'] ?? '';
        $taxonomy = TaxonomyRegistry::get($key);

        if ($taxonomy === null) {
            $this->json(['success' => false, 'error' => 'Taxonomy not found.'], 404);
        }

        $query = trim($_GET['q'] ?? '');

        if ($query !== '') {
            $terms = Term::search($key, $query, 20);
        } else {
            $terms = Term::allForTaxonomy($key);
        }

        $this->json(['success' => true, 'data' => ['terms' => $terms]]);
    }

    // ----------------------------------------------------------------
    // POST /api/v1/taxonomies/{key}/terms
    // ----------------------------------------------------------------

    /**
     * Create a new term within a taxonomy.
     * Used by the tag-input JS when the user types a new tag that doesn't exist yet.
     *
     * Body (JSON or form): { name: "New Tag" }
     * Returns: { success: true, data: { term: { id, name, slug } } }
     */
    public function createTerm(array $vars = []): void
    {
        Auth::requireAuth();

        $key      = $vars['key'] ?? '';
        $taxonomy = TaxonomyRegistry::get($key);

        if ($taxonomy === null) {
            $this->json(['success' => false, 'error' => 'Taxonomy not found.'], 404);
        }

        // A CATEGORY IS STRUCTURE; A TAG IS FILING. The line is drawn there
        // unless the taxonomy says otherwise (`StaffView::addsTerms()`): an
        // author may make a tag while filing an entry, and only an editor adds
        // a category, because a category is what the whole site is navigated
        // by. The entry's row and the terms screen ask the same, or this would
        // be the side door.
        if (!\Cms\ContentType\StaffView::addsTerms((string) $key)) {
            $who = \Cms\ContentType\StaffView::addTermsRole((string) $key);
            $this->json(['success' => false, 'error' => ($who === false
                ? 'No ' . strtolower((string) $taxonomy['label']) . ' are added here. File this under one that exists.'
                : 'Only ' . \Cms\ContentType\StaffView::who($who) . ' can add a ' . strtolower((string) $taxonomy['label_single'])
                    . '. File this under one that exists, or ask ' . \Cms\ContentType\StaffView::who($who) . ' to add it.')], 403);
        }

        // Accept JSON or form-encoded body
        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $body = (array) json_decode(file_get_contents('php://input') ?: '', true);
        } else {
            $body = $_POST;
        }

        $name = trim($body['name'] ?? '');

        if ($name === '') {
            $this->json(['success' => false, 'error' => 'Term name is required.'], 422);
        }

        // WHERE IT SITS, on a nested taxonomy — the entry form's Add asks. A
        // parent must be a term of THIS taxonomy; anything else is refused by
        // name rather than quietly filed at the top.
        $parentId = (int) ($body['parent_id'] ?? 0);
        if ($parentId > 0 && (empty($taxonomy['hierarchical']) || Term::idsOutside($key, [$parentId]) !== [])) {
            $this->json(['success' => false, 'error' => 'That parent is not a '
                . strtolower((string) $taxonomy['label_single']) . ' it can sit under.'], 422);
        }

        $slug = Term::generateSlug($name, $key);

        // RAW, NOT ESCAPED. Storing `htmlspecialchars($name)` would be
        // escape-on-STORE, and wrong twice: every reader escapes again at output
        // (`View::escape`, `h()`), so an author who typed "Talks & panels" would
        // see "Talks &amp; panels"; and it is not idempotent, so a name that ever
        // round-trips through an edit form compounds — `&` → `&amp;` →
        // `&amp;amp;`, forever, with nothing to stop it.
        //
        // `FieldGroupController` explains the same rule at length: escape at
        // OUTPUT.
        $id = Term::create([
            'taxonomy_key' => $key,
            'name'         => $name,
            'slug'         => $slug,
            'description'  => null,
            'parent_id'    => $parentId > 0 ? $parentId : null,
            'sort_order'   => 0,
        ]);

        $term = Term::find($id);
        $this->json(['success' => true, 'data' => ['term' => [
            'id'        => (int) $term['id'],
            'name'      => $term['name'],
            'slug'      => $term['slug'],
            'parent_id' => (int) ($term['parent_id'] ?? 0),
        ]]]);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/posts/{postId}/terms/{key}
    // ----------------------------------------------------------------

    /**
     * Return all terms assigned to a specific post for a specific taxonomy.
     * Returns: { success: true, data: { terms: [{id, name, slug},...] } }
     */
    public function getPostTerms(array $vars = []): void
    {
        Auth::requireAuth();

        $postId   = (int) ($vars['postId'] ?? 0);
        $key      = $vars['key'] ?? '';
        $taxonomy = TaxonomyRegistry::get($key);

        if ($taxonomy === null) {
            $this->json(['success' => false, 'error' => 'Taxonomy not found.'], 404);
        }

        $terms = Term::getPostTerms($postId, $key);

        $this->json(['success' => true, 'data' => ['terms' => $terms]]);
    }

    // ----------------------------------------------------------------
    // POST /api/v1/posts/{postId}/terms/{key}
    // ----------------------------------------------------------------

    /**
     * Set (replace) term assignments for a post/taxonomy pair.
     * Body: { term_ids: [1, 3, 7] }
     * Returns: { success: true, data: null }
     *
     * Note: The CPT edit form save path (via CptController) is the primary assignment
     * mechanism. This endpoint exists for programmatic use and future React integration.
     */
    public function setPostTerms(array $vars = []): void
    {
        Auth::requireAuth();

        $postId   = (int) ($vars['postId'] ?? 0);
        $key      = $vars['key'] ?? '';
        $taxonomy = TaxonomyRegistry::get($key);

        if ($taxonomy === null) {
            $this->json(['success' => false, 'error' => 'Taxonomy not found.'], 404);
        }

        // RE-FILING AN ENTRY IS CHANGING IT. This checked sign-in only, so an
        // author could move anyone's entry between categories by posting here —
        // the entry screen refuses them, and this was the side door.
        // `Auth::canChange()` is the one rule every entry write path asks.
        $post = Post::find($postId);
        if ($post === null) {
            $this->json(['success' => false, 'error' => 'Entry not found.'], 404);
        }
        if (!Auth::canChange($post)) {
            $this->json(['success' => false, 'error' => 'Only an editor can change this entry.'], 403);
        }

        // Accept JSON or form-encoded body
        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $body = (array) json_decode(file_get_contents('php://input') ?: '', true);
        } else {
            $body = $_POST;
        }

        $rawIds  = $body['term_ids'] ?? [];
        $termIds = is_array($rawIds) ? array_map('intval', $rawIds) : [];

        // ANOTHER TAXONOMY'S TERM IS REFUSED BY NAME. The model drops such ids
        // for every caller; a request that sent one is told which, rather than
        // having part of what it asked for vanish.
        $foreign = Term::idsOutside($key, $termIds);
        if ($foreign !== []) {
            $this->json(['success' => false, 'error' => (count($foreign) === 1 ? 'Term ' : 'Terms ')
                . implode(', ', $foreign) . ' '
                . (count($foreign) === 1 ? 'is not a ' : 'are not ')
                . strtolower((string) (count($foreign) === 1 ? $taxonomy['label_single'] : $taxonomy['label']))
                . '. Nothing was changed.'], 422);
        }

        // THE RULE, the entry's type's or the taxonomy's, in its own words —
        // asked before writing, so a
        // refusal is a 422 that says why rather than the model's exception
        // surfacing as a 500.
        $problem = Term::countViolation($key, $termIds, (string) $post['post_type']);
        if ($problem !== null) {
            $this->json(['success' => false, 'error' => $problem], 422);
        }

        Term::setPostTerms($postId, $key, $termIds, (string) $post['post_type']);

        $this->json(['success' => true, 'data' => null]);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * Output a JSON response and exit.
     *
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        exit;
    }
}
