<?php

declare(strict_types=1);

namespace Cms\Database;

use PDO;
use RuntimeException;

/**
 * Applies the `.sql` files in `core/Database/migrations/` that the `migrations`
 * table has not recorded. One runner for both callers: the front controller,
 * which runs it on every request so an install applies itself, and
 * `admin/bin/migrate.php`, which an update runs from the command line.
 *
 * ── AND THE SITE'S OWN (`admin/site/migrations/`) ───────────────────────────
 * A site that adds a table or a column of its own keeps the file in its own
 * directory, which no package ships and no update touches. They run after
 * The CMS's, under the same lock, recorded in the same table by file name — so
 * a file moved there from `core/Database/migrations/` (a site's own
 * `local_*.sql`) is already applied and never runs twice, and it is not an
 * orphan. A site file with the name of one of the CMS's is refused: the record
 * would say one of the two had run when only the other had.
 *
 * ── WHY IT TAKES A LOCK ─────────────────────────────────────────────────────
 * Two runners used to be able to apply the same file at once: the first
 * requests after a deploy, or an update's CLI run racing a visitor. The second
 * one fails on "table already exists" halfway through a file whose first half
 * the other one already applied — and MySQL cannot roll DDL back. So applying
 * happens under a named lock, and the applied set is read again once the lock
 * is held. The cheap "anything to do?" check stays outside it, so a page view
 * on an up-to-date install still pays one indexed query and one readdir.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $dir,
        private readonly ?string $siteDir = null,
    ) {
        // This class executes every `.sql` file it finds, so the path decides
        // what runs against the database. Handed an empty string, the old
        // runner globbed `/*.sql` and executed a 2MB dump of an unrelated site.
        foreach (array_filter([$dir, $siteDir], 'is_string') as $path) {
            if (!is_dir($path)) {
                throw new RuntimeException("Migration directory '{$path}' does not exist. Refusing to run.");
            }
        }
    }

    /**
     * The CMS's migrations and, when it has one, the site's directory of them
     * (`admin/site/migrations/`). The site's goes in only if it exists.
     */
    public static function forInstall(PDO $pdo, string $admin): self
    {
        $site = rtrim($admin, '/') . '/site/migrations';

        return new self($pdo, rtrim($admin, '/') . '/core/Database/migrations', is_dir($site) ? $site : null);
    }

    /** @return array<string, string> id => absolute path, in the order they run: the CMS's, then the site's. */
    public function files(): array
    {
        $byId = [];
        foreach (array_filter([$this->dir, $this->siteDir], 'is_string') as $i => $dir) {
            $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $path) {
                $id = basename($path);
                if ($i > 0 && isset($byId[$id])) {
                    throw new RuntimeException("The site migration '{$id}' has the name of one of the CMS's own. Rename it.");
                }
                $byId[$id] = $path;
            }
        }

        return $byId;
    }

    /** @return array<string, string> id => applied_at. Empty before the first run. */
    public function applied(): array
    {
        try {
            $rows = $this->pdo->query('SELECT id, applied_at FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable) {
            return [];
        }

        return array_map('strval', $rows);
    }

    /** @return list<string> Files not yet recorded, in the order they would run. */
    public function pending(): array
    {
        return array_values(array_diff(array_keys($this->files()), array_keys($this->applied())));
    }

    /**
     * Recorded as applied, with no file on disk. Nothing in a release removes a
     * migration, so this means the database belongs to a different release than
     * the code — a restore that went to the wrong place, or code rolled back
     * without its database.
     *
     * @return list<string>
     */
    public function orphans(): array
    {
        return array_values(array_diff(array_keys($this->applied()), array_keys($this->files())));
    }

    /**
     * Is every migration file already recorded?
     *
     * `COUNT(*)` and `MAX(id)` together: ids are the filenames, which sort, so
     * when everything is applied the highest id is the last file. Any failure
     * means "no" — on a fresh install the table does not exist yet.
     */
    public function upToDate(): bool
    {
        $files = array_keys($this->files());
        if ($files === []) {
            return true;
        }
        try {
            $row = $this->pdo->query('SELECT COUNT(*) AS n, MAX(id) AS newest FROM migrations')->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return false;
        }
        // The highest id of the lot, as MAX() sorts — not the last to run: a
        // site's file may sort before the CMS's last and still run after it.
        // (Collations compare as the bytes do for these plain file names.)
        sort($files, SORT_STRING);

        return $row !== false
            && (int) $row['n'] === count($files)
            && (string) $row['newest'] === $files[count($files) - 1];
    }

    /**
     * Apply everything pending, in order, under the lock.
     *
     * @param callable(string $id): void|null $before called before each file runs
     * @return list<string> the ids applied by THIS call
     */
    public function run(int $lockTimeout = 10, ?callable $before = null): array
    {
        if ($this->upToDate() || $this->pending() === []) {
            return [];
        }

        $lock = 'cms_migrate_' . substr(sha1((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 16);
        $got  = $this->pdo->query('SELECT GET_LOCK(' . $this->pdo->quote($lock) . ', ' . max(0, $lockTimeout) . ')')->fetchColumn();
        if ((string) $got !== '1') {
            throw new MigrationsBusy(
                "Another process is applying migrations to this database (waited {$lockTimeout}s). Try again when it finishes."
            );
        }

        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS migrations (
                    id         VARCHAR(255) NOT NULL PRIMARY KEY,
                    applied_at DATETIME     NOT NULL
                )'
            );

            // Read again now the lock is held: whoever held it before may have
            // applied some or all of what looked pending a moment ago.
            $ran = [];
            foreach ($this->pending() as $id) {
                $sql = (string) file_get_contents($this->files()[$id]);
                if (trim($sql) === '') {
                    continue;
                }
                if ($before !== null) {
                    $before($id);
                }
                $this->apply($id, $sql);
                $ran[] = $id;
            }

            return $ran;
        } finally {
            $this->pdo->query('SELECT RELEASE_LOCK(' . $this->pdo->quote($lock) . ')');
        }
    }

    private function apply(string $id, string $sql): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec($sql);
            // DDL makes MySQL commit implicitly, ending the transaction. Open
            // another so the record of the migration is written atomically.
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }
            $stmt = $this->pdo->prepare('INSERT INTO migrations (id, applied_at) VALUES (:id, :applied_at)');
            $stmt->execute([':id' => $id, ':applied_at' => date('Y-m-d H:i:s')]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Migration '{$id}' failed: " . $e->getMessage());
        }
    }
}
