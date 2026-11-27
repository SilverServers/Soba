<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\ContentType\ArchetypeDefaults;
use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Models\ContentTypeDefinition;
use Cms\Models\FieldGroup;

/**
 * ContentTypeDefinitionController — admin CRUD for UI-managed Content Type definitions.
 *
 * ── THE LIST DOES NOT RENDER A READ-ONLY ENTRY ──────────────────────────────
 * A read-only `Defined in code` row would give the owner no control over the
 * type it shows. `content-types/list.php` gives every row only the actions it
 * genuinely has, and says where a code-defined type is edited in an
 * `.alert--info` above the list, which is a fact its reader can act on with a
 * text editor.
 */
class ContentTypeDefinitionController
{
    // ----------------------------------------------------------------
    // Archetypes — what ticking one actually does
    // ----------------------------------------------------------------

    /**
     * Which of an archetype's starter fields the form asked for.
     *
     * ── WHY THE FORM SENDS A MARKER ─────────────────────────────────────────
     * Unchecked boxes send nothing, so "the author unticked all four starter
     * fields" and "this form never offered the choice" arrive as the same empty
     * POST — and guessing wrong either drops fields somebody wanted or forces on
     * fields they explicitly refused. The form renders a hidden
     * `archetype_fields_present[<archetype>]` beside the boxes to say the choice
     * was on offer. Present means the list is exact, empty included. Absent
     * means take them all, which is what ticking an archetype has always done
     * and what an API client or an older form still gets.
     *
     * @param  array<string, mixed> $post
     * @return array<string, string[]> archetype => chosen field keys
     */
    private function seedSelection(array $post): array
    {
        $present  = $post['archetype_fields_present'] ?? [];
        $selected = $post['archetype_fields'] ?? [];
        if (!is_array($present)) {
            return [];
        }

        $out = [];
        foreach (array_keys($present) as $archetype) {
            $archetype = (string) $archetype;
            $offered   = array_column(ArchetypeDefaults::getFields($archetype), 'key');
            $ticked    = $selected[$archetype] ?? [];
            $ticked    = is_array($ticked) ? array_keys(array_filter($ticked)) : [];

            // Intersect with what the archetype actually declares: the POST
            // names field keys, and a key this archetype does not offer must not
            // become a field just because somebody typed it into the request.
            $out[$archetype] = array_values(array_intersect($offered, $ticked));
        }

        return $out;
    }

    /**
     * Generate the field group a ticked archetype brings.
     *
     * `$chosen` is `null` for "every field this archetype declares" and an array
     * for exactly those keys — see seedSelection(). An archetype whose fields
     * were all declined generates NO GROUP, which is the whole point of the
     * control: the shape is a starting point and the author decides how much of
     * it they want. `post` declares no fields and has always generated nothing.
     *
     * An EXISTING group is left exactly as it is. Re-ticking an archetype whose
     * group is still here must not overwrite the author's edits to it with the
     * defaults they replaced — it only makes sure the group is active, for the
     * one install where a previous version deactivated it instead of removing
     * it.
     *
     * @param string[]|null $chosen
     */
    private function applyArchetype(string $typeKey, string $archetype, ?array $chosen): void
    {
        $existing = FieldGroup::findArchetypeGroup($typeKey, $archetype);
        if ($existing !== null) {
            FieldGroup::activate((int) $existing['id']);
            return;
        }

        $fields = ArchetypeDefaults::getFields($archetype);
        if ($chosen !== null) {
            $fields = array_values(array_filter(
                $fields,
                static fn (array $f): bool => in_array($f['key'], $chosen, true)
            ));
        }

        if ($fields === []) {
            return;
        }

        FieldGroup::create([
            'title'         => ArchetypeDefaults::getGroupTitle($archetype),
            'content_types' => [$typeKey],
            'is_active'     => 1,
            'fields'        => $fields,
            'is_archetype'  => true,
            'archetype_key' => $archetype,
        ]);
    }

    // ----------------------------------------------------------------
    // List
    // ----------------------------------------------------------------

