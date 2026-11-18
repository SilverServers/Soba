<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

class Gallery
{
    public static function all(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            "SELECT g.*,
                    (SELECT COUNT(*) FROM gallery_items WHERE gallery_id = g.id) AS item_count
             FROM galleries g
             ORDER BY g.created_at DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The gallery shown in one of the site's places (`Helpers\Places`, kind
     * 'gallery'), or null. A place shows one
     * gallery at most.
     *
     * @return array<string, mixed>|null
     */
    public static function byPlace(string $key): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT id FROM galleries WHERE place_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $id = $stmt->fetchColumn();
        return $id !== false ? self::find((int) $id) : null;
    }

    /**
     * Show a gallery in one of the site's places, or in none (null). A place
     * another gallery holds MOVES: that gallery leaves it in the same
     * transaction, and its row is returned so the caller can say so (null
     * otherwise) — `Form::place()`'s rule, for the same unique key.
     *
     * @return array<string, mixed>|null
     */
    public static function place(int $id, ?string $key): ?array
    {
        $key = $key !== null && trim($key) !== '' ? trim($key) : null;
        $pdo = Database::getInstance();
        $had = $key !== null ? self::byPlace($key) : null;
        if ($had !== null && (int) $had['id'] === $id) {
            return null;
        }
        $pdo->beginTransaction();
        try {
            if ($had !== null) {
                $pdo->prepare('UPDATE galleries SET place_key = NULL WHERE id = :id')->execute([':id' => (int) $had['id']]);
            }
            $pdo->prepare('UPDATE galleries SET place_key = :key WHERE id = :id')->execute([':key' => $key, ':id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $had;
    }

    public static function find(int $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM galleries WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['settings'] = $row['settings'] ? json_decode($row['settings'], true) : [];
        return $row;
    }

    public static function create(string $title, ?string $description = null, array $settings = []): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "INSERT INTO galleries (title, description, settings) VALUES (:title, :desc, :settings)"
        );
        $stmt->execute([
            ':title'    => $title,
            ':desc'     => $description,
            ':settings' => json_encode($settings),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, string $title, ?string $description = null, array $settings = []): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "UPDATE galleries SET title = :title, description = :desc, settings = :settings WHERE id = :id"
        );
        return $stmt->execute([
            ':title'    => $title,
            ':desc'     => $description,
            ':settings' => json_encode($settings),
            ':id'       => $id,
        ]);
    }

    public static function delete(int $id): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("DELETE FROM galleries WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Get all items (images) for a gallery, ordered by position.
     * Joins to attachments for file details.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getItems(int $galleryId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT gi.id AS item_id, gi.attachment_id, gi.position,
                    gi.caption, gi.link_url, gi.thumb_attachment_id,
                    a.filename, a.site_path, a.original_name, a.mime_type, a.width, a.height,
                    a.alt_text, a.visibility
             FROM gallery_items gi
             JOIN attachments a ON a.id = gi.attachment_id
             WHERE gi.gallery_id = :gid
             ORDER BY gi.position ASC"
        );
        $stmt->execute([':gid' => $galleryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Replace all items in a gallery with a new ordered set.
     *
     * Each item is an attachment id, or — since migration 091 — an array with
     * its details: `attachment_id`, and optionally `caption`, `link_url` and
     * `thumb_attachment_id`. Both shapes are taken. An empty detail is NULL,
     * so "has a caption" is one test.
     * The caller vouches for a thumbnail id: the key refuses one that is not a
     * file (GalleryController::parseItems() checks first).
     *
     * @param int   $galleryId
     * @param list<int|array<string, mixed>> $items  in display order
     */
    public static function setItems(int $galleryId, array $items): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare("DELETE FROM gallery_items WHERE gallery_id = :gid");
        $stmt->execute([':gid' => $galleryId]);

        if (empty($items)) {
            return;
        }

        $insertStmt = $pdo->prepare(
            "INSERT INTO gallery_items (gallery_id, attachment_id, position, caption, link_url, thumb_attachment_id)
             VALUES (:gid, :aid, :pos, :caption, :link, :thumb)"
        );

        foreach (array_values($items) as $position => $item) {
            $row     = is_array($item) ? $item : ['attachment_id' => $item];
            $caption = trim((string) ($row['caption'] ?? ''));
            $link    = trim((string) ($row['link_url'] ?? ''));
            $thumb   = (int) ($row['thumb_attachment_id'] ?? 0);
            $insertStmt->execute([
                ':gid'     => $galleryId,
                ':aid'     => (int) ($row['attachment_id'] ?? 0),
                ':pos'     => $position,
                ':caption' => $caption === '' ? null : mb_substr($caption, 0, 300),
                ':link'    => $link === '' ? null : mb_substr($link, 0, 500),
                ':thumb'   => $thumb > 0 ? $thumb : null,
            ]);
        }
    }
}
