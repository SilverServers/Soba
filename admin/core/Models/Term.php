<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\ContentType\TaxonomyRegistry;
use Cms\Database\Database;
use PDO;

/**
 * Term model — CRUD for individual taxonomy terms and post/term relationship management.
 *
 * Terms belong to a taxonomy (identified by taxonomy_key string).
 * Hierarchical taxonomies support parent/child nesting via parent_id (self-referencing FK).
 * Post/term assignments are stored in post_term_relationships and managed atomically
 * via setPostTerms() — delete-then-insert scoped to one post/taxonomy pair.
 */
class Term
{
    /**
     * Return all terms for a taxonomy as a flat array, ordered by sort_order then name.
     * Used by list views, parent selectors, and the taxonomy field type.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allForTaxonomy(string $taxonomyKey): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT * FROM terms WHERE taxonomy_key = ? ORDER BY sort_order ASC, name ASC'
        );
        $stmt->execute([$taxonomyKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return all terms for a taxonomy as a nested tree structure.
     * Used to render hierarchical checkbox panels with indentation.
     *
     * The tree is built in PHP from a single flat query.
     * Orphaned terms (parent_id set to a deleted parent) are promoted to root level.
     *
     * Each item shape: id, taxonomy_key, name, slug, description, parent_id, sort_order, children[]
     *
     * @return array<int, array<string, mixed>>
     */
    public static function treeForTaxonomy(string $taxonomyKey): array
    {
        $flat = self::allForTaxonomy($taxonomyKey);

        // Index by id for O(1) lookup
        $indexed = [];
        foreach ($flat as $term) {
            $term['children'] = [];
            $indexed[$term['id']] = $term;
        }

        // Build tree: attach each child to its parent; promote orphans to root
        $roots = [];
        foreach ($indexed as $id => $term) {
            $parentId = $term['parent_id'];
            if ($parentId !== null && isset($indexed[$parentId])) {
                $indexed[$parentId]['children'][] = &$indexed[$id];
            } else {
                $roots[] = &$indexed[$id];
            }
        }

        return $roots;
    }

