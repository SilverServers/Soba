<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

/**
 * Option model — site-wide key/value settings stored in the `options` table.
 */
class Option
{
    /**
     * Get a single option value by key. Returns $default if not found.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT option_value FROM options WHERE option_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row['option_value'] : $default;
    }

    /**
     * Get multiple options at once. Returns an associative array keyed by option_key.
     * Missing keys are filled with their default value (null if not specified).
     *
     * @param array<string, string|null> $keysWithDefaults  ['key' => 'default', ...]
     * @return array<string, string|null>
     */
    public static function getMany(array $keysWithDefaults): array
    {
        if (empty($keysWithDefaults)) {
            return [];
        }

        $pdo  = Database::getInstance();
        $keys = array_keys($keysWithDefaults);

        // Build parameterized IN clause
        $placeholders = [];
        $params       = [];
        foreach ($keys as $i => $key) {
            $param = ':k' . $i;
            $placeholders[] = $param;
            $params[$param] = $key;
        }

        $sql  = 'SELECT option_key, option_value FROM options WHERE option_key IN (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Merge with defaults for any missing keys
        $result = [];
        foreach ($keysWithDefaults as $key => $default) {
            $result[$key] = array_key_exists($key, $rows) ? $rows[$key] : $default;
        }

        return $result;
    }

    /**
     * Set a single option value. Inserts if the key doesn't exist, updates if it does.
     */
    public static function set(string $key, ?string $value): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO options (option_key, option_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE option_value = :value2'
        );
        $stmt->execute([
            ':key'    => $key,
            ':value'  => $value,
            ':value2' => $value,
        ]);
    }

    /**
     * Set multiple options at once.
     *
     * @param array<string, string|null> $options  ['key' => 'value', ...]
     */
    public static function setMany(array $options): void
    {
        if (empty($options)) {
            return;
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO options (option_key, option_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE option_value = :value2'
        );

        foreach ($options as $key => $value) {
            $stmt->execute([
                ':key'    => $key,
                ':value'  => $value,
                ':value2' => $value,
            ]);
        }
    }

    /**
     * Delete an option by key.
     */
    public static function delete(string $key): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('DELETE FROM options WHERE option_key = :key');
        $stmt->execute([':key' => $key]);
    }
}
