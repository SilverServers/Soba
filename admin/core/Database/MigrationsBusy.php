<?php

declare(strict_types=1);

namespace Cms\Database;

/**
 * Another process holds the migration lock for longer than this one waits
 * (`Migrator::run()`): not a fault, a moment. A visit answers it with a 503
 * and Retry-After (`www/index.php`), and `admin/bin/migrate.php` prints its
 * message, which says to try again.
 */
final class MigrationsBusy extends \RuntimeException
{
}
