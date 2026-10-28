<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

class Form
{
    /**
     * Get all forms with submission counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            // RECEIVED ONLY. Spam is never a submission and never unread
            // (`Forms\Guard`); counted here it would make the forms list report
            // every bot that ever touched the form.
            "SELECT f.*,
                    (SELECT COUNT(*) FROM form_submissions WHERE form_id = f.id AND status = 'received') AS submission_count,
                    (SELECT COUNT(*) FROM form_submissions WHERE form_id = f.id AND status = 'received' AND is_read = 0) AS unread_count
             FROM forms f
             ORDER BY f.created_at DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find a single form by ID. Decodes JSON columns.
     */
    public static function find(int $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['fields']   = $row['fields']   ? json_decode($row['fields'], true) : [];
        $row['settings'] = $row['settings'] ? json_decode($row['settings'], true) : [];
        return $row;
    }

    /**
     * The form shown in one of the site's places (`Helpers\Places`, kind
     * 'form'), or null. A place shows one form
     * at most.
     *
     * @return array<string, mixed>|null
     */
    public static function byPlace(string $key): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT id FROM forms WHERE place_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $id = $stmt->fetchColumn();
        return $id !== false ? self::find((int) $id) : null;
    }

    /**
     * Show a form in one of the site's places, or in none (null). A place
     * another form holds MOVES: that form leaves it in the same transaction,
     * and its row is returned so the caller can say so (null otherwise).
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
                $pdo->prepare('UPDATE forms SET place_key = NULL WHERE id = :id')->execute([':id' => (int) $had['id']]);
            }
            $pdo->prepare('UPDATE forms SET place_key = :key WHERE id = :id')->execute([':key' => $key, ':id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $had;
    }

    /**
     * Create a new form.
     */
    public static function create(string $title, array $fields = [], array $settings = []): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "INSERT INTO forms (title, fields, settings) VALUES (:title, :fields, :settings)"
        );
        $stmt->execute([
            ':title'    => $title,
            ':fields'   => json_encode($fields),
            ':settings' => json_encode($settings),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Update an existing form.
     */
    public static function update(int $id, string $title, array $fields = [], array $settings = []): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "UPDATE forms SET title = :title, fields = :fields, settings = :settings WHERE id = :id"
        );
        return $stmt->execute([
            ':title'    => $title,
            ':fields'   => json_encode($fields),
            ':settings' => json_encode($settings),
            ':id'       => $id,
        ]);
    }

    /**
     * Delete a form (submissions cascade).
     */
    public static function delete(int $id): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("DELETE FROM forms WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