    public function index(array $vars = []): void
    {
        Auth::requireRole('admin');

        $dbContentTypes = ContentTypeDefinition::all();

        // Content Types come from two places and this screen must show both.
        // Code-registered types (config/content-types.php) are the documented
        // source of truth for an install, and they were invisible here: the
        // screen read the `content_types` table alone, so an install whose types
        // are all in code was told it had none — while the sidebar, the routes
        // and the entry screens all worked from those same types.
        $dbKeys = array_column($dbContentTypes, 'type_key');
        $codeContentTypes = array_filter(
            Registry::all(),
            static fn (array $ct): bool => !in_array($ct['key'], $dbKeys, true)
        );

        $pageTitle      = 'Content Types';
        $currentPage    = 'content-types';

        ob_start();
        require ROOT_PATH . '/admin/templates/content-types/list.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    // ----------------------------------------------------------------
    // Create
    // ----------------------------------------------------------------

    public function showCreate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $contentTypeDefinition = null;
        $formData              = [];
        $errors                = [];
        $pageTitle             = 'Add Content Type';
        $currentPage           = 'content-types';

        ob_start();
        $parentTypeOptions = self::parentTypeOptions(
            (string) ($contentTypeDefinition['type_key'] ?? ($formData['type_key'] ?? ''))
        );
        require ROOT_PATH . '/admin/templates/content-types/edit.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    public function handleCreate(array $vars = []): void
    {
        Auth::requireRole('admin');

        [$errors, $data] = $this->validateAndSanitize($_POST);

        if (!empty($errors)) {
            $contentTypeDefinition = null;
            $formData              = $_POST;
            $pageTitle             = 'Add Content Type';
            $currentPage           = 'content-types';

            ob_start();
            $parentTypeOptions = self::parentTypeOptions(
            (string) ($contentTypeDefinition['type_key'] ?? ($formData['type_key'] ?? ''))
        );
        require ROOT_PATH . '/admin/templates/content-types/edit.php';
            $pageContent = ob_get_clean();

            require ROOT_PATH . '/admin/templates/layout.php';
            return;
        }

        $newId = ContentTypeDefinition::create($data);
        ContentTypeDefinition::setPaging($newId, $data['per_page'], $data['first_page_extra']);
        ContentTypeDefinition::setDefaultStatus($newId, $data['default_status']);
        ContentTypeDefinition::setCopyRules($newId, $data['copy']);
        ContentTypeDefinition::setTitleLabel($newId, $data['title_label']);
        $this->saveStaffView($newId, (string) $data['type_key'], $_POST);
        ContentTypeDefinition::setTermRules($newId, $data['term_rules']);
        ContentTypeDefinition::setFieldsPosition($newId, $data['fields_position']);

        $chosen = $this->seedSelection($_POST);
        foreach ($data['archetypes'] ?? ['post'] as $archetype) {
            $this->applyArchetype($data['type_key'], $archetype, $chosen[$archetype] ?? null);
        }

        header('Location: ' . ADMIN_URL . '/content-types');
        exit;
    }

    // ----------------------------------------------------------------
    // Edit
    // ----------------------------------------------------------------

    public function showEdit(array $vars = []): void
    {
        Auth::requireRole('admin');

        $contentTypeDefinition = ContentTypeDefinition::find((int) $vars['id']);

        if ($contentTypeDefinition === null) {
            http_response_code(404);
            require ROOT_PATH . '/templates/404.php';
            return;
        }

        $formData    = [];
        $errors      = [];
        $pageTitle   = 'Edit Content Type';
        $currentPage = 'content-types';

        ob_start();
        $parentTypeOptions = self::parentTypeOptions(
            (string) ($contentTypeDefinition['type_key'] ?? ($formData['type_key'] ?? ''))
        );
        require ROOT_PATH . '/admin/templates/content-types/edit.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    public function handleUpdate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $contentTypeDefinition = ContentTypeDefinition::find((int) $vars['id']);

        if ($contentTypeDefinition === null) {
            http_response_code(404);
            require ROOT_PATH . '/templates/404.php';
            return;
        }

        // THE KEY IS NOT TAKEN FROM THE POST ON AN UPDATE. The form renders it
        // as a FACT rather than a control, because entries are stored against
        // it, so `$_POST['type_key']` is absent here. Validated on the POST
        // alone, every save of an existing content type would fail with
        // "Content type key is required" — behind an HTTP 200 and a form that
        // comes back wearing what was typed (a failed submit re-renders from
        // $_POST), with only an alert block above it to say so.
        //
        // So the STORED key is passed, rather than a hidden input added. A
        // hidden input would make the key changeable by anything that can post
        // the form, and the key is what every `posts` and `post_meta` row is
        // filed under — changing it orphans all of them silently. The value the
        // form cannot change should not travel through the form.
        [$errors, $data] = $this->validateAndSanitize(
            $_POST,
            (int) $vars['id'],
            (string) $contentTypeDefinition['type_key']
        );

        if (!empty($errors)) {
            $formData    = $_POST;
            $pageTitle   = 'Edit Content Type';
            $currentPage = 'content-types';

            ob_start();
            $parentTypeOptions = self::parentTypeOptions(
            (string) ($contentTypeDefinition['type_key'] ?? ($formData['type_key'] ?? ''))
        );
        require ROOT_PATH . '/admin/templates/content-types/edit.php';
            $pageContent = ob_get_clean();

            require ROOT_PATH . '/admin/templates/layout.php';
            return;
        }

        // Detect archetype changes before update
        $oldArchetypes = $contentTypeDefinition['archetypes'] ?? ['post'];
        $newArchetypes = $data['archetypes'] ?? ['post'];

        ContentTypeDefinition::update((int) $vars['id'], $data);
        ContentTypeDefinition::setPaging((int) $vars['id'], $data['per_page'], $data['first_page_extra']);
        ContentTypeDefinition::setDefaultStatus((int) $vars['id'], $data['default_status']);
        ContentTypeDefinition::setCopyRules((int) $vars['id'], $data['copy']);
        ContentTypeDefinition::setTitleLabel((int) $vars['id'], $data['title_label']);
        ContentTypeDefinition::setTermRules((int) $vars['id'], $data['term_rules']);
        ContentTypeDefinition::setFieldsPosition((int) $vars['id'], $data['fields_position']);
        $this->saveListView((int) $vars['id'], (string) $data['type_key'], $_POST);
        $this->saveChildren((int) $vars['id'], (string) $data['type_key'], $_POST);
        $this->saveStaffView((int) $vars['id'], (string) $data['type_key'], $_POST);
        ContentTypeDefinition::setPatterns((int) $vars['id'], $data['title_pattern'], $data['slug_pattern']);
        ContentTypeDefinition::setParentLabel((int) $vars['id'], $data['parent_label']);

        // A ticked archetype brings its chosen fields; an unticked one TAKES ITS
        // GROUP AWAY AGAIN — see forgetArchetype() for why that is a delete and
        // not an `is_active = 0`.
        $typeKey = $contentTypeDefinition['type_key'];
        $added   = array_diff($newArchetypes, $oldArchetypes);
        $removed = array_diff($oldArchetypes, $newArchetypes);
        $chosen  = $this->seedSelection($_POST);

        foreach ($added as $archetype) {
            $this->applyArchetype($typeKey, $archetype, $chosen[$archetype] ?? null);
        }

        foreach ($removed as $archetype) {
            FieldGroup::forgetArchetype($typeKey, $archetype);
        }

        $contentTypeDefinition = ContentTypeDefinition::find((int) $vars['id']);
        $formData              = [];
        $errors                = [];
        $saved                 = true;
        $pageTitle             = 'Edit Content Type';
        $currentPage           = 'content-types';

        ob_start();
        $parentTypeOptions = self::parentTypeOptions(
            (string) ($contentTypeDefinition['type_key'] ?? ($formData['type_key'] ?? ''))
        );
        require ROOT_PATH . '/admin/templates/content-types/edit.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    // ----------------------------------------------------------------
    // Delete
    // ----------------------------------------------------------------

    public function handleDelete(array $vars = []): void
    {
        Auth::requireRole('admin');

        ContentTypeDefinition::delete((int) $vars['id']);

        header('Location: ' . ADMIN_URL . '/content-types');
        exit;
    }

    // ----------------------------------------------------------------
    // Validation
    // ----------------------------------------------------------------

    /**
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    /**
     * @param string|null $lockedKey The stored key on an UPDATE. When given, the
     *                               key comes from here and not from `$post`, and
     *                               the key checks are skipped: it is already in
     *                               use — by this very type — so `keyExists()`
     *                               and `Registry::get()` both answer yes and
     *                               would reject every save of an existing type.
     */
    private function validateAndSanitize(array $post, ?int $excludeId = null, ?string $lockedKey = null): array
    {
        $errors = [];

        $pluralLabel   = trim($post['plural_label']   ?? '');
        $singularLabel = trim($post['singular_label'] ?? '');
        $typeKey       = $lockedKey ?? trim($post['type_key'] ?? '');
        $adminSlug     = trim($post['admin_slug']     ?? '');
        $publicSlug    = trim($post['public_slug']    ?? '');
        $parentType    = trim($post['parent_type']    ?? '');
        $menuIcon      = trim($post['menu_icon']      ?? 'file');
        $supports      = [];
        $isActive      = isset($post['is_active']) ? 1 : 0;

        // Collect supports checkboxes
        foreach (['title', 'content', 'thumbnail', 'excerpt'] as $s) {
            if (!empty($post['supports'][$s])) {
                $supports[] = $s;
            }
        }

        // Collect archetypes checkboxes
        $validArchetypes = ArchetypeDefaults::KEYS;
        $archetypes = [];
        $rawArchetypes = $post['archetypes'] ?? [];
        if (is_array($rawArchetypes)) {
            foreach ($rawArchetypes as $a) {
                $a = trim((string) $a);
                if (in_array($a, $validArchetypes, true)) {
                    $archetypes[] = $a;
                }
            }
        }
        if (empty($archetypes)) {
            $archetypes = ['post']; // default to post archetype
        }
        // AN ANNOUNCEMENT IS ONLY THAT (`ArchetypeDefaults::ALONE`): it has no
        // page and is in no list, so a type cannot be one and a post or an
        // event as well. Refused by name rather than one of them dropped.
        $alone = array_values(array_intersect($archetypes, ArchetypeDefaults::ALONE));
        if ($alone !== [] && count($archetypes) > 1) {
            $errors['archetypes'] = ucfirst($alone[0]) . ' is a kind of its own: untick it or the others.';
        }

        if ($pluralLabel === '') {
            $errors['plural_label'] = 'Plural label is required.';
        }

        if ($singularLabel === '') {
            $errors['singular_label'] = 'Singular label is required.';
        }

        if ($lockedKey !== null) {
            // Immutable, and already validated when the type was created. Nothing
            // to check — see handleUpdate() for why it is not in the POST at all.
        } elseif ($typeKey === '') {
            $errors['type_key'] = 'Content type key is required.';
        // ── FIFTY, NOT TWENTY ───────────────────────────────────────────────
        // A 20-character cap cuts real keys short — `student_caucus_bodies`
        // does not fit — and the key is IMMUTABLE the moment an entry is saved
        // against it, so a shortened key is permanent.
        //
        // Every column in this schema that holds a type key is varchar(50):
        //
        //     posts.post_type            50
        //     revisions.post_type        50
        //     content_types.type_key     50   (and parent_type, migration 047)
        //
        // and every column one is COMPOSED into is wider still — `option_key` is
        // 191, `meta_key` 255, the slugs 100. `field_groups.archetype_key` is
        // varchar(20) and holds an ARCHETYPE — post, event, listing, product —
        // never a type key.
        //
        // A cap of 20 would make the app stricter than its own storage by
        // thirty characters.
        //
        // 50 is the same number in three places: the column, this rule, and
        // the `pattern` on the input.
        } elseif (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $typeKey)) {
            $errors['type_key'] = 'Key must start with a letter; only lowercase letters, digits, and underscores; max 50 chars.';
        } elseif (ContentTypeDefinition::keyExists($typeKey, $excludeId) || Registry::get($typeKey) !== null) {
            $errors['type_key'] = 'This content type key is already in use.';
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
                $adminSlug = str_replace('_', '-', $typeKey);
            }
        }
        // A BLANK PUBLIC URL IS NO PAGES: the type's entries have no page of
        // their own (`Registry::hasPages()`), and none of the checks below
        // apply. A new type's box is filled from the plural name as it is
        // typed (type-form.js), so a type has pages unless the box is cleared.

        // ── AN ADDRESS THE ROUTER ALREADY OWNS ──────────────────────────────
        // A type's archive is the route `/{public slug}` and its entries are
        // `/{public slug}/{entry}`. An address the router already has —
        // `/search`, `/cart` — or one another type or a taxonomy already
        // answers at is not a tie the router settles: it THROWS while building
        // its table, on every request, so one such save would take the whole
        // site down, this screen included. Cleaned like every other slug, then
        // refused by name. (`Router::dispatch()` survives such a row if it
        // arrives some other way; this is where the owner is told.)
        // AN ANNOUNCEMENT HAS NO PAGE: it shows over the pages it names, so
        // its type has no address of its own whatever was typed (the form
        // draws none for it).
        if (array_intersect($archetypes, ArchetypeDefaults::ALONE) !== []) {
            $publicSlug = '';
        }
        $typedSlug  = $publicSlug;
        $publicSlug = \Cms\Helpers\Slug::clean($publicSlug);
        if ($typedSlug === '') {
            // No pages: nothing to check.
        } elseif ($publicSlug === '') {
            $errors['public_slug'] = 'The public URL needs at least one letter or digit.';
        } elseif (in_array($publicSlug, \Cms\Helpers\Slug::reservedFirstSegments(), true)) {
            $errors['public_slug'] = 'The address /' . htmlspecialchars($publicSlug)
                . ' already belongs to this site itself, so a type there could never be reached. Choose another.';
        } elseif (in_array($publicSlug, \Cms\Site\Front::siteAddresses(), true)) {
            // A SITE WITH ITS OWN ROUTER IN FRONT keeps its own addresses
            // (`Site\Front`), and a type there would never be handed a request.
            $errors['public_slug'] = 'The address /' . htmlspecialchars($publicSlug)
                . ' is one of the site\'s own pages, so a type there could never be reached. Choose another.';
        } else {
            foreach (Registry::all() as $otherKey => $other) {
                if ((string) $otherKey !== $typeKey && (string) Registry::publicSlug((string) $otherKey) === $publicSlug) {
                    $errors['public_slug'] = 'The address /' . htmlspecialchars($publicSlug) . ' already belongs to '
                        . htmlspecialchars((string) ($other['label'] ?? $otherKey)) . '.';
                    break;
                }
            }
            if (!isset($errors['public_slug'])) {
                foreach (TaxonomyRegistry::all() as $taxKey => $tax) {
                    if (trim((string) ($tax['public_slug'] ?? '')) === $publicSlug) {
                        $errors['public_slug'] = 'The address /' . htmlspecialchars($publicSlug) . ' already belongs to the '
                            . htmlspecialchars((string) ($tax['label'] ?? $taxKey))
                            . ' taxonomy — their pages and this type\'s entries would share one shape of address.';
                        break;
                    }
                }
            }
        }

        if (!empty($typeKey) && !isset($errors['type_key'])) {
            if (ContentTypeDefinition::adminSlugExists($adminSlug, $excludeId)) {
                $errors['admin_slug'] = 'Admin slug "' . htmlspecialchars($adminSlug) . '" is already in use.';
            }
        }

        if ($menuIcon === '') {
            $menuIcon = 'file';
        }

        // ── THE PARENT IS CHECKED, NOT TRUSTED ──────────────────────────────
        // The select offers only what `canParent()` allows, and a POST is not
        // the select — the same shape as `//evil.test` against the redirects
        // screen's destination guard, where the control is right and a handler
        // that believed it would not be.
        //
        // A CYCLE IS NOT A TASTE QUESTION. Anything that walks up a chain —
        // breadcrumbs, a delete that looks for children, the sidebar — loops
        // forever on A→B→A, and the screen never returns. There is no output to
        // read and nothing malformed to find.
        if ($parentType !== '') {
            $selfKey = $lockedKey ?? $typeKey;

            if ($selfKey !== '' && !Registry::canParent($selfKey, $parentType)) {
                $errors['parent_type'] = Registry::get($parentType) === null
                    ? 'There is no content type called "' . htmlspecialchars($parentType) . '".'
                    : 'That would make ' . htmlspecialchars($pluralLabel !== '' ? $pluralLabel : $selfKey)
                        . ' belong to itself, directly or through another type.';
                $parentType = '';
            }
        }

        // ── HOW MANY TO A PAGE (migration 090) ──────────────────────────────
        // Blank follows the site's own number; 0 lists every entry on one
        // page. Anything else outside 0–100 is refused BY NAME, as General's
        // own box is — not clamped, so what is stored is what was typed. A
        // non-scalar (a hand-made `per_page[]`) is a refusal, not "Array".
        $countOf = static function (mixed $raw): ?string {
            return is_scalar($raw) ? trim((string) $raw) : null;
        };
        $perPage    = null;
        $perPageRaw = $countOf($post['per_page'] ?? '');
        if ($perPageRaw === null
            || ($perPageRaw !== '' && (!ctype_digit($perPageRaw) || (int) $perPageRaw > \Cms\Helpers\Paging::MAX_PER_PAGE))) {
            $errors['per_page'] = 'Entries per page must be blank, 0, or a whole number up to 100.';
        } elseif ($perPageRaw !== '') {
            $perPage = (int) $perPageRaw;
        }
        $extra    = 0;
        $extraRaw = $countOf($post['first_page_extra'] ?? '');
        if ($extraRaw === null
            || ($extraRaw !== '' && (!ctype_digit($extraRaw) || (int) $extraRaw > \Cms\Helpers\Paging::MAX_PER_PAGE))) {
            $errors['first_page_extra'] = 'Extra entries on page 1 must be a whole number from 0 to 100.';
        } elseif ($extraRaw !== '') {
            $extra = (int) $extraRaw;
        }

        // ITS OWN WORD FOR THE TITLE (migration 150), such as Name: as typed,
        // its ends trimmed, and refused by name past the column's hundred.
        $titleLabel = is_scalar($post['title_label'] ?? null) ? trim((string) $post['title_label']) : '';
        if (mb_strlen($titleLabel) > 100) {
            $errors['title_label'] = 'The title label can be at most 100 characters.';
        }

        // HOW MANY TERMS OF EACH TAXONOMY ITS ENTRIES TAKE (migration 151), for
        // each taxonomy on the type, in the four answers the taxonomy's own
        // screen gives, or blank for the taxonomy's own rule. "No more than"
        // without a number from 1 to 99 is refused by the taxonomy's name.
        $termRules = [];
        foreach (\Cms\ContentType\TaxonomyRegistry::getForContentType((string) ($lockedKey ?? $typeKey)) as $taxKey => $tax) {
            $given = is_array($post['term_rules'][$taxKey] ?? null) ? $post['term_rules'][$taxKey] : [];
            $rule  = is_string($given['rule'] ?? null) ? $given['rule'] : '';
            $most  = is_scalar($given['max'] ?? null) ? trim((string) $given['max']) : '';
            if ($rule === 'any') {
                $termRules[(string) $taxKey] = ['min' => 0, 'max' => null];
            } elseif ($rule === 'one_or_more') {
                $termRules[(string) $taxKey] = ['min' => 1, 'max' => null];
            } elseif ($rule === 'exactly_one') {
                $termRules[(string) $taxKey] = ['min' => 1, 'max' => 1];
            } elseif ($rule === 'at_most') {
                if (!ctype_digit($most) || (int) $most < 1 || (int) $most > 99) {
                    $errors['term_rules_' . $taxKey] = 'Say how many ' . strtolower((string) $tax['label'])
                        . ' an entry can take at most — a number from 1 to 99.';
                } else {
                    $termRules[(string) $taxKey] = ['min' => 0, 'max' => (int) $most];
                }
            }
        }

        // ── HOW ITS EDITOR NAMES EACH PARENT (migration 148) ───────────────
        // Over the parent type's fields, so a token naming nothing the parent
        // has is refused by name; with no parent, none is kept.
        $parentLabel = is_scalar($post['parent_label'] ?? null) ? trim((string) $post['parent_label']) : '';
        if ($parentType === '') {
            $parentLabel = '';
        } elseif (mb_strlen($parentLabel) > 255) {
            $errors['parent_label'] = 'How each is named can be at most 255 characters.';
        } elseif ($parentLabel !== '' && Registry::get($parentType) !== null) {
            $problem = \Cms\ContentType\Patterns::problem($parentLabel, $parentType, 'name of each');
            if ($problem !== null) {
                $errors['parent_label'] = str_replace("this type's fields or taxonomies", 'the fields or taxonomies of what it belongs to', $problem);
            }
        }

        // ── WHAT COPY MAKES OF ITS ENTRIES (migration 147) ──────────────────
        // The words after a copy's title, none allowed; its status; and the
        // fields it never takes, each a key or a key's start and `*`, a name
        // that is neither refused by name. The CMS's own is kept as none.
        $copySuffix = is_scalar($post['copy_suffix'] ?? null) ? trim((string) $post['copy_suffix']) : \Cms\ContentType\CopyRules::SUFFIX;
        if (mb_strlen($copySuffix) > 100) {
            $errors['copy_suffix'] = 'The words after a copy\'s title can be at most 100 characters.';
        }
        $copySkip = is_scalar($post['copy_skip'] ?? null) ? (string) $post['copy_skip'] : '';
        foreach (preg_split('/[\s,]+/', $copySkip) ?: [] as $copyName) {
            if (trim($copyName) !== '' && preg_match('/^[a-z0-9_]+\*?$/', trim($copyName)) !== 1) {
                $errors['copy_skip'] = 'Never copied: "' . trim($copyName) . '" is not a field\'s key. Write each as its key, such as legacy_id, or legacy_* for every key that begins so.';
                break;
            }
        }
        $copy = ['suffix' => $copySuffix, 'status' => ($post['copy_status'] ?? '') === 'same' ? 'same' : 'private',
                 'skip' => \Cms\ContentType\CopyRules::names($copySkip)];
        if ($copy === ['suffix' => \Cms\ContentType\CopyRules::SUFFIX, 'status' => 'private', 'skip' => []]) {
            $copy = null;
        }

        // ── ITS TITLES AND ADDRESSES MADE FROM ITS FIELDS (migration 146) ───
        // On a type that exists, whose fields and taxonomies a pattern names:
        // a token that names nothing it has is refused by name, as is a
        // pattern longer than its column.
        $patterns = [];
        foreach (['title_pattern' => 'title pattern', 'slug_pattern' => 'address pattern'] as $box => $what) {
            $typed           = is_scalar($post[$box] ?? null) ? trim((string) $post[$box]) : '';
            $patterns[$box]  = $typed;
            if (mb_strlen($typed) > 255) {
                $errors[$box] = 'The ' . $what . ' can be at most 255 characters.';
            } elseif ($typed !== '' && $lockedKey !== null && Registry::get($lockedKey) !== null) {
                $problem = \Cms\ContentType\Patterns::problem($typed, $lockedKey, $what);
                if ($problem !== null) {
                    $errors[$box] = $problem;
                }
            }
        }

        $data = [
            'type_key'       => $typeKey,
            // RAW, NOT ESCAPED — every reader escapes at output (h() in the
            // templates, View::escape in the renderers), so storing escaped text
            // would show the author `&amp;` where they typed `&`. And because a
            // save re-posts whatever the form rendered, each save would escape
            // the previous escape: `&` → `&amp;` → `&amp;amp;`, compounding with
            // nothing to stop it. Escape at OUTPUT, never on the way in.
            'plural_label'   => $pluralLabel,
            'singular_label' => $singularLabel,
            'admin_slug'     => $adminSlug,
            'public_slug'    => $publicSlug,
            'parent_type'    => $parentType,
            'menu_icon'      => $menuIcon,
            'supports'       => $supports,
            'archetypes'     => $archetypes,
            'is_active'      => $isActive,
            'per_page'         => $perPage,
            'first_page_extra' => $extra,
            // THE STATUS A NEW ENTRY STARTS AT (migration 131): Published when
            // chosen; anything else, a hand-made value included, Private.
            'default_status'   => ($post['default_status'] ?? '') === 'published' ? 'published' : 'private',
            'title_label'      => $titleLabel,
            'term_rules'       => $termRules,
            'copy'             => $copy,
            'parent_label'     => $parentLabel,
            'title_pattern'    => $patterns['title_pattern'],
            'slug_pattern'     => $patterns['slug_pattern'],
            // WHERE ITS FIELDS GO (migration 149): above its text, or after it.
            'fields_position'  => ($post['fields_position'] ?? '') === 'high' ? 'high' : '',
        ];

        return [$errors, $data];
    }

