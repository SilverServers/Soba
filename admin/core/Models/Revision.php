<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

/**
 * Revision history for posts and pages.
 *
 * A revision is a full snapshot of an object's core columns plus every
 * post_meta value at that moment. Whole snapshots rather than diffs: restoring
 * is then just writing the row back, which is worth the storage for text content.
 *
 * The intended call site is immediately BEFORE a write — `snapshot()` captures
 * what is about to be overwritten, so the newest revision is always the
 * previous state, and the live row is always the current state. Nothing captures
 * the live row itself; that would double-store every save.
 *
 * Covers two tables (`posts` and `pages`) via object_type + object_id rather than
 * a foreign key, since MySQL cannot express a FK to one of two tables. That means
 * cascade deletion is this class's job — see deleteFor().
 */
class Revision
{
    public const TYPE_POST = 'post';
    public const TYPE_PAGE = 'page';

    /** Columns copied verbatim from the source row into the snapshot. */
    private const SNAPSHOT_COLUMNS = [
        'title', 'slug', 'content', 'excerpt', 'status', 'featured_image_id', 'menu_order',
        'post_type', 'parent_id', 'published_at', 'seo_title', 'meta_description',
    ];

    /**
     * Snapshot the CURRENT state of an object, before it is overwritten.
     *
     * Returns the new revision id, or null if the object no longer exists (which
     * is not an error — it just means there is nothing to preserve).
     *
     * @param string      $reason      'manual' | 'restore' | 'assistant'
     * @param string|null $changeSetId Set when applying an approved assistant change set
     */
    public static function snapshot(
        string $objectType,
        int $objectId,
        ?int $authorId = null,
        string $reason = 'manual',
        ?string $note = null,
        ?string $changeSetId = null
    ): ?int {
        $row = self::loadSource($objectType, $objectId);
        if ($row === null) {
            return null;
        }

        // Both have meta now. Pages gained custom fields in migration 036 — the
        // hero headline, the button labels — and this comment used to say meta
        // was a posts-only concern, so page revisions quietly captured none of
        // it. Undo then reported success and changed nothing, which is worse than
        // failing: the owner believes their site went back and it did not.
        $meta = $objectType === self::TYPE_POST ? Meta::getAll($objectId) : [];

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO revisions
                 (object_type, object_id, title, slug, content, excerpt, status,
                  featured_image_id, menu_order, post_type, parent_id, published_at,
                  seo_title, meta_description, meta_json, reason, note, change_set_id, author_id)
             VALUES
                 (:object_type, :object_id, :title, :slug, :content, :excerpt, :status,
                  :featured_image_id, :menu_order, :post_type, :parent_id, :published_at,
                  :seo_title, :meta_description, :meta_json, :reason, :note, :change_set_id, :author_id)'
        );

