<?php

declare(strict_types=1);

namespace Cms\Update;

use PDO;
use RuntimeException;

/**
 * The database before an update: a dump to restore, and a fingerprint to prove
 * the restore put back exactly what was there.
 *
 * ── WHY NOT mysqldump ───────────────────────────────────────────────────────
 * Shared hosting often has no `mysqldump` on the path, or no `exec()`. This
 * runs over the same PDO connection the site already uses, so a backup is
 * possible wherever the site itself works — and there is one code path to
 * test, not two.
 *
 * ── THE FORMAT ──────────────────────────────────────────────────────────────
 * Gzipped SQL, ONE STATEMENT PER LINE. Values go through `PDO::quote()`, which
 * escapes newlines, so no statement spans two lines; `SHOW CREATE TABLE` puts
 * newlines only between definitions (a comment or default containing one comes
 * back escaped), so they are folded to spaces. A restore can then run the file
 * line by line with no SQL parser to get wrong.
 *
 * ── WHAT IT REFUSES ─────────────────────────────────────────────────────────
 * Views, triggers, routines and events. The CMS creates none; a database that
 * has one has something this class would not put back, and a backup that
 * silently leaves something out is worse than no backup. Binary and generated
 * columns are handled (hex literals; left out of the INSERT) although no
 * migration creates one today.
 */
final class DbSnapshot
{
    /** Keep each INSERT well under a shared host's max_allowed_packet. */
    private const BATCH_BYTES = 512 * 1024;

    /**
     * Per table: the CREATE statement's hash, the row count and the server's
     * own checksum of the rows. Two databases with the same fingerprint hold
     * the same tables with the same rows.
     *
     * @return array<string, array{create: string, rows: int, checksum: string}>
     */
    public static function fingerprint(PDO $pdo): array
    {
        self::refuseUnsupported($pdo);

        $tables = [];
        foreach (self::baseTables($pdo) as $table) {
            $create = self::createStatement($pdo, $table);
            // AUTO_INCREMENT is part of the table's state (a restore must not
            // hand out an id that was already used), so it stays in the hash.
            $rows     = (int) $pdo->query('SELECT COUNT(*) FROM ' . self::q($table))->fetchColumn();
            $checksum = $pdo->query('CHECKSUM TABLE ' . self::q($table) . ' EXTENDED')->fetch(PDO::FETCH_NUM);
            $tables[$table] = [
                'create'   => hash('sha256', $create),
                'rows'     => $rows,
                'checksum' => (string) ($checksum[1] ?? ''),
            ];
        }
        ksort($tables);

        return $tables;
    }

    /**
     * Write every table, structure and rows, to `$file` (gzipped).
     *
     * @return array{tables: int, rows: int, bytes: int}
     */
    public static function dump(PDO $pdo, string $file): array
    {
        self::refuseUnsupported($pdo);

        $out = gzopen($file, 'wb6');
        if ($out === false) {
            throw new RuntimeException("Cannot write the database backup to {$file}.");
        }
        @chmod($file, 0600);

        $write = static function (string $line) use ($out, $file): void {
            if (gzwrite($out, $line . "\n") === false) {
                throw new RuntimeException("Writing the database backup to {$file} failed.");
            }
        };

        $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $write('-- CMS database snapshot of `' . $db . '`, ' . gmdate('Y-m-d\TH:i:s\Z') . '. One statement per line.');
        $write("SET NAMES utf8mb4");
        $write("SET FOREIGN_KEY_CHECKS=0");
        $write("SET UNIQUE_CHECKS=0");
        $write("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
        $write("SET time_zone='+00:00'");

        $totalRows = 0;
        $tables    = self::baseTables($pdo);
        foreach ($tables as $table) {
            $write('DROP TABLE IF EXISTS ' . self::q($table));
            $write(str_replace(["\r\n", "\n", "\r"], ' ', self::createStatement($pdo, $table)));

            $columns = [];
            $binary  = [];
            foreach ($pdo->query('SHOW COLUMNS FROM ' . self::q($table))->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (stripos((string) $col['Extra'], 'GENERATED') !== false) {
                    continue;
                }
                $columns[] = (string) $col['Field'];
                if (preg_match('/blob|binary|^bit/i', (string) $col['Type']) === 1) {
                    $binary[(string) $col['Field']] = true;
                }
            }
            if ($columns === []) {
                continue;
            }

            $head  = 'INSERT INTO ' . self::q($table) . ' (' . implode(', ', array_map([self::class, 'q'], $columns)) . ') VALUES ';
            $batch = [];
            $size  = 0;
            $stmt  = $pdo->query('SELECT ' . implode(', ', array_map([self::class, 'q'], $columns)) . ' FROM ' . self::q($table));
            while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                $values = [];
                foreach ($row as $i => $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (isset($binary[$columns[$i]])) {
                        $values[] = "X'" . bin2hex((string) $value) . "'";
                    } else {
                        $values[] = $pdo->quote((string) $value);
                    }
                }
                $tuple   = '(' . implode(', ', $values) . ')';
                $batch[] = $tuple;
                $size   += strlen($tuple);
                $totalRows++;
                if ($size >= self::BATCH_BYTES) {
                    $write($head . implode(', ', $batch));
                    $batch = [];
                    $size  = 0;
                }
            }
            if ($batch !== []) {
                $write($head . implode(', ', $batch));
            }
        }

        $write("SET FOREIGN_KEY_CHECKS=1");
        $write("SET UNIQUE_CHECKS=1");
        $write('-- end of snapshot');
        if (!gzclose($out)) {
            throw new RuntimeException("Closing the database backup {$file} failed.");
        }

        return ['tables' => count($tables), 'rows' => $totalRows, 'bytes' => (int) filesize($file)];
    }