    /**
     * WHAT ITS ENTRY LIST SHOWS AND HOW IT IS ORDERED (migration 130), when the
     * form drew its List panel: the columns ticked that the type can show, in
     * the order offered, and a sort the type can make. Anything else posted is
     * left out, as the panel never offers it.
     *
     * @param array<string, mixed> $post
     */
    private function saveListView(int $id, string $typeKey, array $post): void
    {
        if (!isset($post['list_view']) || \Cms\ContentType\Registry::get($typeKey) === null) {
            return;
        }
        $ticked  = array_map('strval', array_filter((array) ($post['list_columns'] ?? []), 'is_scalar'));
        $columns = array_values(array_filter(
            array_column(\Cms\ContentType\ListColumns::available($typeKey), 'spec'),
            static fn (string $spec): bool => in_array($spec, $ticked, true)
        ));
        $sort = null;
        if (is_string($post['list_sort'] ?? null) && preg_match('/^(.+):(asc|desc)$/', $post['list_sort'], $m) === 1) {
            $field = $m[1];
            if ($field === 'date' || $field === 'title'
                || in_array((string) (\Cms\ContentType\ListColumns::sortable($typeKey, $field)['type'] ?? ''), ['date', 'datetime', 'number', 'text', 'select'], true)) {
                $sort = ['field' => $field, 'order' => $m[2]];
            }
        }
        ContentTypeDefinition::setListView($id, $columns, $sort);
    }