        $stmt->execute([
            ':object_type'       => $objectType,
            ':object_id'         => $objectId,
            ':title'             => (string) ($row['title'] ?? ''),
            ':slug'              => (string) ($row['slug'] ?? ''),
            ':content'           => $row['content'] ?? null,
            // Migration 053. Pages have no excerpt, so theirs snapshots as NULL.
            ':excerpt'           => $row['excerpt'] ?? null,
            ':status'            => (string) ($row['status'] ?? 'private'),
            ':featured_image_id' => $row['featured_image_id'] ?? null,
            ':menu_order'        => (int) ($row['menu_order'] ?? 0),
            ':post_type'         => $row['post_type'] ?? null,
            ':parent_id'         => $row['parent_id'] ?? null,
            ':published_at'      => $row['published_at'] ?? null,
            ':seo_title'         => $row['seo_title'] ?? null,
            ':meta_description'  => $row['meta_description'] ?? null,
            ':meta_json'         => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            ':reason'            => $reason,
            ':note'              => $note,
            ':change_set_id'     => $changeSetId,
            ':author_id'         => $authorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * All revisions for an object, newest first. Excludes the heavy columns so
     * this is cheap enough to render a history list.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listFor(string $objectType, int $objectId, int $limit = 50): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT r.id, r.title, r.status, r.reason, r.note, r.change_set_id,
                    r.created_at, r.author_id, ' . \Cms\Models\User::nameSql('u') . ' AS author_name
               FROM revisions r
          LEFT JOIN users u ON u.id = r.author_id
              WHERE r.object_type = :t AND r.object_id = :id
           ORDER BY r.created_at DESC, r.id DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute([':t' => $objectType, ':id' => $objectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * One full revision including content and meta_json.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM revisions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** Decoded meta map for a revision. */
    public static function metaFor(array $revision): array
    {
        if (empty($revision['meta_json'])) {
            return [];
        }
        $decoded = json_decode((string) $revision['meta_json'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /** How many revisions exist for an object. */
    public static function countFor(string $objectType, int $objectId): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM revisions WHERE object_type = :t AND object_id = :id'
        );
        $stmt->execute([':t' => $objectType, ':id' => $objectId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Roll an object back to a revision.
     *
     * Snapshots the current state first (reason 'restore'), so a rollback is
     * itself undoable — restoring never destroys the state it replaced.
     *
     * Returns false if the revision is missing or does not belong to the object.
     */
    public static function restore(int $revisionId, ?int $authorId = null): bool
    {
        $revision = self::find($revisionId);
        if ($revision === null) {
            return false;
        }

        $objectType = (string) $revision['object_type'];
        $objectId   = (int) $revision['object_id'];

        $live = self::loadSource($objectType, $objectId);
        if ($live === null) {
            return false;
        }

        self::snapshot(
            $objectType,
            $objectId,
            $authorId,
            'restore',
            'Auto-saved before restoring revision #' . $revisionId
        );

        // password_hash is carried over from the LIVE row, not the revision.
        // Post::update() and Page::update() null the column when it is absent, so
        // omitting it would silently strip the password from a protected entry.
        // Revisions deliberately do not snapshot it — history should not archive
        // credentials — which means the current password is the only correct value.
        if ($objectType === self::TYPE_POST) {
            Post::update($objectId, [
                'title'             => $revision['title'],
                'slug'              => $revision['slug'],
                'content'           => $revision['content'] ?? '',
                // Snapshotted since migration 053 — and restored in the same
                // commit, not a year later like `menu_order` below.
                'excerpt'           => $revision['excerpt'] ?? null,
                'status'            => $revision['status'],
                'password_hash'     => $live['password_hash'] ?? null,
                'featured_image_id' => $revision['featured_image_id'],
                'published_at'      => $revision['published_at'],
                // Snapshotted since this table was written (see COLUMNS above)
                // and never restored, because Post::update() did not carry it.
                // Undoing a reorder put the words back and left the order wrong.
                'menu_order'        => $revision['menu_order'],
            ]);

            // Meta is replaced wholesale: keys added after this revision are
            // removed, which is what "roll back to that version" has to mean.
            Meta::deleteAll($objectId);
            foreach (self::metaFor($revision) as $key => $value) {
                Meta::set($objectId, (string) $key, $value);
            }
        } else {
            // Same wholesale replace as posts: keys added after this revision go,
            // which is what "roll back to that version" has to mean.
            Page::update($objectId, [
                'parent_id'         => $revision['parent_id'],
                'title'             => $revision['title'],
                'slug'              => $revision['slug'],
                'content'           => $revision['content'] ?? '',
                'menu_order'        => $revision['menu_order'],
                'status'            => $revision['status'],
                'password_hash'     => $live['password_hash'] ?? null,
                'featured_image_id' => $revision['featured_image_id'],
                'seo_title'         => $revision['seo_title'],
                'meta_description'  => $revision['meta_description'],
            ]);
        }

        return true;
    }

    /**
     * Undo a whole assistant change set.
     *
     * A change set can touch several objects at once — two pricing tiers, a page's
     * body and its meta description — and each got its own pre-change snapshot.
     * Undoing it one revision at a time means hunting through several edit screens
     * and guessing which snapshot belongs to which half, which is exactly what the
     * site owner this feature exists for will not do.
     *
     * The **oldest** snapshot per object is the right one: a change set that
     * touched one object twice produced two snapshots, and only the first holds
     * the state from before the change set ran. Restoring the newest would put
     * back a half-applied state.
     *
     * @return array{restored: int, objects: int}
     */
    public static function restoreChangeSet(string $changeSetId, ?int $authorId = null): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT MIN(id) AS revision_id
               FROM revisions
              WHERE change_set_id = :cs
           GROUP BY object_type, object_id'
        );
        $stmt->execute([':cs' => $changeSetId]);
        $rows = $stmt->fetchAll();

        $restored = 0;

        foreach ($rows as $row) {
            if (self::restore((int) $row['revision_id'], $authorId)) {
                $restored++;
            }
        }

        return ['restored' => $restored, 'objects' => count($rows)];
    }

    /**
     * Drop every revision for an object.
     *
     * Call this when permanently deleting a post or page — the revisions table
     * has no FK to either table (it spans both), so nothing cascades for us.
     */
    public static function deleteFor(string $objectType, int $objectId): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'DELETE FROM revisions WHERE object_type = :t AND object_id = :id'
        );
        $stmt->execute([':t' => $objectType, ':id' => $objectId]);
    }

    /**
     * Trim an object's history to the newest $keep revisions.
     * Unlimited history is rarely wanted and grows without bound.
     */
    public static function prune(string $objectType, int $objectId, int $keep = 25): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id FROM revisions
              WHERE object_type = :t AND object_id = :id
           ORDER BY created_at DESC, id DESC
              LIMIT 100000 OFFSET ' . max(0, $keep)
        );
        $stmt->execute([':t' => $objectType, ':id' => $objectId]);
        $stale = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($stale === []) {
            return 0;
        }

        $in = implode(',', array_fill(0, count($stale), '?'));
        $pdo->prepare("DELETE FROM revisions WHERE id IN ($in)")->execute($stale);

        return count($stale);
    }

    /**
     * Fetch the live row a snapshot is taken from.
     *
     * @return array<string, mixed>|null
     */
    private static function loadSource(string $objectType, int $objectId): ?array
    {
        $row = $objectType === self::TYPE_POST
            ? Post::find($objectId)
            : Page::find($objectId);

        return $row ?: null;
    }
}
