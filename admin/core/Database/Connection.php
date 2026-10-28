<?php

declare(strict_types=1);

namespace Cms\Database;

use PDO;
use PDOStatement;

/**
 * THE CONNECTION — PDO, telling `Writes` what it wrote.
 *
 * Every query in the CMS passes through here (`Database::getInstance()`), so
 * this is the one place that can know, at the end of an administrator's
 * request, whether it changed anything — and that is what the audit log
 * records (`Security\Audit`). `exec()` and `query()` are noted here; prepared
 * statements by `Statement`. Behaviour and return values are PDO's own.
 */
final class Connection extends PDO
{
    public function exec(string $statement): int|false
    {
        $result = parent::exec($statement);
        if ($result !== false) {
            Writes::note($statement, $result);
        }

        return $result;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $result = $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        if ($result !== false) {
            Writes::note($query, $result->rowCount());
        }

        return $result;
    }
}
