<?php

declare(strict_types=1);

namespace Cms\Database;

use PDOStatement;

/**
 * A prepared statement that tells `Writes` when it wrote. PDO builds these
 * itself (`PDO::ATTR_STATEMENT_CLASS`), which is why the constructor is
 * protected; `execute()` is PDO's, with one note after it succeeds.
 */
final class Statement extends PDOStatement
{
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        $ok = parent::execute($params);
        if ($ok) {
            Writes::note($this->queryString, $this->rowCount());
        }

        return $ok;
    }
}
