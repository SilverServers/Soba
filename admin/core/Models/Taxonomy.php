<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

/**
 * Taxonomy model — CRUD for UI-managed taxonomy definitions.
 *
 * Code-registered taxonomies live in config/taxonomies.php (via TaxonomyRegistry::register()).
 * UI-created taxonomies are stored in the taxonomies table and loaded into
 * TaxonomyRegistry at bootstrap alongside code-registered ones.
 */
class Taxonomy
{
    /**
     * Return all UI-created taxonomies, ordered alphabetically.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $db   = Database::getInstance();
        $rows = $db->query('SELECT * FROM taxonomies ORDER BY plural_label ASC')
                   ->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['content_types'] = json_decode($row['content_types'], true) ?? [];
        }

        return $rows;
    }

    /**
     * Return only active UI-created taxonomies (used at bootstrap to load into TaxonomyRegistry).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allActive(): array
    {
        $db   = Database::getInstance();
        $rows = $db->query('SELECT * FROM taxonomies WHERE is_active = 1 ORDER BY plural_label ASC')
                   ->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['content_types'] = json_decode($row['content_types'], true) ?? [];
        }

        return $rows;
    }

    /**
     * Find a single taxonomy by its DB id.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM taxonomies WHERE id = ?');
        $stmt->execute([$id]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['content_types'] = json_decode($row['content_types'], true) ?? [];

        return $row;
    }

    /**
     * Find a single taxonomy by its taxonomy_key.
     *
     * @return array<string, mixed>|null
     */
    public static function findByKey(string $key): ?array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM taxonomies WHERE taxonomy_key = ?');
        $stmt->execute([$key]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['content_types'] = json_decode($row['content_types'], true) ?? [];

        return $row;
    }

    /**
     * Insert a new taxonomy. Returns the new row's id.
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO taxonomies
                (taxonomy_key, plural_label, singular_label, admin_slug, public_slug, content_types, is_hierarchical,
                 min_terms, max_terms, is_option, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['taxonomy_key'],
            $data['plural_label'],
            $data['singular_label'],
            $data['admin_slug'],
            // NULL, NOT '' — 048 makes the column nullable AND unique, so two
            // taxonomies holding an empty string would collide on that index.
            // Null means "no public archive", and any number of them may have
            // none.
            ($data['public_slug'] ?? '') !== '' ? $data['public_slug'] : null,
            
            json_encode($data['content_types'] ?? []),
            $data['is_hierarchical'] ? 1 : 0,
            // How many terms an entry takes (migration 056). Absent means the
            // old behaviour: none needed, no limit.
            (int) ($data['min_terms'] ?? 0),
            isset($data['max_terms']) ? (int) $data['max_terms'] : null,
            // Whether a customer chooses from it when buying (migration 095).
            !empty($data['is_option']) ? 1 : 0,
            $data['is_active'] ?? 1,
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Update an existing taxonomy.
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('
            UPDATE taxonomies
            SET taxonomy_key = ?, plural_label = ?, singular_label = ?,
                admin_slug = ?, public_slug = ?, content_types = ?, is_hierarchical = ?,
                min_terms = ?, max_terms = ?, is_option = ?, is_active = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $data['taxonomy_key'],
            $data['plural_label'],
            $data['singular_label'],
            $data['admin_slug'],
            ($data['public_slug'] ?? '') !== '' ? $data['public_slug'] : null,
            
            json_encode($data['content_types'] ?? []),
            $data['is_hierarchical'] ? 1 : 0,
            (int) ($data['min_terms'] ?? 0),
            isset($data['max_terms']) ? (int) $data['max_terms'] : null,
            !empty($data['is_option']) ? 1 : 0,
            $data['is_active'] ?? 1,
            $id,
        ]);
    }

    /**
     * Delete a taxonomy by id.
     * Note: This does NOT cascade-delete terms — terms with this taxonomy_key are orphaned.
     * This is intentional: if the taxonomy is re-registered with the same key, terms resurface.
     */
    /**
     * Delete a taxonomy AND every term filed under it.
     *
     * IT USED TO DELETE THE ROW AND NOTHING ELSE, and there is no foreign key to
     * cover for it: `terms.taxonomy_key` is a plain VARCHAR with an index, not a
     * reference — the only FK on that table is `parent_id` pointing at itself.
     * So deleting a taxonomy left every one of its terms in the table, invisible
     * to every screen (nothing renders terms of an unregistered taxonomy) and
     * still holding their `post_term_relationships` rows.
     *
     * Two things made it worse than a leak. The confirm dialog PROMISES "N terms
     * go with it, and their assignments are removed from every entry" — so the
     * screen was lying. And `terms` has `UNIQUE (taxonomy_key, slug)`, so
     * recreating a taxonomy with the same key and re-adding "News" would collide
     * with the orphan and fail with a duplicate-key error nobody could explain.
     *
     * Found by deleting a probe taxonomy and then reading the `terms` table
     * instead of the screen, which showed nothing either way.
     *
     * The terms go FIRST: `post_term_relationships` has ON DELETE CASCADE on
     * `term_id`, so removing the terms clears the assignments, and doing it in
     * that order means a failure part-way leaves a taxonomy with fewer terms
     * rather than terms with no taxonomy.
     */
    public static function delete(int $id): void
    {
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT taxonomy_key FROM taxonomies WHERE id = ?');
        $stmt->execute([$id]);
        $key = $stmt->fetchColumn();

        if ($key !== false) {
            $db->prepare('DELETE FROM terms WHERE taxonomy_key = ?')->execute([$key]);
        }

        $db->prepare('DELETE FROM taxonomies WHERE id = ?')->execute([$id]);
    }

    /**
     * Check whether a taxonomy_key is already in use (for validation).
     * Pass $excludeId when editing so the row's own key doesn't fail the check.
     */
    public static function keyExists(string $key, ?int $excludeId = null): bool
    {
        $db = Database::getInstance();

        if ($excludeId !== null) {
            $stmt = $db->prepare('SELECT id FROM taxonomies WHERE taxonomy_key = ? AND id != ?');
            $stmt->execute([$key, $excludeId]);
        } else {
            $stmt = $db->prepare('SELECT id FROM taxonomies WHERE taxonomy_key = ?');
            $stmt->execute([$key]);
        }

        return $stmt->fetch() !== false;
    }

    /**
     * Is this PUBLIC slug taken by another taxonomy?
     *
     * Migration 048 puts a unique index on the column, so the database would
     * refuse a duplicate — with a driver exception on a screen, rather than a
     * sentence naming the taxonomy that already has the address.
     */
    public static function publicSlugExists(string $slug, ?int $excludeId = null): bool
    {
        $db = Database::getInstance();

        if ($excludeId !== null) {
            $stmt = $db->prepare('SELECT id FROM taxonomies WHERE public_slug = ? AND id != ?');
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $db->prepare('SELECT id FROM taxonomies WHERE public_slug = ?');
            $stmt->execute([$slug]);
        }

        return $stmt->fetch() !== false;
    }

    /**
     * Check whether an admin_slug is already in use.
     */
    public static function adminSlugExists(string $slug, ?int $excludeId = null): bool
    {
        $db = Database::getInstance();

        if ($excludeId !== null) {
            $stmt = $db->prepare('SELECT id FROM taxonomies WHERE admin_slug = ? AND id != ?');
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $db->prepare('SELECT id FROM taxonomies WHERE admin_slug = ?');
            $stmt->execute([$slug]);
        }

        return $stmt->fetch() !== false;
    }
}
