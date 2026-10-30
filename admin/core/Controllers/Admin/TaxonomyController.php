<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Models\Taxonomy;

/**
 * TaxonomyController — admin CRUD for taxonomy definitions.
 *
 * A taxonomy is a way of filing entries: Categories on News, Club types on
 * Clubs. It is one of the system's existing mechanisms — when something needs
 * grouping, the question is whether a taxonomy already holds it, never what new
 * store to add.
 *
 * Code-registered taxonomies (`config/taxonomies.php`) are merged into the list
 * from the REGISTRY, not read out of the table: the list answers what exists in
 * this install, not what is in one table. They are NOT rendered as editable
 * rows: nothing here can change a file. They keep the actions they genuinely
 * have and an `.alert--info` says where they live, as `content-types/list.php`
 * and `field-groups/list.php` do too.
 */
class TaxonomyController
{
    // ----------------------------------------------------------------
    // List
    // ----------------------------------------------------------------

    public function index(array $vars = []): void
    {
        Auth::requireRole('admin');

        $dbTaxonomies    = Taxonomy::all();
        $allContentTypes = Registry::all();

        // Same defect as Content Types and Field Groups: code-registered
        // taxonomies (config/taxonomies.php) never appeared here, because this
        // screen read only the `taxonomies` table.
        $dbKeys = array_column($dbTaxonomies, 'taxonomy_key');
        $codeTaxonomies = array_filter(
            TaxonomyRegistry::all(),
            static fn (array $tx): bool => !in_array($tx['key'], $dbKeys, true)
        );
        $pageTitle       = 'Taxonomies';
        $currentPage     = 'taxonomies';

        ob_start();
        require ROOT_PATH . '/admin/templates/taxonomies/list.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    // ----------------------------------------------------------------
    // Create
    // ----------------------------------------------------------------

    public function showCreate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $taxonomy    = null;
        $formData    = [];
        $errors      = [];
        $allContentTypes = Registry::all();
        $pageTitle   = 'Add Taxonomy';
        $currentPage = 'taxonomies';

        ob_start();
        require ROOT_PATH . '/admin/templates/taxonomies/edit.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    public function handleCreate(array $vars = []): void
    {
        Auth::requireRole('admin');

        [$errors, $data] = $this->validateAndSanitize($_POST);

        if (!empty($errors)) {
            $taxonomy    = null;
            $formData    = $_POST;
            $allContentTypes = Registry::all();
            $pageTitle   = 'Add Taxonomy';
            $currentPage = 'taxonomies';

            ob_start();
            require ROOT_PATH . '/admin/templates/taxonomies/edit.php';
            $pageContent = ob_get_clean();

            require ROOT_PATH . '/admin/templates/layout.php';
            return;
        }

        Taxonomy::create($data);

        header('Location: ' . ADMIN_URL . '/taxonomies');
        exit;
    }

    // ----------------------------------------------------------------
    // Edit
    // ----------------------------------------------------------------

    public function showEdit(array $vars = []): void
    {
        Auth::requireRole('admin');

        $taxonomy = Taxonomy::find((int) $vars['id']);

        if ($taxonomy === null) {
            http_response_code(404);
            require ROOT_PATH . '/templates/404.php';
            return;
        }

        $formData    = [];
        $errors      = [];
        $allContentTypes = Registry::all();
        $pageTitle   = 'Edit Taxonomy';
        $currentPage = 'taxonomies';

        ob_start();
        require ROOT_PATH . '/admin/templates/taxonomies/edit.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    public function handleUpdate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $taxonomy = Taxonomy::find((int) $vars['id']);

        if ($taxonomy === null) {
            http_response_code(404);
            require ROOT_PATH . '/templates/404.php';
            return;
        }

        // THE KEY IS NOT TAKEN FROM THE POST ON AN UPDATE. The form renders it
        // as a FACT rather than a control, because every `term_relationships`
        // row and every taxonomy field's `taxonomy_key` setting is filed under
        // it. So `$_POST['taxonomy_key']` is absent, and the validator would
        // answer "Taxonomy key is required" on every save — the identical bug
        // that made `/admin/content-types/{id}/edit` unable to save at all.
        //
        // Fixed by passing the STORED key rather than by adding a hidden input:
        // a hidden input makes the key changeable by anything that can post the
        // form, and changing it silently orphans every term assignment.
        //
        // There is a second reason the POST path cannot work here even with an
        // input: the uniqueness check asks `TaxonomyRegistry::get($key)`, and an
        // existing taxonomy IS in the registry — it registered itself from the
        // database at bootstrap. It would reject its own key as "already in
        // use". Two failures stacked, both invisible until a save is attempted
        // and the DATABASE is read back.
        [$errors, $data] = $this->validateAndSanitize(
            $_POST,
            (int) $vars['id'],
            (string) $taxonomy['taxonomy_key']
        );

        if (!empty($errors)) {
            $formData    = $_POST;
            $allContentTypes = Registry::all();
            $pageTitle   = 'Edit Taxonomy';
            $currentPage = 'taxonomies';

            ob_start();
            require ROOT_PATH . '/admin/templates/taxonomies/edit.php';
            $pageContent = ob_get_clean();

            require ROOT_PATH . '/admin/templates/layout.php';
            return;
        }

        Taxonomy::update((int) $vars['id'], $data);

        $taxonomy    = Taxonomy::find((int) $vars['id']);
        $formData    = [];
        $errors      = [];
        $saved       = true;
        $allContentTypes = Registry::all();
        $pageTitle   = 'Edit Taxonomy';
        $currentPage = 'taxonomies';

        ob_start();
        require ROOT_PATH . '/admin/templates/taxonomies/edit.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    // ----------------------------------------------------------------
    // Delete
    // ----------------------------------------------------------------

    public function handleDelete(array $vars = []): void
    {
        Auth::requireRole('admin');

        Taxonomy::delete((int) $vars['id']);

        header('Location: ' . ADMIN_URL . '/taxonomies');
        exit;
    }

    // ----------------------------------------------------------------
    // Validation
    // ----------------------------------------------------------------

    /**
     * @param string|null $lockedKey The stored key on an UPDATE. When given, the
     *                               key comes from here and not from `$post`, and
     *                               the key checks are skipped — see
     *                               handleUpdate() for both reasons.
     *
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    private function validateAndSanitize(array $post, ?int $excludeId = null, ?string $lockedKey = null): array
    {
        $errors = [];

        $pluralLabel    = trim($post['plural_label']    ?? '');
        $singularLabel  = trim($post['singular_label']  ?? '');
        $taxonomyKey    = $lockedKey ?? trim($post['taxonomy_key'] ?? '');
        $adminSlug      = trim($post['admin_slug']      ?? '');
        $publicSlug     = strtolower(trim($post['public_slug'] ?? ''));
        // THE VALUE, NOT `isset()`. The shape is a RADIO PAIR on the form —
        // "Flat" and "Nested" as two named choices, because the thing being
        // chosen is which control every entry form draws, and a lone checkbox
        // labelled "Hierarchical" states the data structure instead. A radio
        // pair always posts, so `isset()` was true for BOTH options and Flat
        // would have stored as nested.
        //
        // `!empty()` reads the value, so it is correct for the radio pair
        // (`""` vs `"1"`) and for a plain checkbox (absent vs `"1"`) alike.
        $isHierarchical = !empty($post['is_hierarchical']) ? 1 : 0;
        $isOption       = !empty($post['is_option']) ? 1 : 0;
        $isActive       = isset($post['is_active']) ? 1 : 0;

        // ── HOW MANY TERMS AN ENTRY TAKES (migration 056) ───────────────────
        // Four named answers on the form, stored as a minimum and a maximum:
        // "exactly one" is both at 1. An unknown answer is "any number" — the
        // old behaviour — rather than an error on a radio group.
        $minTerms = 0;
        $maxTerms = null;
        switch ((string) ($post['terms_rule'] ?? 'any')) {
            case 'one_or_more':
                $minTerms = 1;
                break;
            case 'exactly_one':
                $minTerms = 1;
                $maxTerms = 1;
                break;
            case 'at_most':
                $most = trim((string) ($post['max_terms'] ?? ''));
                if (!ctype_digit($most) || (int) $most < 1 || (int) $most > 99) {
                    $errors['max_terms'] = 'Say how many an entry can take at most — a number from 1 to 99.';
                } else {
                    $maxTerms = (int) $most;
                }
                break;
        }

        // Collect attached content type checkboxes
        $contentTypes = [];
        $rawContentTypes = $post['content_types'] ?? [];
        if (is_array($rawContentTypes)) {
            foreach ($rawContentTypes as $pt) {
                $pt = trim((string) $pt);
                if ($pt !== '') {
                    $contentTypes[] = $pt;
                }
            }
        }

        if ($pluralLabel === '') {
            $errors['plural_label'] = 'Plural label is required.';
        }

        if ($singularLabel === '') {
            $errors['singular_label'] = 'Singular label is required.';
        }

        if ($lockedKey !== null) {
            // Immutable, and already validated when the taxonomy was created.
        } elseif ($taxonomyKey === '') {
            $errors['taxonomy_key'] = 'Taxonomy key is required.';
        } elseif (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $taxonomyKey)) {
            $errors['taxonomy_key'] = 'Key must start with a letter; only lowercase letters, digits, and underscores; max 50 chars.';
        } elseif (Taxonomy::keyExists($taxonomyKey, $excludeId) || TaxonomyRegistry::get($taxonomyKey) !== null) {
            $errors['taxonomy_key'] = 'This taxonomy key is already in use.';
        }

        if ($adminSlug === '') {
            // FROM THE PLURAL LABEL, NOT THE KEY PLUS AN "s".
            //
            // `$key . 's'` is not a pluraliser — it is a string concatenation
            // that happens to be right for `event` and wrong for everything
            // whose plural is not the singular plus one letter. Key `category`
            // would derive the admin URL **`categorys`**, which is what the
            // owner then sees in their address bar and in every link on the
            // screen.
            //
            // The plural is not a thing that has to be guessed at — the form has
            // a required box for it, filled in by the person who knows. Slugify
            // THAT. "Categories" gives `categories`; "News" gives `news`, which
            // `news` + `s` would have made `newss`.
            $adminSlug = strtolower(trim($pluralLabel));
            $adminSlug = preg_replace('/[^a-z0-9]+/', '-', $adminSlug) ?? '';
            $adminSlug = trim($adminSlug, '-');
            // A label of nothing but punctuation slugifies to nothing, and an
            // empty slug would make the route `/admin/taxonomies//terms`. Fall
            // back to the key, which is already validated as URL-safe.
            if ($adminSlug === '') {
                $adminSlug = str_replace('_', '-', $taxonomyKey);
            }
        }

        if (!isset($errors['taxonomy_key']) && $taxonomyKey !== '') {
            if (Taxonomy::adminSlugExists($adminSlug, $excludeId)) {
                $errors['admin_slug'] = 'Admin slug "' . htmlspecialchars($adminSlug) . '" is already in use.';
            }
        }

        // ── THE PUBLIC ADDRESS, AND THE COLLISION THAT MATTERS ──────────────
        // `/{taxonomy}/{term}` is the same two-segment shape as
        // `/{type}/{entry}`, and the router registers content types FIRST — so
        // a taxonomy sharing a type's public slug does not error, it just never
        // gets a request. Silent, and the symptom is a link that 404s.
        //
        // Refused here, where the message can name the type that already has
        // the address. Migration 048's header says why the database cannot do
        // this: a UNIQUE index cannot span two tables.
        if ($publicSlug !== '') {
            // NESTED IS ALLOWED — `articles/category`, under a type's own
            // address. Turning the slash into a hyphen would leave a site unable
            // to set up the category address its existing links already use.
            // A type's routes are one and two segments deep; a term under a
            // nested address is three, so neither shadows the other.
            $publicSlug = \Cms\Helpers\Slug::cleanPath($publicSlug);
            $segments   = $publicSlug === '' ? [] : explode('/', $publicSlug);
            if (count($segments) > 3) {
                $errors['public_slug'] = 'The address /' . htmlspecialchars($publicSlug)
                    . ' is ' . count($segments) . ' levels deep. Three is the most.';
                $publicSlug = '';
            } elseif ($segments !== [] && in_array($segments[0], \Cms\Helpers\Slug::reservedFirstSegments(), true)) {
                $errors['public_slug'] = 'The address /' . htmlspecialchars($publicSlug) . ' starts with /'
                    . htmlspecialchars($segments[0]) . ', which this site already uses for something else.';
                $publicSlug = '';
            }

            foreach (Registry::all() as $ctKey => $ct) {
                if ((string) ($ct['public_slug'] ?? '') === $publicSlug) {
                    $errors['public_slug'] = 'The address /' . htmlspecialchars($publicSlug)
                        . ' already belongs to ' . htmlspecialchars((string) ($ct['label'] ?? $ctKey))
                        . '. Entries win a tie, so this taxonomy would never be reached there.';
                    $publicSlug = '';
                    break;
                }
            }
        }

        if ($publicSlug !== '' && Taxonomy::publicSlugExists($publicSlug, $excludeId)) {
            $errors['public_slug'] = 'Another taxonomy is already published at /'
                . htmlspecialchars($publicSlug) . '.';
            $publicSlug = '';
        }

        $data = [
            'taxonomy_key'    => $taxonomyKey,
            // RAW, NOT ESCAPED — every reader escapes at output. Storing escaped
            // text would show `&amp;` where the author typed `&`, and because a
            // save re-posts what the form rendered, each save would escape the
            // previous escape: `&` → `&amp;` → `&amp;amp;`, compounding forever.
            // Escape at OUTPUT, never on the way in.
            'plural_label'    => $pluralLabel,
            'singular_label'  => $singularLabel,
            'admin_slug'      => $adminSlug,
            'public_slug'     => $publicSlug,
            'content_types'   => $contentTypes,
            'is_hierarchical' => $isHierarchical,
            'min_terms'       => $minTerms,
            'max_terms'       => $maxTerms,
            'is_option'       => $isOption,
            'is_active'       => $isActive,
        ];

        return [$errors, $data];
    }
}
