<?php

declare(strict_types=1);

namespace Cms\Update;

use PDO;
use RuntimeException;

/**
 * Replaces an installed CMS with a newer release without losing anything
 * the site owns. Driven by `admin/bin/update.php`; see that file for usage.
 *
 * ── THE SHAPE OF AN UPDATE ──────────────────────────────────────────────────
 * The update tarball unpacks INSIDE the site, to
 * `admin/updates/incoming/<version>/`, so extracting it cannot overwrite a
 * thing, and every later move is a rename on one filesystem: instant, and
 * exactly reversible. Then, with every step written to a journal before it is
 * taken:
 *
 *    1. maintenance on          visitors get a 503 from here on
 *    2. the front controller    swapped first, so the 503 is the NEW one's
 *    3. settle, probe           the web server must be serving it (OPcache)
 *    4. database backup         dump + fingerprint, while nothing can write
 *    5. every other CMS path, swapped: the old one moves into the backup
 *    6. migrations              the new release's own migrate.php, a child
 *    7. verify                  every file matches the new manifest; the new
 *                               release answers its sign-in page, / and a 404 with
 *                               nothing in its error log — from the CLI, and
 *                               through the real web server when it can
 *    8. maintenance off
 *
 * Any failure from step 1 on is rolled back from the journal: the database is
 * restored from the dump and checked against its fingerprint, every move is
 * reversed, and the CMS files are checked against what was there before.
 *
 * ── WHAT IT WILL NOT DO ─────────────────────────────────────────────────────
 * Touch anything `ownership.php` does not give to the CMS. Overwrite a change
 * the site made to one of the CMS's files — it stops, names every one, and
 * copies them out so they can be brought into the CMS or moved to the site's
 * theme (`admin/site/`); `--discard-local-changes` goes ahead with them kept
 * in the backup. Go backwards: a downgrade is a rollback.
 */
final class Updater
{
    public const EXIT_OK            = 0;
    public const EXIT_FAILED        = 1;   // rolled back
    public const EXIT_BLOCKED       = 2;   // refused before changing anything
    public const EXIT_LOCAL_CHANGES = 3;   // refused: the site changed the CMS's files
    public const EXIT_ROLLBACK_BAD  = 4;   // rollback itself failed: needs a person

    private const SMOKE_404 = '/__cms-update-smoke-404';

    /** What the two smoke tests call themselves (`smoke-request.php`, `http()`). */
    private const PROBES = ['CMS-update-smoke', 'CMS-update-probe'];

    /**
     * Whether this request is the updater's own smoke test, from the command
     * line or the web server: not a visitor, so nothing a visit does on the
     * side (a not-found counted, a backup begun) is done for it, and a
     * reinstall leaves the database as it found it.
     *
     * @param array<string, mixed> $server
     */
    public static function isProbe(array $server): bool
    {
        return in_array((string) ($server['HTTP_USER_AGENT'] ?? ''), self::PROBES, true);
    }

    /** @var callable(string): void */
    private $say;

    /** @var array<string, mixed> */
    private array $journal = [];

    private string $journalFile = '';

    /**
     * @param string      $site  the site's project root (holds admin/ and www/)
     * @param string|null $stage the unpacked release (holds admin/ and www/), null when
     *                           running from an installed release (status, rollback)
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly string $site,
        private readonly ?string $stage,
        private readonly array $options,
        callable $say
    ) {
        $this->say = $say;
        $this->ownershipData = require __DIR__ . '/ownership.php';
    }

    /** `ownership.php` as this release wrote it, read once — see Ownership::fromArray(). */
    private array $ownershipData;

    /**
     * The list as THIS SITE reads it: with its own name for a named front
     * controller, and an admin folder still at an old address until this moves
     * it back (`Ownership::installNames()`, from its config.php and its
     * folders), so every path below that touches the site is where the site
     * keeps it — asked again after that move.
     */
    private function ownership(string $layout): Ownership
    {
        $routerFront = $layout === 'router' ? (string) ($this->ownershipData['layouts']['router']['@front'] ?? '') : null;

        return Ownership::fromArray($layout, $this->ownershipData, Ownership::installNames($this->config(), $this->site, $routerFront));
    }

    /** The list as a PACKAGE reads it: the canonical names it was built with. */
    private function stageOwnership(string $layout): Ownership
    {
        return Ownership::fromArray($layout, $this->ownershipData);
    }

    /**
     * Load every class an update needs BEFORE the first move. Once the staged
     * release has been moved into the site, the autoloader this process
     * started with points at paths that no longer exist.
     */
    public static function preload(): void
    {
        foreach ([Ownership::class, Manifest::class, DbSnapshot::class, \Cms\Database\Migrator::class, \JsonException::class,
                  \Cms\Helpers\Slug::class, \Cms\Compat\OldNames::class] as $class) {
            class_exists($class);
        }
    }

    // ------------------------------------------------------------------
    // Commands
    // ------------------------------------------------------------------

    public function check(): int
    {
        $plan = $this->plan();
        $this->report($plan);

        if ($plan['blockers'] !== []) {
            return self::EXIT_BLOCKED;
        }
        if ($this->localChanges($plan) !== [] && empty($this->options['discard-local-changes'])) {
            return self::EXIT_LOCAL_CHANGES;
        }
        $this->out("\nReady: `apply` would update {$plan['from']} → {$plan['to']}.");

        return self::EXIT_OK;
    }

