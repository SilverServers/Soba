<?php

declare(strict_types=1);

namespace Cms\Database;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    /**
     * Store configuration for lazy connection — and pin PHP's clock to UTC.
     *
     * ── BOTH SIDES OF ONE CONTRACT ──────────────────────────────────────────
     * The connection below sets MySQL's session to `+00:00`, so CURRENT_TIMESTAMP
     * and UTC_TIMESTAMP() write UTC. PHP writes the same columns with `date()`
     * — `published_at`, `last_accessed_at` — and `date()` uses PHP's default
     * zone, which is whatever the host's php.ini says. On a host set to
     * America/Vancouver, half the rows would be seven hours off the other half,
     * and a scheduled entry would go live seven hours early.
     *
     * So the zone is set HERE, where the MySQL half is set, and every process
     * that talks to the database — a web request, a migration script — stores
     * UTC. The owner's zone (Settings → General) is applied on
     * the way OUT, by `Helpers\Dates`, and on the way IN from a date control.
     */
    public static function init(array $config): void
    {
        date_default_timezone_set('UTC');
        self::$config = $config;
    }

    /** Has init() been given a configuration this request? */
    public static function initialized(): bool
    {
        return self::$config !== [];
    }

    /**
     * Return the shared PDO connection, creating it on first call.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = self::$config;

        if (empty($config)) {
            throw new RuntimeException('Database::init() must be called before Database::getInstance().');
        }

        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $config['db_host'],
                $config['db_name'],
                $config['db_charset'] ?? 'utf8mb4'
            );
            // `Connection` and `Statement` are PDO's own, plus a note of every
            // table written — the audit log's evidence (`Security\Audit`).
            $pdo = new Connection($dsn, $config['db_user'], $config['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STATEMENT_CLASS    => [Statement::class, []],
            ]);
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }

        self::$instance = $pdo;
        return $pdo;
    }

    /**
     * Apply any migration files not yet recorded in the migrations table.
     *
     * Migration files are plain .sql files named with a numeric prefix so they
     * sort and run in order (e.g. 001_create_users.sql, 002_add_posts.sql).
     *
     * **This runs on every single request**, because the front controller calls it
     * during bootstrap — that is what makes an install self-applying, and it should
     * stay that way. What it must not do is charge every page view for it, so
     * `Migrator::upToDate()` answers "anything to do?" with one indexed query and
     * one readdir, and the database stays the source of truth — a dropped database
     * still re-migrates itself, which a filesystem marker file would have broken.
     *
     * The work itself lives in `Migrator`, which `admin/bin/migrate.php` shares, so
     * a command-line run and a page view take the same lock and cannot apply the
     * same file twice.
     */
    /** The CMS's migrations, then the site's own when `$siteDir` is a directory (`Migrator`). */
    public static function runMigrations(string $migrationDir, ?string $siteDir = null): void
    {
        (new Migrator(self::getInstance(), $migrationDir, $siteDir !== null && is_dir($siteDir) ? $siteDir : null))->run();
    }
}