    /**
     * How many entries are filed under each term of a taxonomy, keyed by term id.
     *
     * ONE QUERY FOR THE WHOLE LIST, deliberately. The obvious shape is a
     * `countFor(int $termId)` called once per row — which is two hundred queries
     * for two hundred numbers, on the shared hosting this CMS is written for.
     * `Database::runMigrations()` was rewritten for exactly this reason: work
     * that scales with the page and buys nothing is real money on every request.
     *
     * A term with no entries is ABSENT from the result rather than present as 0,
     * because the join has no row to count. Callers read it as
     * `$usage[$id] ?? 0`; a caller that assumes every id is a key gets a
     * notice on precisely the terms nobody has used yet.
     *
     * @return array<int, int> term id => entry count
     */
    public static function usageCounts(string $taxonomyKey): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT t.id, COUNT(ptr.post_id) AS n
               FROM terms t
               LEFT JOIN post_term_relationships ptr ON ptr.term_id = t.id
              WHERE t.taxonomy_key = ?
              GROUP BY t.id'
        );
        $stmt->execute([$taxonomyKey]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * How many entries each term's ARCHIVE lists, keyed by term id — the number
     * the terms screen prints on each row.
     *
     * Not `usageCounts()`, every filing there is: drafts, the trash, an entry
     * scheduled for next month — that would print 5 where the archive lists 3,
     * and 0 for a pillar whose entries sit one level down, over an archive that
     * lists all of them. This counts by the archive's rules
     * (`Public\TermController::archive()`):
     *   · live — `Post::live()`, published and its date come;
     *   · a term on a nested taxonomy includes its whole branch;
     *   · an entry filed under the term and a child is one entry.
     *
     * `usageCounts()` stays, for the question it answers correctly: what a
     * delete would unfile, which is every direct filing whatever its state.
     *
     * One query and a walk in PHP — the sitemap's shape for the same branch
     * (`SEO\Sitemap::termRows()`). A term nothing live is filed under is
     * ABSENT; read it as `?? 0`.
     *
     * @return array<int, int> term id => live entries its archive lists
     */
    public static function liveCounts(string $taxonomyKey): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT ptr.term_id, ptr.post_id
               FROM post_term_relationships ptr
               JOIN terms t ON t.id = ptr.term_id
               JOIN posts p ON p.id = ptr.post_id
              WHERE t.taxonomy_key = ? AND ' . Post::live('p')
        );
        $stmt->execute([$taxonomyKey]);

        $direct = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $direct[(int) $row['term_id']][(int) $row['post_id']] = true;
        }

        $tax = TaxonomyRegistry::get($taxonomyKey);
        if (empty($tax['hierarchical'])) {
            return array_map('count', $direct);
        }

        $all = self::allForTaxonomy($taxonomyKey);
        $out = [];
        foreach ($all as $term) {
            $id     = (int) $term['id'];
            $branch = [$id];
            self::collectDescendants($id, $all, $branch);

            // Keyed by entry id, so the union counts each entry once.
            $entries = [];
            foreach ($branch as $member) {
                $entries += $direct[$member] ?? [];
            }
            if ($entries !== []) {
                $out[$id] = count($entries);
            }
        }

        return $out;
    }

    /**
     * Find a single term by its id.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM terms WHERE id = ?');
        $stmt->execute([$id]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * A TERM'S OWN FIELDS (`ContentType\TermFields`), each value by its key as
     * the field keeps it on an entry: plain text, a picture's id, a list as
     * JSON. A theme prints one with `FieldDisplay::html()` or reads it as it
     * reads an entry's. [] for a term with none.
     *
     * @return array<string, string|null>
     */
    public static function meta(int $id): array
    {
        $stmt = Database::getInstance()->prepare('SELECT meta_key, meta_value FROM term_meta WHERE term_id = ?');
        $stmt->execute([$id]);

        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * A term's fields kept, each as sanitized (`FieldRenderer::sanitizeField()`):
     * one row per key, a null value removing its row.
     *
     * @param array<string, mixed> $values
     */
    public static function setMeta(int $id, array $values): void
    {
        $db   = Database::getInstance();
        $put  = $db->prepare('INSERT INTO term_meta (term_id, meta_key, meta_value) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)');
        $drop = $db->prepare('DELETE FROM term_meta WHERE term_id = ? AND meta_key = ?');
        foreach ($values as $key => $value) {
            if ($value === null) {
                $drop->execute([$id, (string) $key]);
                continue;
            }
            $put->execute([$id, (string) $key, is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value]);
        }
    }

    /**
     * Find a term by taxonomy_key + slug (for uniqueness validation).
     *
     * @return array<string, mixed>|null
     */
    public static function findBySlug(string $taxonomyKey, string $slug): ?array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM terms WHERE taxonomy_key = ? AND slug = ?');
        $stmt->execute([$taxonomyKey, $slug]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Search terms by name fragment. Used by the tag-input JS typeahead API.
     * Returns id, name, slug only (lightweight for JSON response).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $taxonomyKey, string $query, int $limit = 20): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT id, name, slug FROM terms
             WHERE taxonomy_key = ? AND name LIKE ?
             ORDER BY name ASC
             LIMIT ?'
        );
        $stmt->execute([$taxonomyKey, '%' . $query . '%', $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Insert a new term. Returns the new row's id.
     *
     * @param array<string, mixed> $data  Keys: taxonomy_key, name, slug, description, parent_id, sort_order
     */
    public static function create(array $data): int
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO terms (taxonomy_key, name, slug, description, parent_id, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['taxonomy_key'],
            $data['name'],
            $data['slug'],
            $data['description'] ?? null,
            isset($data['parent_id']) && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null,
            $data['sort_order'] ?? 0,
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Update an existing term.
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('
            UPDATE terms
            SET name = ?, slug = ?, description = ?, parent_id = ?, sort_order = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $data['name'],
            $data['slug'],
            $data['description'] ?? null,
            isset($data['parent_id']) && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null,
            $data['sort_order'] ?? 0,
            $id,
        ]);
    }

    /**
     * Delete a term by id.
     * ON DELETE CASCADE removes it from post_term_relationships automatically.
     *
     * ITS CHILDREN MOVE UP ONE LEVEL, to its own parent — what the terms
     * list's delete dialog says ("terms nested under it move up a level"), and
     * the term screen's own dialog says the same. The foreign key alone does
     * not do that: its ON DELETE SET NULL would send every child to the TOP, so
     * deleting a middle term would flatten a grandchild two levels, under a
     * dialog that says one.
     */
    public static function delete(int $id): void
    {
        $db = Database::getInstance();
        $db->prepare(
            'UPDATE terms child JOIN terms gone ON gone.id = child.parent_id
                SET child.parent_id = gone.parent_id
              WHERE gone.id = ?'
        )->execute([$id]);
        $stmt = $db->prepare('DELETE FROM terms WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Generate a unique slug for a term within a taxonomy.
     * Appends -2, -3, etc. if the base slug already exists.
     * Pass $excludeId when editing to exclude the term's own current slug.
     */
    public static function generateSlug(string $name, string $taxonomyKey, ?int $excludeId = null): string
    {
        // Basic slugify
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug) ?? $slug;
        $slug = preg_replace('/[\s-]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'term';
        }

        $db        = Database::getInstance();
        $base      = $slug;
        $counter   = 2;

        while (true) {
            if ($excludeId !== null) {
                $stmt = $db->prepare(
                    'SELECT id FROM terms WHERE taxonomy_key = ? AND slug = ? AND id != ?'
                );
                $stmt->execute([$taxonomyKey, $slug, $excludeId]);
            } else {
                $stmt = $db->prepare('SELECT id FROM terms WHERE taxonomy_key = ? AND slug = ?');
                $stmt->execute([$taxonomyKey, $slug]);
            }

            if ($stmt->fetch() === false) {
                break;
            }

            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Get all term IDs assigned to a post for a specific taxonomy.
     * Used by CptController::showEdit() to pre-populate sidebar panels.
     *
     * @return int[]
     */
    public static function getPostTermIds(int $postId, string $taxonomyKey): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('
            SELECT ptr.term_id
            FROM post_term_relationships ptr
            JOIN terms t ON t.id = ptr.term_id
            WHERE ptr.post_id = ? AND t.taxonomy_key = ?
        ');
        $stmt->execute([$postId, $taxonomyKey]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'term_id');
    }

    /**
     * Get full term rows assigned to a post for a specific taxonomy.
     * Returns id, name, slug per term — used to populate chip data-name attributes for JS.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getPostTerms(int $postId, string $taxonomyKey): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('
            SELECT t.id, t.name, t.slug
            FROM post_term_relationships ptr
            JOIN terms t ON t.id = ptr.term_id
            WHERE ptr.post_id = ? AND t.taxonomy_key = ?
            ORDER BY t.name ASC
        ');
        $stmt->execute([$postId, $taxonomyKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The terms each of these entries is filed under, per taxonomy, in ONE
     * query — for a list that prints them on every row and must not ask once
     * per row to do it. Only the taxonomies named; ordered by name, the way
     * `getPostTerms()` orders one entry's.
     *
     * @param  int[]    $postIds
     * @param  string[] $taxonomyKeys
     * @return array<int, array<string, list<array{id: int, name: string}>>>
     *         post id => taxonomy key => its terms
     */
    public static function forPosts(array $postIds, array $taxonomyKeys): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn (int $id): bool => $id > 0)));
        $taxonomyKeys = array_values(array_unique(array_map('strval', $taxonomyKeys)));
        if ($postIds === [] || $taxonomyKeys === []) {
            return [];
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT ptr.post_id, t.id, t.name, t.taxonomy_key
               FROM post_term_relationships ptr
               JOIN terms t ON t.id = ptr.term_id
              WHERE ptr.post_id IN (' . implode(',', array_fill(0, count($postIds), '?')) . ')
                AND t.taxonomy_key IN (' . implode(',', array_fill(0, count($taxonomyKeys), '?')) . ')
           ORDER BY t.name ASC'
        );
        $stmt->execute(array_merge($postIds, $taxonomyKeys));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['post_id']][(string) $row['taxonomy_key']][] = [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
        return $out;
    }

    /**
     * Set the complete term assignment for a post/taxonomy pair.
     *
     * Deletes existing assignments for this post+taxonomy, then inserts the new set.
     * Scoped to one taxonomy at a time — other taxonomy assignments on the same post are untouched.
     * Wrapped in a transaction for atomicity.
     *
     * @param int[] $termIds  Pass empty array to clear all assignments for this taxonomy.
     */
    public static function setPostTerms(int $postId, string $taxonomyKey, array $termIds, ?string $postType = null): void
    {
        $db = Database::getInstance();

        // THE ENTRY'S TYPE, for its own rule (`countRule()`): asked of the
        // entry when the caller did not say it and some type has a rule of its
        // own for this taxonomy, so every caller meets the same rule.
        if ($postType === null && self::typesWithRule($taxonomyKey) !== []) {
            $stmt = $db->prepare('SELECT post_type FROM posts WHERE id = ?');
            $stmt->execute([$postId]);
            $postType = ($found = $stmt->fetchColumn()) !== false ? (string) $found : null;
        }

        // Sanitise input
        $termIds = array_values(array_unique(array_map('intval', $termIds)));
        $termIds = array_filter($termIds, fn(int $id): bool => $id > 0);

        // ONLY THIS TAXONOMY'S TERMS. The delete below is scoped to the
        // taxonomy and the insert is not, so a term id from ANOTHER taxonomy —
        // a tag posted as a category — would delete the entry's real category
        // and file the tag in its place. Dropped here for every caller; the API
        // also refuses such a request by name.
        $termIds = array_values(array_diff($termIds, self::idsOutside($taxonomyKey, $termIds)));

        // AND THE RULE — exactly one, at least one, no more than N: the type's
        // own for this taxonomy, else the taxonomy's (migration 056; 151).
        // Every write path comes through here: the entry form, the API, bulk
        // filing. The form and the API ask `countViolation()` first so their
        // refusal has words and a place on the screen; this is what stops a
        // caller that did not ask.
        $problem = self::countViolation($taxonomyKey, $termIds, $postType);
        if ($problem !== null) {
            throw new \DomainException($problem);
        }

        // JOINED, NOT NESTED. An import writes a whole file in one transaction
        // and files each entry through here, and PDO has no nested
        // transactions: beginning a second one threw "There is already an
        // active transaction" and rolled the whole import back. Inside a
        // caller's transaction this is part of it; on its own it is its own.
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            // Delete existing assignments for this post/taxonomy pair only
            $stmt = $db->prepare('
                DELETE ptr FROM post_term_relationships ptr
                JOIN terms t ON t.id = ptr.term_id
                WHERE ptr.post_id = ? AND t.taxonomy_key = ?
            ');
            $stmt->execute([$postId, $taxonomyKey]);

            // Insert new assignments
            if (!empty($termIds)) {
                $stmt = $db->prepare(
                    'INSERT IGNORE INTO post_term_relationships (post_id, term_id) VALUES (?, ?)'
                );
                foreach ($termIds as $termId) {
                    $stmt->execute([$postId, $termId]);
                }
            }

            if ($own) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * FOLD ONE TERM INTO ANOTHER of the same taxonomy.
     *
     *   · every entry filed under `$fromId` is filed under `$intoId` instead —
     *     every state, the trash included, so an entry restored next month comes
     *     back filed under what its term became rather than under nothing;
     *   · its children move under `$intoId`;
     *   · menu items pointing at it point at `$intoId`, rather than at a term
     *     that is about to stop existing;
     *   · then it is deleted. The ADDRESS is the caller's: a redirect is an SEO
     *     decision about a public route, and the model knows no routes.
     *
     * FILING GOES THROUGH countViolation() AND setPostTerms(), like every other
     * filing. Replacing one term with another cannot break a rule by itself, but
     * an entry that already breaks one — filed before the rule tightened — is
     * refused by name, and then NOTHING moves: the term stays whole, because
     * deleting it would unfile whatever the refusal left behind.
     *
     * @return list<array{title: string, reason: string}>  refusals; [] = merged
     */
    public static function merge(int $fromId, int $intoId): array
    {
        $from = self::find($fromId);
        $into = self::find($intoId);
        if ($from === null || $into === null || $fromId === $intoId
            || (string) $from['taxonomy_key'] !== (string) $into['taxonomy_key']) {
            return [['title' => (string) ($from['name'] ?? ''), 'reason' => 'It can only be merged into another term of the same taxonomy.']];
        }
        $key = (string) $from['taxonomy_key'];
        $db  = Database::getInstance();

        $stmt = $db->prepare(
            'SELECT p.id, p.title, p.post_type FROM post_term_relationships ptr
               JOIN posts p ON p.id = ptr.post_id
              WHERE ptr.term_id = ?'
        );
        $stmt->execute([$fromId]);

        // EVERY CHECK BEFORE ANY WRITE, so a refusal leaves nothing half-moved.
        $plans   = [];
        $refused = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $current = array_map('intval', self::getPostTermIds((int) $row['id'], $key));
            $new     = array_values(array_unique(array_merge(array_diff($current, [$fromId]), [$intoId])));
            $problem = self::countViolation($key, $new, (string) $row['post_type']);
            if ($problem !== null) {
                $refused[] = ['title' => (string) $row['title'], 'reason' => $problem];
                continue;
            }
            $plans[(int) $row['id']] = [$new, (string) $row['post_type']];
        }
        if ($refused !== []) {
            return $refused;
        }

        foreach ($plans as $postId => [$new, $postType]) {
            self::setPostTerms($postId, $key, $new, $postType);
        }

        // ── ITS CHILDREN GO UNDER THE TERM IT BECAME ────────────────────────
        // Unless that term sits inside its branch: then the target first takes
        // the source's own place, or moving the children under it would make a
        // loop (A under B under A) that nothing in the tree could walk out of.
        if (in_array($intoId, self::getDescendantIds($fromId, $key), true)) {
            $db->prepare('UPDATE terms SET parent_id = ? WHERE id = ?')
               ->execute([$from['parent_id'] !== null ? (int) $from['parent_id'] : null, $intoId]);
        }
        $db->prepare('UPDATE terms SET parent_id = ? WHERE parent_id = ? AND id <> ?')
           ->execute([$intoId, $fromId, $intoId]);

        $db->prepare("UPDATE menu_items SET target_id = ? WHERE type = 'taxonomy' AND target_id = ?")
           ->execute([$intoId, $fromId]);

        self::delete($fromId);

        return [];
    }

    /**
     * What is wrong with filing an entry of `$postType` under `$termIds` in
     * this taxonomy, in words — or null when nothing is.
     *
     * The rule is `countRule()`'s: none needed or at least one, and no limit
     * or no more than N; "exactly one" is both at 1. Only this taxonomy's
     * terms count — another's are dropped before anything is written.
     *
     * @param int[] $termIds
     */
    public static function countViolation(string $taxonomyKey, array $termIds, ?string $postType = null): ?string
    {
        $tax = TaxonomyRegistry::get($taxonomyKey);
        [$min, $max] = self::countRule($taxonomyKey, $postType);
        if ($tax === null || ($min === 0 && $max === null)) {
            return null;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $termIds), static fn (int $id): bool => $id > 0)));
        $n   = count(array_diff($ids, self::idsOutside($taxonomyKey, $ids)));
        $one = strtolower((string) $tax['label_single']);
        $a   = preg_match('/^[aeiou]/', $one) ? 'an' : 'a';

        if ($min === 1 && $max === 1 && $n !== 1) {
            return $n === 0
                ? 'Choose ' . $a . ' ' . $one . ' — every entry is filed under exactly one.'
                : 'File this under exactly one ' . $one . ', not ' . $n . '.';
        }
        if ($n < $min) {
            return 'Choose at least one ' . $one . '.';
        }
        if ($max !== null && $n > $max) {
            return 'No more than ' . $max . ' ' . ($max === 1 ? $one : strtolower((string) $tax['label']))
                . ' — ' . $n . ' are chosen.';
        }

        return null;
    }

    /**
     * HOW MANY TERMS OF A TAXONOMY AN ENTRY OF A TYPE TAKES, as [least, most],
     * most null for no limit: the type's own rule for the taxonomy when it
     * gives one (`'terms' => ['location' => ['min' => 1, 'max' => 1]]` in
     * code, or the Content Types screen, migration 151), for that type's
     * entries alone; else the taxonomy's own (migration 056). So a location
     * can be required on events while a contact for the whole organisation
     * has none. A type's rule that is not one is said in the log, and the
     * taxonomy's own is kept.
     *
     * @return array{0: int, 1: int|null}
     */
    public static function countRule(string $taxonomyKey, ?string $postType = null): array
    {
        $tax = TaxonomyRegistry::get($taxonomyKey);
        $own = $postType !== null ? (\Cms\ContentType\Registry::get($postType)['terms'][$taxonomyKey] ?? null) : null;
        if ($own !== null) {
            $rule = self::ruleOf($own);
            if ($rule !== null) {
                return $rule;
            }
            error_log('Content types: "' . $postType . '" gives the taxonomy "' . $taxonomyKey
                . '" a rule there is none of (its min is 0 or 1, its max 1 to 99 or none); the taxonomy\'s own is kept');
        }

        return [(int) ($tax['min_terms'] ?? 0), isset($tax['max_terms']) ? (int) $tax['max_terms'] : null];
    }

    /**
     * A type's rule for a taxonomy as [least, most], or null when it is not
     * one: `min` 0 or 1, 0 unless it says, and `max` 1 to 99, or none.
     *
     * @return array{0: int, 1: int|null}|null
     */
    public static function ruleOf(mixed $rule): ?array
    {
        if (!is_array($rule) || array_diff(array_keys($rule), ['min', 'max']) !== []) {
            return null;
        }
        $whole = static fn (mixed $n): bool => is_int($n) || (is_string($n) && ctype_digit($n));
        $min   = $rule['min'] ?? 0;
        $max   = $rule['max'] ?? null;
        if (!$whole($min) || (int) $min > 1 || ($max !== null && (!$whole($max) || (int) $max < 1 || (int) $max > 99))) {
            return null;
        }

        return [(int) $min, $max === null ? null : (int) $max];
    }

    /**
     * The types with a rule of their own for this taxonomy, by key.
     *
     * @return list<string>
     */
    public static function typesWithRule(string $taxonomyKey): array
    {
        $out = [];
        foreach (\Cms\ContentType\Registry::all() as $key => $type) {
            if (isset($type['terms'][$taxonomyKey])) {
                $out[] = (string) $key;
            }
        }

        return $out;
    }

    /**
     * The slugs of those of `$termIds` that are terms of `$taxonomyKey`, in
     * their order: what a logic rule naming a term by its slug is asked of.
     *
     * @param int[] $termIds
     * @return list<string>
     */
    public static function slugsOf(string $taxonomyKey, array $termIds): array
    {
        $termIds = array_values(array_unique(array_filter(array_map('intval', $termIds), static fn (int $id): bool => $id > 0)));
        if ($termIds === []) {
            return [];
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT id, slug FROM terms WHERE taxonomy_key = ? AND id IN ('
            . implode(',', array_fill(0, count($termIds), '?')) . ')'
        );
        $stmt->execute(array_merge([$taxonomyKey], $termIds));
        $byId = array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        $out  = [];
        foreach ($termIds as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /**
     * The ids in `$termIds` that are NOT terms of `$taxonomyKey` — another
     * taxonomy's, or no term at all.
     *
     * @param  int[] $termIds
     * @return int[]
     */
    public static function idsOutside(string $taxonomyKey, array $termIds): array
    {
        $termIds = array_values(array_unique(array_map('intval', $termIds)));
        if ($termIds === []) {
            return [];
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT id FROM terms WHERE taxonomy_key = ? AND id IN ('
            . implode(',', array_fill(0, count($termIds), '?')) . ')'
        );
        $stmt->execute(array_merge([$taxonomyKey], $termIds));
        $inside = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return array_values(array_diff($termIds, $inside));
    }

    /**
     * Get all descendant IDs of a given term (recursive, depth-first).
     * Used when building parent selectors to exclude self and descendants.
     *
     * @return int[]
     */
    public static function getDescendantIds(int $termId, string $taxonomyKey): array
    {
        $all         = self::allForTaxonomy($taxonomyKey);
        $descendants = [];
        self::collectDescendants($termId, $all, $descendants);
        return $descendants;
    }

    /**
     * @param array<int, array<string, mixed>> $all
     * @param int[]                            $collected
     */
    private static function collectDescendants(int $parentId, array $all, array &$collected): void
    {
        foreach ($all as $term) {
            $id = (int) $term['id'];
            // A PARENT LOOP ENDS HERE rather than recursing until PHP gives up.
            // The admin refuses one, but this now runs on every PUBLIC term
            // archive (a pillar includes its children), and a loop left in the
            // data by an import would take those pages down with it.
            if ((int) $term['parent_id'] === $parentId && !in_array($id, $collected, true)) {
                $collected[] = $id;
                self::collectDescendants($id, $all, $collected);
            }
        }
    }
}
