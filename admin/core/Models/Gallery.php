<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

class Gallery
{
    /** The most characters an item's title and caption hold (migration 127). */
    public const TITLE_MAX   = 200;
    public const CAPTION_MAX = 1000;

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
                    gi.title, gi.caption, gi.link_url, gi.thumb_attachment_id,
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
     * its details: `attachment_id`, and optionally `title` (migration 127),
     * `caption`, `link_url` and `thumb_attachment_id`. Both shapes are taken.
     * An empty detail is NULL, so "has a caption" is one test.
     * The caller vouches for a thumbnail id: the key refuses one that is not a
     * file (GalleryController::parseItems() checks first).
     *
     * A TITLE AND A CAPTION ARE KEPT AS GIVEN. Only the white space at their
     * ends goes — `trim()`'s, which leaves a no-break space where it is. One
     * longer than its column holds is refused with an
     * \InvalidArgumentException naming the item, before anything is written,
     * so the gallery stays as it was; neither is ever cut short.
     *
     * @param int   $galleryId
     * @param list<int|array<string, mixed>> $items  in display order
     */
    public static function setItems(int $galleryId, array $items): void
    {
        $rows = [];
        foreach (array_values($items) as $position => $item) {
            $row     = is_array($item) ? $item : ['attachment_id' => $item];
            $title   = trim((string) ($row['title'] ?? ''));
            $caption = trim((string) ($row['caption'] ?? ''));
            $refused = self::tooLong($title, $caption, 'item ' . ($position + 1));
            if ($refused !== []) {
                throw new \InvalidArgumentException(implode(' ', $refused));
            }
            $link  = trim((string) ($row['link_url'] ?? ''));
            $thumb = (int) ($row['thumb_attachment_id'] ?? 0);
            $rows[] = [
                ':aid'     => (int) ($row['attachment_id'] ?? 0),
                ':pos'     => $position,
                ':title'   => $title === '' ? null : $title,
                ':caption' => $caption === '' ? null : $caption,
                ':link'    => $link === '' ? null : mb_substr($link, 0, 500),
                ':thumb'   => $thumb > 0 ? $thumb : null,
            ];
        }

        $pdo = Database::getInstance();

        $stmt = $pdo->prepare("DELETE FROM gallery_items WHERE gallery_id = :gid");
        $stmt->execute([':gid' => $galleryId]);

        if ($rows === []) {
            return;
        }

        $insertStmt = $pdo->prepare(
            "INSERT INTO gallery_items (gallery_id, attachment_id, position, title, caption, link_url, thumb_attachment_id)
             VALUES (:gid, :aid, :pos, :title, :caption, :link, :thumb)"
        );
        foreach ($rows as $row) {
            $insertStmt->execute([':gid' => $galleryId] + $row);
        }
    }

    /**
     * Why an item's title or caption cannot be kept, one sentence for each
     * that is too long, or [] when both fit. Each is counted in characters,
     * as its column counts them. `$which` names the item: "item 3", or a
     * picture's name in quotes.
     *
     * @return list<string>
     */
    public static function tooLong(string $title, string $caption, string $which): array
    {
        $refused = [];
        foreach (['title' => [$title, self::TITLE_MAX], 'caption' => [$caption, self::CAPTION_MAX]] as $detail => [$text, $most]) {
            $length = mb_strlen($text);
            if ($length > $most) {
                $refused[] = 'The ' . $detail . ' of ' . $which . ' has ' . number_format($length) . ' characters; a '
                           . $detail . ' can be at most ' . number_format($most) . '.';
            }
        }
        return $refused;
    }
}
