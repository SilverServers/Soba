<?php

declare(strict_types=1);

namespace Cms\Database;

/**
 * WHICH TABLES THIS REQUEST WROTE — the audit log's evidence (`Security\Audit`)
 * that an administrator's form changed something, and what kind of thing.
 *
 * Fed by `Connection` and `Statement`, which see every INSERT, REPLACE, UPDATE
 * and DELETE that succeeds, whatever code sent it — so no controller can be
 * the one that forgot to say. Only the first table a statement names is kept:
 * its target. Never a value.
 *
 * A statement that changed no row is not a write: a form saved exactly as it
 * was (`ON DUPLICATE KEY UPDATE` to the same value affects none), a delete
 * of something already gone. The log says "Changed" only when something was.
 */
final class Writes
{
    /** @var array<string, true> */
    private static array $tables = [];

    /** `$affected` is the database's own count of rows the statement changed. */
    public static function note(string $sql, int $affected): void
    {
        if ($affected < 1) {
            return;
        }
        $match = preg_match(
            '/^\s*(?:(?:INSERT|REPLACE)\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+|IGNORE\s+)*(?:INTO\s+)?`?(\w+)`?'
            . '|UPDATE\s+(?:LOW_PRIORITY\s+|IGNORE\s+)*`?(\w+)`?'
            . '|DELETE\s+(?:LOW_PRIORITY\s+|QUICK\s+|IGNORE\s+)*(?:\w+\s+)?FROM\s+`?(\w+)`?)/i',
            $sql,
            $m
        );
        if ($match !== 1) {
            return;
        }
        $table = strtolower(($m[1] ?? '') !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '')));
        if ($table !== '') {
            self::$tables[$table] = true;
        }
    }

    /** @return list<string> in the order first written */
    public static function tables(): array
    {
        return array_keys(self::$tables);
    }

    /** Forget — for the checks, which run many "requests" in one process. */
    public static function reset(): void
    {
        self::$tables = [];
    }
}