    public function apply(): int
    {
        self::preload();
        $plan = $this->plan();
        $this->report($plan);

        if ($plan['blockers'] !== []) {
            $this->out("\nNOT UPDATED. Nothing was changed.");
            return self::EXIT_BLOCKED;
        }
        $local = $this->localChanges($plan);
        if ($local !== [] && empty($this->options['discard-local-changes'])) {
            $bundle = $this->bundleLocalChanges($plan, $this->updatesDir() . '/local-changes-' . gmdate('Ymd\THis\Z'));
            $this->out("\nNOT UPDATED. This site has changed " . count($local) . " of the CMS's files (listed above).");
            $this->out("Copies of them, and a list, are in {$this->rel($bundle)}.");
            $this->out('Each change belongs in the CMS itself (send it back to be built in) or in the site\'s own');
            $this->out('theme: admin/site/templates/ for a template, www/site/theme.css or www/site/admin.css for');
            $this->out('styles. Once they are moved, run `apply` again. `--discard-local-changes` updates anyway,');
            $this->out('keeping the changed files in the backup. Nothing was changed.');
            return self::EXIT_LOCAL_CHANGES;
        }

        $lock = $this->lock();
        try {
            return $this->execute($plan);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function status(): int
    {
        $manifest = Manifest::read($this->site . '/' . Manifest::FILE);
        $version  = is_file($this->site . '/admin/VERSION') ? trim((string) file_get_contents($this->site . '/admin/VERSION')) : null;

        $this->out('Site:     ' . $this->site);
        // THE SITE'S OWN FILES IN admin/config/ (`Site\ConfigFile`), said
        // before anything here reads them: a config.php that does not compile
        // is where this stops.
        foreach (\Cms\Site\ConfigFile::problemsIn($this->site . '/admin/config') as $file => $problem) {
            $this->out((isset($problem['fatal']) ? 'BROKEN:   ' : 'Note:     ') . "admin/config/{$file}: " . ($problem['fatal'] ?? $problem['output']));
            if ($file === 'config.php' && isset($problem['fatal'])) {
                return self::EXIT_BLOCKED;
            }
        }
        if ($manifest === null) {
            $this->out('CMS:      ' . ($version ?? 'no admin/VERSION') . ' — no admin/manifest.json (installed before updates existed)');
        } else {
            $own  = $this->ownership($manifest->layout());
            $diff = $manifest->diffTree($this->site, $own);
            $this->out("CMS:      {$manifest->version()} ({$manifest->layout()} layout, built {$manifest->get('built')}, commit {$manifest->get('commit')})");
            $changed = count($diff['modified']) + count($diff['missing']) + count($diff['unknown']);
            $this->out('Files:    ' . ($changed === 0
                ? "all {$diff['same']} of the CMS's files are as shipped"
                : "{$changed} differ from what was shipped (run `check` from an update package for the list)"));
        }
        if (is_file($this->updatesDir() . '/.maintenance')) {
            $this->out('MAINTENANCE IS ON: ' . trim((string) file_get_contents($this->updatesDir() . '/.maintenance')));
        }
        foreach ($this->journals() as $file) {
            $journal = json_decode((string) file_get_contents($file), true);
            if (is_array($journal) && ($journal['state'] ?? '') === 'running') {
                $this->out("UNFINISHED UPDATE {$journal['id']} ({$journal['from']} → {$journal['to']}). Run `rollback` to undo it.");
            }
        }
        $history = $this->updatesDir() . '/history.log';
        if (is_file($history)) {
            $lines = array_slice(file($history, FILE_IGNORE_NEW_LINES) ?: [], -5);
            $this->out("Recent:\n  " . implode("\n  ", $lines));
        }

        return self::EXIT_OK;
    }

    /**
     * Undo the most recent update (or `$id`): database back to its backup, every
     * move reversed. An update that finished needs `--yes`, because whatever
     * was written to the database since then goes too.
     */
    public function rollback(?string $id): int
    {
        self::preload();
        $file = $id !== null ? $this->updatesDir() . '/' . $id . '/journal.json' : ($this->journals()[0] ?? null);
        if ($file === null || !is_file($file)) {
            $this->out('No update to roll back' . ($id !== null ? " called {$id}" : '') . '.');
            return self::EXIT_BLOCKED;
        }
        $journal = json_decode((string) file_get_contents($file), true);
        if (!is_array($journal)) {
            $this->out("{$file} is not readable.");
            return self::EXIT_BLOCKED;
        }
        $this->journal     = $journal;
        $this->journalFile = $file;

        $state = (string) ($journal['state'] ?? '');
        if (in_array($state, ['rolled_back', 'failed_before_changes'], true)) {
            $this->out("Update {$journal['id']} is already {$state}.");
            return self::EXIT_OK;
        }
        if ($state === 'done') {
            // Only undo what the update left: if the CMS's files have changed
            // since, reversing the moves would take the new changes with them.
            $manifest = Manifest::read($this->site . '/' . Manifest::FILE);
            $own      = $this->ownership((string) $journal['layout']);
            $diff     = $manifest?->diffTree($this->site, $own);
            if ($manifest === null || $manifest->version() !== $journal['to'] || $diff === null
                || $diff['modified'] !== [] || $diff['missing'] !== [] || $diff['unknown'] !== []) {
                $this->out("The CMS's files have changed since update {$journal['id']} finished, so it cannot be reversed safely.");
                return self::EXIT_BLOCKED;
            }
            if (empty($this->options['yes'])) {
                $this->out("Update {$journal['id']} finished at {$journal['finished']}. Rolling it back puts the database back to");
                $this->out("{$journal['db_taken']}: ANYTHING SAVED SINCE IS LOST. Run again with --yes to do it.");
                return self::EXIT_BLOCKED;
            }
        }

        $lock = $this->lock();
        try {
            $this->out("Rolling back {$journal['id']} ({$journal['from']} → {$journal['to']}) …");
            if ($state === 'done') {
                $this->maintenanceOn();
            }
            $ok = $this->undo();
            $this->history($ok ? 'rolled back' : 'ROLLBACK FAILED');
            return $ok ? self::EXIT_OK : self::EXIT_ROLLBACK_BAD;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // ------------------------------------------------------------------
    // The plan: everything decided before anything is touched
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function plan(): array
    {
        $plan = ['blockers' => [], 'warnings' => [], 'notes' => []];
        $block = static function (string $why) use (&$plan): void { $plan['blockers'][] = $why; };

        if ($this->stage === null) {
            $block('This is an installed release, not an update package. Run the update.php inside admin/updates/incoming/<version>/.');
            return $plan + ['from' => '?', 'to' => '?'];
        }
        if (!is_file($this->site . '/admin/core/Database/Database.php')) {
            $block("{$this->site} does not look like a CMS site (no admin/core/Database/Database.php).");
            return $plan + ['from' => '?', 'to' => '?'];
        }
        // Every move is a rename, instant and exactly reversible only on one
        // filesystem — so the package must be unpacked inside the site it
        // updates, where the tarball puts it.
        if (!str_starts_with($this->stage . '/', $this->site . '/admin/updates/incoming/')) {
            $block("The package at {$this->stage} is not inside this site. Unpack it in the site's root "
                . '(it lands in admin/updates/incoming/<version>/) and run the update.php there.');
            return $plan + ['from' => '?', 'to' => '?'];
        }

        // THE SITE'S OWN FILES IN admin/config/ (`Site\ConfigFile`), before
        // anything here reads them. One that does not compile stops every page,
        // and would stop this run with a fatal error where config.php is read,
        // so it is named and refused; one that only prints a blank line is
        // noted. A config.php that does not compile ends the plan here.
        foreach (\Cms\Site\ConfigFile::problemsIn($this->site . '/admin/config') as $file => $problem) {
            if (isset($problem['fatal'])) {
                $block("admin/config/{$file}: {$problem['fatal']}");
                if ($file === 'config.php') {
                    return $plan + ['from' => '?', 'to' => '?'];
                }
            } else {
                $plan['warnings'][] = "admin/config/{$file}: {$problem['output']}";
            }
        }

        // The package: its manifest, and every staged file matching it.
        $package = Manifest::read($this->stage . '/' . Manifest::FILE);
        if ($package === null) {
            $block('The package has no admin/manifest.json; it was not built by tools/package.sh.');
            return $plan + ['from' => '?', 'to' => '?'];
        }
        $plan['package'] = $package;
        $plan['to']      = $package->version();
        $ownership       = $this->ownership($package->layout());
        $plan['ownership'] = $ownership;

        $stageOwn = $this->stageOwnership($package->layout());
        $staged   = $package->diffTree($this->stage, $stageOwn);
        if ($staged['modified'] !== [] || $staged['missing'] !== [] || $staged['unknown'] !== []) {
            $block('The unpacked package does not match its own manifest (' . count($staged['modified']) . ' changed, '
                . count($staged['missing']) . ' missing, ' . count($staged['unknown']) . ' extra): unpack it again.');
        }
        foreach ($this->allFiles($this->stage . '/admin') as $rel) {
            $this->checkStagedPath('admin/' . $rel, $stageOwn, $block);
        }
        foreach ($this->allFiles($this->stage . '/www') as $rel) {
            $this->checkStagedPath('www/' . $rel, $stageOwn, $block);
        }

        // The installed release: its manifest, or failing that, a baseline.
        [$installed, $source] = $this->identify($package, $plan);
        if ($installed === null) {
            $block('Cannot tell which CMS release this site runs: it has no admin/manifest.json and matches no baseline in this package.');
            return $plan + ['from' => '?'];
        }
        $plan['installed'] = $installed;
        $plan['source']    = $source;
        $plan['from']      = $installed->version();

        if ($installed->layout() !== $package->layout()) {
            $block("This site uses the {$installed->layout()} layout and the package was built for {$package->layout()}. Build the package for this site's profile.");
        }
        if ($source === 'manifest') {
            $cmp = version_compare($package->version(), $installed->version());
            if ($cmp < 0) {
                $block("The package ({$package->version()}) is OLDER than the site ({$installed->version()}). Going back is `rollback`, not an update.");
            } elseif ($cmp === 0 && empty($this->options['reinstall'])) {
                $block("The site already runs {$package->version()}. `--reinstall` puts a fresh copy of it back.");
            }
        }

        $plan['diff'] = $installed->diffTree($this->site, $this->ownership($installed->layout()));

        // What moves: every CMS entry, the front controller first.
        $entries = $ownership->coreEntries();
        $front   = $ownership->front();
        usort($entries, static fn (string $a, string $b): int => ($b === $front) <=> ($a === $front));
        $entries = array_values(array_filter($entries, static fn (string $e): bool => $e !== Manifest::FILE));
        $entries[] = Manifest::FILE;
        $plan['entries'] = array_values(array_filter(
            $entries,
            fn (string $e): bool => file_exists($this->site . '/' . $ownership->onSite($e)) || file_exists($this->stage . '/' . $e)
        ));

        // AN ADMIN STILL AT ITS OLD ADDRESS (`Ownership::oldAdminFolder()`):
        // the admin lives at /admin, and hiding it is Security → Login
        // protection. Its folder goes back to www/admin/, and — unless the
        // sign-in page has been moved already — the sign-in page moves to the
        // old address, so whoever signs in there still can.
        $plan['admin_from'] = Ownership::oldAdminFolder($this->config(), $this->site);

        // MODULE SWITCHES (`Helpers\Modules::notes()`): one written as a word
        // that switches its module off from this release, and one that is
        // neither yes nor no, which leaves it on.
        $modules = $this->config()['modules'] ?? [];
        foreach (\Cms\Helpers\Modules::notes(is_array($modules) ? $modules : [], true) as $note) {
            $plan['warnings'][] = 'admin/config/config.php: ' . $note;
        }

        // The site's own files that still use the CMS's old names: they keep
        // working through 0.7.x, and 0.8.0 will need them changed.
        $plan['old_names'] = \Cms\Compat\OldNames::inSiteFiles($this->site, $ownership);

        $old = $installed->files();
        $new = $package->files();
        $plan['added']   = array_values(array_diff(array_keys($new), array_keys($old)));
        $plan['removed'] = array_values(array_filter(
            array_diff(array_keys($old), array_keys($new)),
            fn (string $f): bool => is_file($this->site . '/' . $ownership->onSite($f))
        ));
        $plan['changed'] = array_values(array_filter(
            array_intersect(array_keys($new), array_keys($old)),
            static fn (string $f): bool => $old[$f] !== $new[$f]
        ));

        // The database.
        try {
            $pdo = $this->pdo();
            $applied = $pdo->query('SELECT id FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
            $plan['pending'] = array_values(array_diff($package->migrations(), $applied));
            // The site's own (`admin/site/migrations/`, run by the same Migrator)
            // are recorded in the same table and are not a newer release's.
            $siteMigrations = array_map('basename', glob($this->site . '/admin/site/migrations/*.sql') ?: []);
            $orphans = array_values(array_diff($applied, $package->migrations(), $siteMigrations));
            if ($orphans !== []) {
                $block('The database has migrations this release does not (' . implode(', ', $orphans) . '): it belongs to a newer or different release.');
            }
            $plan['db_bytes'] = (int) $pdo->query(
                'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            )->fetchColumn();
            DbSnapshot::fingerprint($pdo); // refuses a database it could not back up
            $plan['sign_in'] = $this->signInPath($plan);
        } catch (\Throwable $e) {
            $block('Database: ' . $e->getMessage());
        }

        // Room, permissions, and nobody else mid-update.
        if (is_file($this->updatesDir() . '/.maintenance')) {
            $block('Maintenance is already on (admin/updates/.maintenance): another update is running or one failed. Run `status`.');
        }
        foreach ($this->journals() as $file) {
            $journal = json_decode((string) file_get_contents($file), true);
            if (is_array($journal) && ($journal['state'] ?? '') === 'running') {
                $block("Update {$journal['id']} never finished. Run `rollback` first.");
            }
        }
        foreach ($plan['entries'] as $entry) {
            // An update swaps the CMS's paths by rename. A path the site turned
            // into a symlink would be swapped for a real directory, silently
            // changing how the site is put together — so it is named and
            // refused instead.
            $onSite = $ownership->onSite($entry);
            if (is_link($this->site . '/' . $onSite)) {
                $block("{$onSite} is a symlink on this site. Make it the real directory (or file) the CMS ships, then update.");
            }
            $parent = dirname($this->site . '/' . $onSite);
            while (!is_dir($parent) && $parent !== dirname($parent)) {
                $parent = dirname($parent);
            }
            if (!is_writable($parent)) {
                $block("Cannot write {$parent} (needed for {$entry}).");
            }
        }
        $free = @disk_free_space($this->site);
        $need = 3 * (int) ($plan['db_bytes'] ?? 0) + 64 * 1024 * 1024;
        if ($free !== false && $free < $need) {
            $block('Not enough disk space: ' . round($free / 1048576) . ' MB free, about ' . round($need / 1048576) . ' MB needed.');
        }

        // Things worth saying that block nothing.
        foreach (['.git', 'tools', 'design-language', 'hooks'] as $name) {
            if (file_exists($this->site . '/' . $name)) {
                $plan['notes'][] = $name;
            }
        }

        return $plan;
    }

    /**
     * @return array{0: ?Manifest, 1: string}
     */
    private function identify(Manifest $package, array &$plan): array
    {
        // Written before 0.7.0, a manifest or baseline may name the router
        // layout's front controller as it was then (Compat\OldNames::manifest()).
        $now = fn (Manifest $m): Manifest => \Cms\Compat\OldNames::manifest($m, (string) ($this->ownershipData['layouts'][$m->layout()]['@front'] ?? ''));
        $manifest = Manifest::read($this->site . '/' . Manifest::FILE);
        if ($manifest !== null) {
            return [$now($manifest), 'manifest'];
        }

        $best = null;
        $bestScore = 0.0;
        foreach (glob($this->stage . '/baselines/*.json') ?: [] as $file) {
            $baseline = Manifest::read($file);
            if ($baseline === null) {
                continue;
            }
            $baseline = $now($baseline);
            $diff  = $baseline->diffTree($this->site, $this->ownership($baseline->layout()));
            $score = $diff['same'] / max(1, count($baseline->files()));
            $plan['warnings'][] = sprintf('baseline %s matches %.1f%% of its files', $baseline->version(), 100 * $score);
            if ($score > $bestScore) {
                $best      = $baseline;
                $bestScore = $score;
            }
        }
        if ($best === null || $bestScore < 0.6) {
            return [null, 'none'];
        }

        return [$best, 'baseline'];
    }

    private function checkStagedPath(string $rel, Ownership $ownership, callable $block): void
    {
        if ($rel === Manifest::FILE) {
            return;
        }
        $class = $ownership->classify($rel);
        if ($class !== 'core') {
            $block("The package carries {$rel}, which is the site's ({$class}). An update package must carry only the CMS's files.");
        }
    }

    /** @return list<string> modified, missing and unknown CMS files, as "status path" */
    private function localChanges(array $plan): array
    {
        if (!isset($plan['diff'])) {
            return [];
        }
        $lines = [];
        foreach (['modified' => 'changed', 'missing' => 'deleted', 'unknown' => 'added by the site'] as $key => $label) {
            foreach ($plan['diff'][$key] as $path) {
                // A change to a file this release no longer calls the CMS's is
                // not in the way: the update does not touch it.
                if ($plan['ownership']->classify($path) !== 'core') {
                    continue;
                }
                $lines[] = "{$label}: {$path}";
            }
        }

        return $lines;
    }

    /** @param array<string, mixed> $plan */
    private function report(array $plan): void
    {
        $this->out('CMS update');
        $this->out('  site     ' . $this->site);
        if (isset($plan['installed'])) {
            $this->out("  from     {$plan['from']} (" . ($plan['source'] === 'manifest' ? 'admin/manifest.json' : 'identified by baseline fingerprint') . ", {$plan['installed']->layout()} layout)");
        }
        if (isset($plan['package'])) {
            $this->out("  to       {$plan['to']} (commit {$plan['package']->get('commit')}, built {$plan['package']->get('built')})");
        }
        foreach ($plan['warnings'] as $warning) {
            $this->out('  note     ' . $warning);
        }
        if (isset($plan['entries'])) {
            $this->out('  files    ' . count($plan['changed']) . ' changed, ' . count($plan['added']) . ' new, ' . count($plan['removed']) . ' no longer shipped (they go to the backup)');
            foreach (array_slice($plan['removed'], 0, 20) as $f) {
                $this->out("             no longer shipped: {$f}");
            }
            $this->out('  swaps    ' . implode(', ', $plan['entries']));
        }
        if (isset($plan['sign_in'])) {
            $this->out('  sign-in  ' . $plan['sign_in'] . ' (where the smoke test looks for the sign-in form)');
        }
        if (($plan['admin_from'] ?? null) !== null) {
            $this->out("  admin    www/{$plan['admin_from']}/ goes back to www/admin/: the admin lives at /admin, and hiding it is Security → Login protection");
            $this->out('           ' . ($plan['admin_moves_sign_in'] ?? false
                ? "the sign-in page moves to /{$plan['admin_from']} there, so it is still where it was signed into"
                : 'the sign-in page stays where Login protection has it'));
            $this->out("           config.php's admin_url_slug is no longer read: remove that line afterwards");
        }
        if (isset($plan['pending'])) {
            $this->out('  database ' . count($plan['pending']) . ' migration(s) to apply' . ($plan['pending'] === [] ? '' : ': ' . implode(', ', $plan['pending'])));
        }
        $local = $this->localChanges($plan);
        if ($local !== []) {
            $this->out("\n  LOCAL CHANGES to the CMS's own files (" . count($local) . '):');
            foreach ($local as $line) {
                $this->out('    ' . $line);
            }
        }
        if (($plan['old_names'] ?? []) !== []) {
            $this->out("\n  OLD NAMES in " . count($plan['old_names']) . " of the site's own files (Imprint\\…, imprint-*, ImprintMedia).");
            $this->out('  They keep working through 0.7.x. Change them to Cms\\…, cms-*, CmsMedia before 0.8.0:');
            foreach ($plan['old_names'] as $file) {
                $this->out('    ' . $file);
            }
            $this->out("  The CMS writes its old class names beside the new until config.php says 'old_class_names' => false.");
        }
        if ($plan['notes'] !== []) {
            $this->out("\n  Not the CMS's, and not touched: " . implode(', ', $plan['notes']) . '.');
            $this->out('  (Development material from a repository-shaped install is the site\'s to keep or remove.)');
        }
        foreach ($plan['blockers'] as $blocker) {
            $this->out('  BLOCKED  ' . $blocker);
        }
    }

    // ------------------------------------------------------------------
    // Doing it
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $plan */
    private function execute(array $plan): int
    {
        $this->signIn = (string) ($plan['sign_in'] ?? '/admin/login');
        /** @var Manifest $package */
        $package   = $plan['package'];
        /** @var Ownership $ownership */
        $ownership = $plan['ownership'];

        $id   = gmdate('Ymd\THis\Z') . '-' . $plan['from'] . '-to-' . $plan['to'];
        $work = $this->updatesDir() . '/' . $id;
        $this->mkdir($work . '/old');

        // What the CMS's files are right now, to prove a rollback put them back.
        $before = [];
        foreach (array_unique([...$ownership->coreFilesIn($this->site), ...array_keys($plan['installed']->files())]) as $rel) {
            $path = $this->site . '/' . $ownership->onSite($rel);
            $before[$rel] = (is_file($path) || is_link($path)) ? Manifest::hashFile($path) : null;
        }
        $this->writeJson($work . '/files-before.json', $before);

        $this->journalFile = $work . '/journal.json';
        $this->journal = [
            'id'       => $id,
            'from'     => $plan['from'],
            'to'       => $plan['to'],
            'layout'   => $ownership->layout(),
            'source'   => $plan['source'],
            'started'  => gmdate('Y-m-d\TH:i:s\Z'),
            'state'    => 'running',
            'token'    => bin2hex(random_bytes(16)),
            'steps'    => [],
        ];
        $this->saveJournal();
        $this->out("\nUpdating {$plan['from']} → {$plan['to']} (work directory {$this->rel($work)})");

        try {
            if ($this->localChanges($plan) !== []) {
                $this->bundleLocalChanges($plan, $work . '/local-changes');
                $this->out('  local changes copied to ' . $this->rel($work . '/local-changes'));
            }

            $this->maintenanceOn();
            $this->out('  maintenance on');

            $entries = $plan['entries'];
            $front   = array_shift($entries);
            $this->swap($front, $work, $ownership);
            $this->out("  swapped {$front}");

            $this->settleAndProbe();

            $pdo = $this->pdo();
            $this->step(['op' => 'db_backup', 'file' => 'database.sql.gz']);
            $this->writeJson($work . '/database.json', DbSnapshot::fingerprint($pdo));
            $stats = DbSnapshot::dump($pdo, $work . '/database.sql.gz');
            $this->journal['db_taken'] = gmdate('Y-m-d\TH:i:s\Z');
            $this->done();
            $this->out("  database backed up: {$stats['tables']} tables, {$stats['rows']} rows, " . round($stats['bytes'] / 1024) . ' KB');

            if ($plan['admin_from'] !== null) {
                $this->move($this->site . '/www/' . $plan['admin_from'], $this->site . '/www/admin');
                $ownership = $this->ownership($ownership->layout());
                $this->out("  admin folder: www/{$plan['admin_from']}/ → www/admin/");
            }

            foreach ($entries as $entry) {
                $this->swap($entry, $work, $ownership);
            }
            $this->out('  swapped ' . count($entries) . ' more: ' . implode(', ', $entries));

            $this->step(['op' => 'migrate']);
            // --no-first-password: an update never gives out a password, which
            // would be saved in migrate.txt beside this and shown to nobody.
            $migrate = $this->run([PHP_BINARY, '-d', 'log_errors=1', '-d', 'display_errors=0',
                '-d', 'error_log=' . $work . '/migrate-errors.log',
                $this->site . '/admin/bin/migrate.php', 'run', '--no-first-password', '--config=' . $this->site . '/admin/config/config.php']);
            file_put_contents($work . '/migrate.txt', $migrate['out']);
            if ($migrate['code'] !== 0) {
                throw new RuntimeException("Migrations failed:\n" . trim($migrate['out']));
            }
            $this->done();
            $this->out('  migrations: ' . trim((string) preg_replace('/\s+/', ' ', (string) strrchr(trim($migrate['out']), "\n") ?: trim($migrate['out']))));

            // The sign-in page to the admin's old address — written after the
            // database backup, so a rollback takes it back with the rest.
            if (!empty($plan['admin_moves_sign_in'])) {
                $set = $pdo->prepare('INSERT INTO options (option_key, option_value) VALUES (?, ?) '
                    . 'ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)');
                foreach (['security_login_url_enabled' => '1', 'security_login_slug' => $plan['admin_from'], 'security_login_redirect' => ''] as $key => $value) {
                    $set->execute([$key, $value]);
                }
                $this->out("  sign-in page: moved to /{$plan['admin_from']} (Security → Login protection)");
            }

            $diff = $package->diffTree($this->site, $ownership);
            if ($diff['modified'] !== [] || $diff['missing'] !== [] || $diff['unknown'] !== []) {
                throw new RuntimeException('After the swap, the site\'s CMS files do not match the release: '
                    . json_encode($diff, JSON_UNESCAPED_SLASHES));
            }
            $this->out("  files: all {$diff['same']} match the release");

            $this->settle();
            $this->smoke($work, $ownership);
            $this->webSmoke();

            $this->maintenanceOff();
            $this->journal['state']    = 'done';
            $this->journal['finished'] = gmdate('Y-m-d\TH:i:s\Z');
            $this->saveJournal();
            $this->tidyStage();
            $this->history('done');
            $this->out("\nUPDATED to {$plan['to']}. Backup and journal: {$this->rel($work)}");
            $this->out('To undo it: php admin/bin/update.php rollback --yes  (the database goes back to ' . $this->journal['db_taken'] . ')');

            return self::EXIT_OK;
        } catch (\Throwable $e) {
            $this->out("\nFAILED: " . $e->getMessage());
            if (!$this->hasChangedAnything()) {
                $this->maintenanceOff();
                $this->journal['state'] = 'failed_before_changes';
                $this->saveJournal();
                $this->history('failed before changing anything');
                $this->out('Nothing had been changed. Maintenance is off.');
                return self::EXIT_FAILED;
            }
            $this->out('Rolling back …');
            $ok = $this->undo();
            $this->history($ok ? 'failed, rolled back' : 'FAILED, ROLLBACK FAILED');

            return $ok ? self::EXIT_FAILED : self::EXIT_ROLLBACK_BAD;
        }
    }

    /**
     * Move the site's copy of `$entry` into the backup and the staged copy into
     * its place. Two renames on one filesystem, each journalled first. The
     * package has it under its canonical name; the site, and so the backup,
     * under the site's own (`Ownership::onSite()`).
     */
    private function swap(string $entry, string $work, Ownership $ownership): void
    {
        $onSite = $ownership->onSite($entry);
        $live   = $this->site . '/' . $onSite;
        $staged = $this->stage . '/' . $entry;
        $backup = $work . '/old/' . $onSite;

        if (file_exists($live) || is_link($live)) {
            $this->mkdir(dirname($backup));
            $this->move($live, $backup);
        }
        if (file_exists($staged)) {
            $parent = dirname($live);
            if (!is_dir($parent)) {
                $this->step(['op' => 'mkdir', 'path' => $this->rel($parent)]);
                $this->mkdir($parent);
                $this->done();
            }
            $this->move($staged, $live);
        }
    }

    private function move(string $from, string $to): void
    {
        $this->step(['op' => 'move', 'from' => $this->rel($from), 'to' => $this->rel($to)]);
        if (!@rename($from, $to)) {
            throw new RuntimeException("Could not move {$this->rel($from)} to {$this->rel($to)}.");
        }
        $this->lastMove = microtime(true);
        $this->done();
    }

    /** When a file last moved: see settle(). */
    private float $lastMove = 0.0;

    /** Where this site signs in: see signInPath(). */
    private string $signIn = '/admin/login';

    /**
     * Where this site's sign-in page answers, by the NEW release's own rule —
     * `Security\LoginUrl::path()`: `/{slug}` once the owner has moved it, else
     * `/admin/login`. Signed out, a moved site answers /admin/login with its
     * not-found page, so an updater that smoke-tested that address would roll
     * back every update there. Asked in the plan, before anything moves, while
     * this process's autoloader still reaches the staged release.
     */
    private function signInPath(array &$plan): string
    {
        $config = $this->config();
        if (!class_exists(\Cms\Security\LoginUrl::class)) {
            return '/admin/login';
        }
        \Cms\Database\Database::init($config);
        // An admin coming back from an old address (`plan()`) takes the
        // sign-in page there with it, when that can be a sign-in address and
        // the page has not been moved already.
        $plan['admin_moves_sign_in'] = ($plan['admin_from'] ?? null) !== null && !\Cms\Security\LoginUrl::enabled()
            && \Cms\Security\LoginUrl::wellFormed((string) $plan['admin_from']);
        $path = match (true) {
            \Cms\Security\LoginUrl::enabled() => \Cms\Security\LoginUrl::path(),
            $plan['admin_moves_sign_in']          => '/' . $plan['admin_from'],
            default                               => '/admin/login',
        };
        if (preg_match('#^/[a-z0-9][a-z0-9/-]*$#', $path) !== 1) {
            throw new RuntimeException("The release gives a sign-in address the smoke test cannot use: {$path}");
        }

        return $path;
    }

    /**
     * Wait until `--settle` seconds (default 3) have passed since a file last
     * moved. OPcache re-reads a changed file only every `revalidate_freq`
     * seconds (2 by default), so until then the web server can still run the
     * code that was just moved away — and mixed with the code just moved in.
     * Seen for real: right after a rollback, a page was still rendered by the
     * release that had been rolled back.
     */
    private function settle(): void
    {
        $wait = (float) ($this->options['settle'] ?? 3) - (microtime(true) - $this->lastMove);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
    }

    /**
     * Wait for the web server to be running the new front controller, which
     * answers everything with a 503 now. Most hosts' OPcache re-reads a changed
     * file within two seconds; one that never does (validate_timestamps=0)
     * would keep serving the OLD release against the NEW database, so a web
     * server still answering 200 stops the update.
     */
    private function settleAndProbe(): void
    {
        $this->settle();

        $url = $this->probeUrl();
        if ($url === null) {
            $this->journal['web'] = 'not probed';
            $this->out('  web server not probed (--no-probe, or no base_url)');
            return;
        }
        for ($i = 0; $i < 5; $i++) {
            [$status, $body, $location] = $this->http($url . $this->signIn, null);
            // A front that gives every page one address may send the sign-in
            // address to its slashed twin before the sign-in page is moved
            // there (an admin coming back from an old address): that one
            // redirect is followed, as the web smoke follows it.
            if (in_array($status, [301, 308], true) && self::slashOf($location, $url, $this->signIn)) {
                [$status, $body] = $this->http($url . $this->signIn . '/', null);
            }
            if ($status === 503) {
                $this->journal['web'] = 'probed';
                $this->out('  web server serves the new front controller (503 while updating)');
                return;
            }
            if ($status === 200 && str_contains($body, 'type="password"')) {
                sleep(2);
                continue;
            }
            $this->journal['web'] = 'unreachable';
            $this->out("  web server not usable for checks ({$url}{$this->signIn} answered " . ($status ?: 'nothing') . '); relying on the command-line checks');
            return;
        }
        throw new RuntimeException('The web server is still serving the OLD release ten seconds after the swap. Its OPcache is not '
            . 're-reading changed files (opcache.validate_timestamps=0?): restart PHP-FPM, or update with a restart in between.');
    }

    /** The new release answers its sign-in page, / and a not-found page, with nothing in its error log. */
    private function smoke(string $work, Ownership $ownership): void
    {
        $this->step(['op' => 'smoke']);
        $log      = $work . '/smoke-errors.log';
        $sessions = $work . '/smoke-sessions';
        $this->mkdir($sessions);
        @unlink($log);
        $base  = (string) ($this->config()['base_url'] ?? 'http://localhost');
        $front = $this->site . '/' . $ownership->onSite($ownership->front());

        $expect = [
            $this->signIn   => static fn (array $r): bool => $r['status'] === 200 && $r['password'] === true,
            '/'             => static fn (array $r): bool => $r['status'] === 200,
            self::SMOKE_404 => static fn (array $r): bool => $r['status'] === 404,
        ];
        $lines = [];
        foreach ($expect as $path => $ok) {
            $run = $this->run([PHP_BINARY,
                '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_reporting=' . E_ALL, '-d', 'error_log=' . $log,
                '-d', 'session.save_path=' . $sessions, '-d', 'sys_temp_dir=' . $sessions,
                $this->site . '/admin/core/Update/smoke-request.php', $front, $base, $path, (string) $this->journal['token']]);
            $result = json_decode((string) strrchr("\n" . trim($run['out']), "\n"), true);
            if (!is_array($result) || !$ok($result) || !empty($result['fatal'])) {
                throw new RuntimeException("Smoke test {$path} failed: " . trim($run['out']));
            }
            $lines[] = "{$path} {$result['status']}";
        }
        // A line is the release's when it names a file in this site. The rest is
        // the host talking about itself on every PHP start-up — an extension
        // built for another library version, an ini directive — and is not a
        // verdict on the release.
        $ours = [];
        $host = 0;
        foreach (is_file($log) ? (file($log, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (str_contains($line, $this->site . '/')) {
                $ours[] = $line;
            } else {
                $host++;
            }
        }
        if ($ours !== []) {
            throw new RuntimeException("The new release logged errors during the smoke test:\n" . implode("\n", $ours));
        }
        $this->done();
        $this->out('  smoke (command line): ' . implode(', ', $lines) . '; nothing in the error log from this site'
            . ($host > 0 ? " ({$host} line(s) from the host itself, not the site: {$this->rel($log)})" : ''));
    }

    /** The same, through the real web server, before any visitor is let in. */
    private function webSmoke(): void
    {
        if (($this->journal['web'] ?? '') !== 'probed') {
            return;
        }
        $url = (string) $this->probeUrl();
        [$status, $body] = $this->http($url . $this->signIn, (string) $this->journal['token']);
        if ($status !== 200 || !str_contains($body, 'type="password"')) {
            throw new RuntimeException("Through the web server, {$url}{$this->signIn} answered {$status} on the new release.");
        }
        [$status, , $location] = $this->http($url . self::SMOKE_404, (string) $this->journal['token']);
        // ONE ADDRESS PER PAGE. A site that gives every page one address — the
        // trailing-slash setting, or the site's own front doing the same —
        // answers a missing page asked for without its slash with a 301 to the
        // one with it, which then 404s, and without following it such a site's
        // update would roll back here. That one redirect is followed; a
        // redirect anywhere else is still a failure.
        if (in_array($status, [301, 308], true) && self::slashOf($location, $url, self::SMOKE_404)) {
            [$status] = $this->http($url . self::SMOKE_404 . '/', (string) $this->journal['token']);
        }
        if ($status !== 404) {
            throw new RuntimeException("Through the web server, a missing page answered {$status}, not 404.");
        }
        $this->out("  smoke (web server): {$this->signIn} 200, missing page 404");
    }

    /**
     * Reverse the journal. The database first, while the new front controller
     * is still answering 503; then every move, newest first, so the old front
     * controller comes back last. Then prove it: database against its
     * fingerprint, the CMS's files against their hashes from before.
     */
    private function undo(): bool
    {
        $work = $this->updatesDir() . '/' . $this->journal['id'];
        $ok   = true;

        if ($this->stepDone('db_backup') && is_file($work . '/database.sql.gz')) {
            try {
                $pdo    = $this->pdo();
                $before = json_decode((string) file_get_contents($work . '/database.json'), true);
                if (DbSnapshot::compare($before, DbSnapshot::fingerprint($pdo)) !== []) {
                    DbSnapshot::restore($pdo, $work . '/database.sql.gz');
                }
                $diff = DbSnapshot::compare($before, DbSnapshot::fingerprint($pdo));
                if ($diff !== []) {
                    $ok = false;
                    $this->out('  DATABASE NOT RESTORED EXACTLY: ' . implode('; ', $diff));
                } else {
                    $this->out('  database restored and matches its fingerprint (' . count($before) . ' tables)');
                }
            } catch (\Throwable $e) {
                $ok = false;
                $this->out('  DATABASE RESTORE FAILED: ' . $e->getMessage());
            }
        }

        foreach (array_reverse($this->journal['steps']) as $step) {
            try {
                if ($step['op'] === 'move') {
                    $from = $this->site . '/' . $step['from'];
                    $to   = $this->site . '/' . $step['to'];
                    if (file_exists($to) || is_link($to)) {
                        // Something now sits where this came from — the package
                        // unpacked again, say. A directory there makes the move
                        // back fail; a file there would be silently overwritten.
                        // Neither: it is moved aside into this update's folder,
                        // kept, and named.
                        if (file_exists($from) || is_link($from)) {
                            $aside = $work . '/displaced/' . $step['from'];
                            for ($n = 2; file_exists($aside) || is_link($aside); $n++) {
                                $aside = $work . '/displaced-' . $n . '/' . $step['from'];
                            }
                            $this->mkdir(dirname($aside));
                            if (!@rename($from, $aside)) {
                                throw new RuntimeException("{$step['from']} is in the way and could not be moved aside");
                            }
                            $this->out("  moved aside, it was in the way: {$step['from']} → {$this->rel($aside)}");
                        }
                        $this->mkdir(dirname($from));
                        if (!@rename($to, $from)) {
                            throw new RuntimeException("could not move {$step['to']} back to {$step['from']}");
                        }
                        $this->lastMove = microtime(true);
                    }
                } elseif ($step['op'] === 'mkdir') {
                    @rmdir($this->site . '/' . $step['path']);
                }
            } catch (\Throwable $e) {
                $ok = false;
                $this->out('  FILE ROLLBACK FAILED: ' . $e->getMessage());
            }
        }

        // Maintenance stays up until the web server has let go of the release
        // that was just moved away (settle()).
        $this->settle();

        $before = json_decode((string) @file_get_contents($work . '/files-before.json'), true);
        if (is_array($before)) {
            $wrong = [];
            $own = $this->ownership((string) $this->journal['layout']);
            foreach ($before as $rel => $hash) {
                $path = $this->site . '/' . $own->onSite((string) $rel);
                $now  = (is_file($path) || is_link($path)) ? Manifest::hashFile($path) : null;
                if ($now !== $hash) {
                    $wrong[] = $rel;
                }
            }
            foreach ($own->coreFilesIn($this->site) as $rel) {
                if (!array_key_exists($rel, $before)) {
                    $wrong[] = $rel . ' (should not exist)';
                }
            }
            if ($wrong !== []) {
                $ok = false;
                $this->out('  FILES NOT RESTORED: ' . implode(', ', array_slice($wrong, 0, 20)) . (count($wrong) > 20 ? ' …' : ''));
            } else {
                $this->out('  The CMS\'s files are back exactly as they were (' . count($before) . ' checked)');
            }
        }

        $this->maintenanceOff();
        $this->journal['state'] = $ok ? 'rolled_back' : 'rollback_failed';
        $this->journal['rolled_back'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->saveJournal();
        $this->out($ok ? "ROLLED BACK to {$this->journal['from']}. Maintenance is off."
            : "ROLLBACK DID NOT COMPLETE. The site may be broken; maintenance is off so you can see it. Journal: {$this->rel($this->journalFile)}");

        return $ok;
    }

    // ------------------------------------------------------------------
    // Pieces
    // ------------------------------------------------------------------

    private function maintenanceOn(): void
    {
        $this->mkdir($this->updatesDir());
        $this->step(['op' => 'maintenance_on']);
        $this->writeJson($this->updatesDir() . '/.maintenance', [
            'since'  => gmdate('Y-m-d\TH:i:s\Z'),
            'update' => $this->journal['id'] ?? '',
            'token'  => $this->journal['token'] ?? bin2hex(random_bytes(16)),
        ]);
        @chmod($this->updatesDir() . '/.maintenance', 0640);
        $this->done();
    }

    private function maintenanceOff(): void
    {
        @unlink($this->updatesDir() . '/.maintenance');
    }

    private function hasChangedAnything(): bool
    {
        foreach ($this->journal['steps'] ?? [] as $step) {
            if (in_array($step['op'], ['move', 'mkdir', 'db_backup'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $plan */
    private function bundleLocalChanges(array $plan, string $dir): string
    {
        $this->mkdir($dir);
        $list = ["CMS files this site changed, against {$plan['from']} ({$plan['source']}).",
                 'status | path | sha256 on the site | sha256 as shipped', ''];
        $shipped = $plan['installed']->files();
        foreach (['modified' => 'changed', 'missing' => 'deleted', 'unknown' => 'added'] as $key => $label) {
            foreach ($plan['diff'][$key] as $rel) {
                $path = $this->site . '/' . $plan['ownership']->onSite($rel);
                $hash = (is_file($path) || is_link($path)) ? Manifest::hashFile($path) : '-';
                $list[] = "{$label} | {$rel} | {$hash} | " . ($shipped[$rel] ?? '-');
                if ($hash !== '-') {
                    $this->mkdir(dirname($dir . '/files/' . $rel));
                    copy($path, $dir . '/files/' . $rel);
                }
            }
        }
        file_put_contents($dir . '/LIST.txt', implode("\n", $list) . "\n");

        return $dir;
    }

    /** Whatever of the unpacked package is left once its trees have moved in. */
    private function tidyStage(): void
    {
        if ($this->stage === null || !is_dir($this->stage)) {
            return;
        }
        $rest = $this->updatesDir() . '/' . $this->journal['id'] . '/package';
        $this->step(['op' => 'move', 'from' => $this->rel($this->stage), 'to' => $this->rel($rest)]);
        @rename($this->stage, $rest);
        $this->done();
        @rmdir(dirname($this->stage));
    }

    private function history(string $result): void
    {
        $this->mkdir($this->updatesDir());
        $line = sprintf("%s  %s -> %s  %s  %s\n", gmdate('Y-m-d\TH:i:s\Z'), $this->journal['from'] ?? '?', $this->journal['to'] ?? '?', $result, $this->journal['id'] ?? '');
        file_put_contents($this->updatesDir() . '/history.log', $line, FILE_APPEND);
    }

    /** @param array<string, mixed> $step */
    private function step(array $step): void
    {
        $this->journal['steps'][] = $step + ['done' => false];
        $this->saveJournal();
    }

    private function done(): void
    {
        $last = array_key_last($this->journal['steps']);
        $this->journal['steps'][$last]['done'] = true;
        $this->saveJournal();
    }

    private function stepDone(string $op): bool
    {
        foreach ($this->journal['steps'] ?? [] as $step) {
            if ($step['op'] === $op && $step['done']) {
                return true;
            }
        }

        return false;
    }

    private function saveJournal(): void
    {
        if ($this->journalFile !== '') {
            $this->writeJson($this->journalFile, $this->journal);
        }
    }

    /** Atomic: written beside the target, then renamed over it. */
    private function writeJson(string $file, mixed $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $tmp  = $file . '.tmp';
        if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $file)) {
            throw new RuntimeException("Could not write {$file}.");
        }
    }

    /** @return resource */
    private function lock()
    {
        $this->mkdir($this->updatesDir());
        $handle = fopen($this->updatesDir() . '/.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another update or rollback is running on this site.');
        }

        return $handle;
    }

    private function updatesDir(): string
    {
        return $this->site . '/admin/updates';
    }

    /** @return list<string> journal files, newest first */
    private function journals(): array
    {
        $files = glob($this->updatesDir() . '/*/journal.json') ?: [];
        rsort($files, SORT_STRING);

        return $files;
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $file = $this->site . '/admin/config/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('No admin/config/config.php on this site.');
        }
        $config = \Cms\Site\ConfigFile::read($file);
        if (!is_array($config)) {
            throw new RuntimeException('admin/config/config.php does not return an array.');
        }

        return $config;
    }

    private ?PDO $pdo = null;

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $c = $this->config();
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['db_host'], $c['db_name'], $c['db_charset'] ?? 'utf8mb4'),
            (string) $c['db_user'],
            (string) $c['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $this->pdo->exec("SET time_zone = '+00:00'");

        return $this->pdo;
    }

    private function probeUrl(): ?string
    {
        if (!empty($this->options['no-probe'])) {
            return null;
        }
        $url = (string) ($this->options['probe-url'] ?? ($this->config()['base_url'] ?? ''));

        return preg_match('#^https?://#', $url) === 1 ? rtrim($url, '/') : null;
    }

    /** @return array{0: int, 1: string, 2: string} status (0 = no answer), body, and the Location it named ('' when none) */
    private function http(string $url, ?string $token): array
    {
        $headers = "User-Agent: CMS-update-probe\r\n" . ($token !== null ? "X-CMS-Smoke: {$token}\r\n" : '');
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 8, 'ignore_errors' => true, 'follow_location' => 0, 'header' => $headers,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $status   = 0;
        $location = '';
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status   = (int) $m[1];
                $location = '';
            } elseif (preg_match('#^Location:\s*(\S+)#i', $line, $m) === 1) {
                $location = $m[1];
            }
        }

        return [$status, $body === false ? '' : $body, $location];
    }

    /**
     * Is $location the page $base . $path with a trailing slash, and nothing
     * else — a path, or an address on the same scheme, host and port; no
     * query, no fragment?
     */
    private static function slashOf(string $location, string $base, string $path): bool
    {
        $to   = $location !== '' ? parse_url($location) : false;
        $from = parse_url($base);
        if (!is_array($to) || !is_array($from) || isset($to['query']) || isset($to['fragment'])) {
            return false;
        }
        if (isset($to['host'])) {
            foreach (['scheme', 'host', 'port'] as $part) {
                if (strtolower((string) ($to[$part] ?? '')) !== strtolower((string) ($from[$part] ?? ''))) {
                    return false;
                }
            }
        }

        return ($to['path'] ?? '') === rtrim((string) ($from['path'] ?? ''), '/') . $path . '/';
    }

    /**
     * @param list<string> $command
     * @return array{code: int, out: string}
     */
    private function run(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->site);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start ' . $command[0]);
        }
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => (string) $out];
    }

    /** @return list<string> every file under $dir, relative */
    private function allFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            $files[] = substr($item->getPathname(), strlen($dir) + 1);
        }

        return $files;
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}.");
        }
        $updates = $this->updatesDir();
        if (str_starts_with($dir, $updates) && !is_file($updates . '/.htaccess')) {
            // admin/ is never served, but a backup holds every user's password
            // hash: deny it outright in case a host serves the project root.
            @file_put_contents($updates . '/.htaccess', "# Update backups and staged releases. Never served.\nRequire all denied\n");
        }
    }

    private function rel(string $path): string
    {
        return str_starts_with($path, $this->site . '/') ? substr($path, strlen($this->site) + 1) : $path;
    }

    private function out(string $line): void
    {
        ($this->say)($line);
    }
}