    /**
     * ITS CHILDREN ON EACH ENTRY'S EDITOR (migration 145), when the form drew
     * its panel: for each type that belongs to this one, the columns ticked
     * that its rows can show, in the order offered. A type with none ticked is
     * not shown; anything else posted is left out, as the panel never offers it.
     *
     * @param array<string, mixed> $post
     */
    private function saveChildren(int $id, string $typeKey, array $post): void
    {
        if (!isset($post['children_view']) || Registry::get($typeKey) === null) {
            return;
        }
        $posted   = is_array($post['children'] ?? null) ? $post['children'] : [];
        $children = [];
        foreach (\Cms\ContentType\Children::available($typeKey) as $child) {
            $ticked  = is_array($posted[$child['key']]['columns'] ?? null)
                ? array_map('strval', array_filter($posted[$child['key']]['columns'], 'is_scalar')) : [];
            $columns = array_values(array_filter(
                array_column($child['columns'], 'spec'),
                static fn (string $spec): bool => in_array($spec, $ticked, true)
            ));
            if ($columns !== []) {
                $children[$child['key']] = ['columns' => $columns];
            }
        }
        ContentTypeDefinition::setChildren($id, $children);
    }

    /**
     * WHAT STAFF SEE ON ITS SCREENS (migration 140, `ContentType\StaffView`),
     * when the form drew it: each panel's least role, and with its List panel
     * each list part's and what the list is grouped by, a Choice field or a
     * taxonomy the type has. A part left at its default is not kept, and a
     * list the form did not draw keeps what it was.
     *
     * @param array<string, mixed> $post
     */
    private function saveStaffView(int $id, string $typeKey, array $post): void
    {
        if (!isset($post['staff_view'])) {
            return;
        }
        $pick = static function (mixed $posted, array $defaults): array {
            $out = [];
            foreach ($defaults as $part => $default) {
                $role = \Cms\ContentType\StaffView::posted(is_array($posted) ? ($posted[$part] ?? null) : null, $default);
                if ($role !== null) {
                    $out[$part] = $role;
                }
            }

            return $out;
        };
        $panels = $pick($post['panels'] ?? null, \Cms\ContentType\StaffView::PANELS);
        $list   = (array) (Registry::get($typeKey)['list'] ?? []);
        if (isset($post['list_view']) && Registry::get($typeKey) !== null) {
            $list  = $pick($post['list_parts'] ?? null, \Cms\ContentType\StaffView::LIST);
            $group = is_string($post['group_by'] ?? null) ? $post['group_by'] : '';
            if ($group !== '' && isset(\Cms\ContentType\StaffView::groupOptions($typeKey)[$group])) {
                $list['group_by'] = $group;
            }
        }
        ContentTypeDefinition::setStaffView($id, $panels, $list);
    }

    /**
     * The types this one may declare as its parent.
     *
     * ONE METHOD, CALLED AT EVERY RENDER SITE. There are five of them — create,
     * a failed create, edit, a failed update, and the saved redirect — and the
     * same rule computed twice drifts: one copy ends up wrong on exactly the
     * status the other handles.
     *
     * ASKS THE REGISTRY, so a type registered in code is offered beside one
     * created in the admin. A list built from
     * `ContentTypeDefinition::all()` would answer the narrower question "what is
     * in this table", and the answer would be a Belongs-to control that cannot
     * see half the install.
     *
     * @return list<array{key: string, label: string}>
     */
    private static function parentTypeOptions(string $selfKey): array
    {
        $out = [];

        foreach (Registry::all() as $key => $definition) {
            // `canParent()` refuses the type itself and anything whose own chain
            // comes back here. Asking it rather than filtering on `$key !==
            // $selfKey` is what keeps A→B→A off the list as well as A→A.
            if ($selfKey !== '' && !Registry::canParent($selfKey, (string) $key)) {
                continue;
            }

            $out[] = [
                'key'   => (string) $key,
                'label' => (string) ($definition['label'] ?? $key),
            ];
        }

        return $out;
    }
}