    /**
     * Put the database back to the snapshot: every table the snapshot does not
     * contain is dropped (an update's migrations may have created some), then
     * the file is replayed line by line.
     */
    public static function restore(PDO $pdo, string $file): void
    {
        $in = gzopen($file, 'rb');
        if ($in === false) {
            throw new RuntimeException("Cannot read the database backup {$file}.");
        }

        // Read the whole file first: a truncated backup must fail before a
        // single table is dropped, not halfway through the restore.
        $lines = [];
        while (($line = gzgets($in)) !== false) {
            $lines[] = rtrim($line, "\n");
        }
        gzclose($in);
        if ($lines === [] || end($lines) !== '-- end of snapshot') {
            throw new RuntimeException("{$file} is incomplete (no end marker). Nothing was restored.");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::baseTables($pdo) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . self::q($table));
        }
        foreach ($lines as $number => $line) {
            if ($line === '' || str_starts_with($line, '-- ')) {
                continue;
            }
            try {
                $pdo->exec($line);
            } catch (\Throwable $e) {
                throw new RuntimeException('Restoring line ' . ($number + 1) . " of {$file} failed: " . $e->getMessage());
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * How two fingerprints differ, one line per table. Empty means identical.
     *
     * @param array<string, array<string, mixed>> $before
     * @param array<string, array<string, mixed>> $after
     * @return list<string>
     */
    public static function compare(array $before, array $after): array
    {
        $diff = [];
        foreach ($before as $table => $was) {
            if (!isset($after[$table])) {
                $diff[] = "{$table}: missing";
                continue;
            }
            foreach (['create' => 'structure', 'rows' => 'row count', 'checksum' => 'rows'] as $key => $label) {
                if ((string) $was[$key] !== (string) $after[$table][$key]) {
                    $diff[] = "{$table}: {$label} differs ({$was[$key]} → {$after[$table][$key]})";
                }
            }
        }
        foreach (array_diff_key($after, $before) as $table => $_) {
            $diff[] = "{$table}: not in the snapshot";
        }

        return $diff;
    }

    /** @return list<string> */
    private static function baseTables(PDO $pdo): array
    {
        $tables = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
              ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $tables);
    }

    private static function createStatement(PDO $pdo, string $table): string
    {
        $row = $pdo->query('SHOW CREATE TABLE ' . self::q($table))->fetch(PDO::FETCH_NUM);
        if ($row === false || !isset($row[1])) {
            throw new RuntimeException("SHOW CREATE TABLE {$table} returned nothing.");
        }

        return (string) $row[1];
    }

    private static function refuseUnsupported(PDO $pdo): void
    {
        $checks = [
            'view'    => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE <> 'BASE TABLE'",
            'trigger' => 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()',
            'routine' => 'SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()',
            'event'   => 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()',
        ];
        foreach ($checks as $kind => $sql) {
            $names = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
            if ($names !== []) {
                throw new RuntimeException(
                    "The database has a {$kind} (" . implode(', ', $names) . ') that this backup cannot save. '
                    . 'The CMS creates none; take a mysqldump by hand and remove it, or do not update.'
                );
            }
        }
    }

    private static function q(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
