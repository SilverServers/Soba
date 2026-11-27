<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Database\Database;
use Cms\Models\Option;
use Cms\Update\DbSnapshot;
use RuntimeException;

/**
 * BACKUPS ON A TIMETABLE (Tools → Backups): the database and the uploads, as
 * one file kept on the server, every day or every week, the newest few kept.
 *
 * ── WHAT ONE HOLDS ──────────────────────────────────────────────────────────
 * A tar file: `database.sql.gz` first, every table as the updater's own
 * backup writes it (`Update\DbSnapshot`), then `uploads/`, every file under
 * `www/uploads/`. Not `config.php`: it holds the database's password and the
 * site's secret, and a backup is a file somebody downloads. Kept in
 * `admin/backups/`, outside the web root, where no update reaches, and
 * 0640, as the updater's snapshot is: the site's own and its group's,
 * whatever the server's umask, since it holds the whole database.
 *
 * ── MADE ON A VISIT, A STEP AT A TIME ───────────────────────────────────────
 * Shared hosting has no cron to rely on, so a visit makes one when it is due,
 * after its page has been sent, as unpaid orders are lapsed
 * (`Commerce\Lapse`): looked for at most every ten minutes, and the time is
 * claimed first so two visits do not both start one. A large site's uploads
 * take longer than a request may run, so a backup is a job: each turn adds
 * files for some seconds and the next visit goes on. A tar is written by
 * appending, with no library, so the file is whole and readable only once
 * its last turn closes it. `admin/bin/run-due.php` makes them from a real
 * cron where there is one, and `admin/bin/backup.php now` at once.
 *
 * ── PUT BACK FROM THE COMMAND LINE ──────────────────────────────────────────
 * `php admin/bin/backup.php restore <id>` backs up the site as it is first,
 * then puts the database and the uploads back as the backup has them. Not
 * from the admin: a restore replaces the very accounts and sessions the
 * person pressing the button is signed in with.
 */
final class Backups
{
    /** How often: 'day', 'week' or 'never'. Weekly until the owner says otherwise. */
    public const EVERY_KEY = 'backup_every';
    public const KEEP_KEY  = 'backup_keep';
    public const RAN_KEY   = 'backup_checked_at';

    public const EVERY = ['day' => 86400, 'week' => 604800, 'never' => 0];
    public const DEFAULT_EVERY = 'week';
    public const DEFAULT_KEEP  = 4;
    public const MAX_KEEP      = 30;

    /** A visit looks at most this often. */
    private const LOOK = 600;

    /** A job no turn has touched for this long is taken to have died, and is started again. */
    private const STALE = 3600;

    // ----------------------------------------------------------------
    // The owner's choices
    // ----------------------------------------------------------------

    public static function every(): string
    {
        $every = (string) (Option::get(self::EVERY_KEY, self::DEFAULT_EVERY) ?? self::DEFAULT_EVERY);
        return array_key_exists($every, self::EVERY) ? $every : self::DEFAULT_EVERY;
    }

    public static function keep(): int
    {
        $keep = (int) (Option::get(self::KEEP_KEY, (string) self::DEFAULT_KEEP) ?? self::DEFAULT_KEEP);
        return max(1, min(self::MAX_KEEP, $keep));
    }

    /** Where they are kept: outside the web root, never served, never touched by an update. */
    public static function dir(): string
    {
        return ROOT_PATH . '/backups';
    }

    // ----------------------------------------------------------------
    // On a visit
    // ----------------------------------------------------------------

    /** On every visit: one read when nothing is due; the work, when it is, after the page. */
    public static function onVisit(): void
    {
        // The updater's smoke test is not a visit: a reinstall leaves the
        // database as it found it.
        if (\Cms\Update\Updater::isProbe($_SERVER)) {
            return;
        }
        if (self::every() === 'never' || time() - (int) (Option::get(self::RAN_KEY, '0') ?? '0') < self::LOOK) {
            return;
        }
        register_shutdown_function([self::class, 'due']);
    }

    /** A turn, when this request wins the claim to it. Never throws: a backup that fails must not become a broken page. */
    public static function due(): void
    {
        try {
            if (!self::claim()) {
                return;
            }
            if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                fastcgi_finish_request();
            }
            @set_time_limit(120);
            self::turn(20.0);
        } catch (\Throwable $e) {
            error_log('CMS backups: ' . $e->getMessage());
        }
    }

    /**
     * Go on with the job under way, or start one when the last finished is
     * older than the owner's choice allows. True once nothing is left to do.
     */
    public static function turn(float $seconds): bool
    {
        $job = self::running();
        if ($job === null) {
            $period = self::EVERY[self::every()];
            $last   = self::all()[0]['finished'] ?? 0;
            if ($period === 0 || time() - (int) $last < $period) {
                return true;
            }
            $job = self::start('timetable');
        }
        return self::step($job, $seconds);
    }

    /**
     * The visit that may look now takes the look, so a second one does not:
     * `Commerce\Lapse`'s claim, compared and swapped on the stored time.
     */
    private static function claim(): bool
    {
        $db   = Database::getInstance();
        $now  = time();
        $read = $db->prepare('SELECT option_value FROM options WHERE option_key = :k');
        $read->execute([':k' => self::RAN_KEY]);
        $last = $read->fetchColumn();
        if ($last === false) {
            $stmt = $db->prepare('INSERT IGNORE INTO options (option_key, option_value) VALUES (:k, :v)');
            $stmt->execute([':k' => self::RAN_KEY, ':v' => (string) $now]);

            return $stmt->rowCount() === 1;
        }
        if ($now - (int) $last < self::LOOK) {
            return false;
        }
        $stmt = $db->prepare('UPDATE options SET option_value = :v WHERE option_key = :k AND option_value <=> :was');
        $stmt->execute([':v' => (string) $now, ':k' => self::RAN_KEY, ':was' => $last]);

        return $stmt->rowCount() === 1;
    }

    // ----------------------------------------------------------------
    // A backup
    // ----------------------------------------------------------------

    /**
     * The job under way, if one is, and not stale: a backup being made, or a
     * finished one whose copy is still going off the server (`BackupCopy`).
     * A stale backup is removed; a stale copy is only said to have stopped,
     * since its backup is whole.
     *
     * @return array<string, mixed>|null
     */
    public static function running(): ?array
    {
        foreach (self::jobs() as $job) {
            if ($job['phase'] === 'done' && !self::copying($job)) {
                continue;
            }
            if (time() - (int) $job['touched'] > self::STALE) {
                if ($job['phase'] === 'done') {
                    if (is_string($job['copy']['upload'] ?? null)) {
                        BackupCopy::abandon((string) $job['id'], (string) $job['copy']['upload']);
                    }
                    $job['copy'] = ['state' => 'failed', 'to' => (string) ($job['copy']['to'] ?? ''), 'at' => time(),
                                    'error' => 'its copy stopped part-way and was not finished'];
                    self::save($job);
                    continue;
                }
                self::remove((string) $job['id']);
                continue;
            }
            return $job;
        }
        return null;
    }

    /** Whether a job's copy off the server is still to go, or going. @param array<string, mixed> $job */
    private static function copying(array $job): bool
    {
        return in_array($job['copy']['state'] ?? '', BackupCopy::UNDER_WAY, true);
    }

    /**
     * A new backup, its files listed now; the work is done by `step()`. One at a
     * time: a job under way is returned instead.
     *
     * @return array<string, mixed>
     */
    public static function start(string $why): array
    {
        $running = self::running();
        if ($running !== null) {
            return $running;
        }
        self::prepare();
        $id    = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $files = [];
        $root  = PUBLIC_PATH . '/uploads';
        if (is_dir($root)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile() && !$file->isLink()) {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($files, SORT_STRING);
        self::write(self::dir() . '/' . $id . '.list', implode("\n", $files));
        $job = [
            'id' => $id, 'why' => $why, 'phase' => 'database', 'offset' => 0, 'count' => count($files),
            'started' => time(), 'touched' => time(), 'finished' => null, 'bytes' => 0, 'skipped' => [],
        ];
        self::save($job);
        return $job;
    }

    /**
     * Some seconds more of a job. True once it is finished.
     *
     * @param array<string, mixed> $job
     */
    public static function step(array $job, float $seconds): bool
    {
        $lock = fopen(self::dir() . '/lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return false;   // another turn has it
        }
        try {
            $job   = self::read((string) $job['id']) ?? $job;
            $until = microtime(true) + max(0.0, $seconds);
            $part  = self::dir() . '/' . $job['id'] . '.part';
            while (($job['phase'] !== 'done' || self::copying($job)) && microtime(true) < $until) {
                if ($job['phase'] === 'done') {
                    // ITS COPY OFF THE SERVER, the next parts of it (`BackupCopy`).
                    BackupCopy::step($job, $until);
                } elseif ($job['phase'] === 'database') {
                    $dump = self::dir() . '/' . $job['id'] . '-database.sql.gz';
                    DbSnapshot::dump(Database::getInstance(), $dump);
                    $tar = fopen($part, 'wb');
                    if ($tar === false) {
                        throw new RuntimeException('Could not write ' . $part . '.');
                    }
                    // 0640 before its first byte, whatever the server's umask;
                    // the rename that finishes it keeps the mode.
                    @chmod($part, 0640);
                    self::add($tar, 'database.sql.gz', $dump);
                    fclose($tar);
                    @unlink($dump);
                    $job['phase'] = 'uploads';
                } elseif ($job['phase'] === 'uploads') {
                    $list = explode("\n", (string) @file_get_contents(self::dir() . '/' . $job['id'] . '.list'));
                    $list = array_values(array_filter($list, 'strlen'));
                    $tar  = fopen($part, 'ab');
                    if ($tar === false) {
                        throw new RuntimeException('Could not write ' . $part . '.');
                    }
                    while ($job['offset'] < count($list) && microtime(true) < $until) {
                        $relative = $list[$job['offset']];
                        $source   = PUBLIC_PATH . '/uploads/' . $relative;
                        if (is_file($source) && !self::add($tar, 'uploads/' . $relative, $source)) {
                            $job['skipped'][] = $relative;   // a name too long for the format
                        }
                        $job['offset']++;
                    }
                    fclose($tar);
                    if ($job['offset'] >= count($list)) {
                        $job['phase'] = 'close';
                    }
                } elseif ($job['phase'] === 'close') {
                    // Two empty blocks end a tar; only then is the file whole.
                    file_put_contents($part, str_repeat("\0", 1024), FILE_APPEND);
                    $tarFile = self::dir() . '/' . $job['id'] . '.tar';
                    if (!@rename($part, $tarFile)) {
                        throw new RuntimeException('Could not finish ' . $tarFile . '.');
                    }
                    @unlink(self::dir() . '/' . $job['id'] . '.list');
                    $job['phase']    = 'done';
                    $job['finished'] = time();
                    $job['bytes']    = (int) filesize($tarFile);
                    // Whole, and listed: its copy, where config.php names
                    // somewhere, goes on from here (`BackupCopy`).
                    if (BackupCopy::config() !== null) {
                        $job['copy'] = ['state' => 'waiting', 'to' => BackupCopy::label()];
                    }
                }
                $job['touched'] = time();
                self::save($job);
            }
            if ($job['phase'] === 'done') {
                self::prune();
            }
            return $job['phase'] === 'done' && !self::copying($job);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** One backup made whole now: for the command line, and a restore's own backup first. */
    public static function now(string $why): string
    {
        $job = self::start($why);
        while (!self::step($job, 30.0)) {
            $job = self::read((string) $job['id']) ?? $job;
        }
        return (string) $job['id'];
    }

    // ----------------------------------------------------------------
    // The list
    // ----------------------------------------------------------------

    /**
     * The finished backups, newest first: id, when, the file and its size,
     * and its copy off the server (`BackupCopy`) as far as it has gone: null
     * when none was asked for.
     *
     * @return list<array{id: string, why: string, finished: int, bytes: int, file: string, count: int, copy: ?array<string, mixed>}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::jobs() as $job) {
            $file = self::dir() . '/' . $job['id'] . '.tar';
            if ($job['phase'] === 'done' && is_file($file)) {
                $out[] = [
                    'id'       => (string) $job['id'],
                    'why'      => (string) $job['why'],
                    'finished' => (int) $job['finished'],
                    'bytes'    => (int) filesize($file),
                    'file'     => $file,
                    'count'    => (int) $job['count'],
                    'copy'     => is_array($job['copy'] ?? null) ? $job['copy'] : null,
                ];
            }
        }
        return $out;
    }

    /** One finished backup's file, or null. */
    public static function file(string $id): ?string
    {
        foreach (self::all() as $backup) {
            if ($backup['id'] === $id) {
                return $backup['file'];
            }
        }
        return null;
    }

    /** A backup and everything of it, gone. */
    public static function remove(string $id): void
    {
        if (!self::isId($id)) {
            return;
        }
        foreach (['.json', '.tar', '.part', '.list', '-database.sql.gz'] as $suffix) {
            @unlink(self::dir() . '/' . $id . $suffix);
        }
    }

    /** All the finished ones but the newest the owner keeps. */
    private static function prune(): void
    {
        foreach (array_slice(self::all(), self::keep()) as $old) {
            self::remove($old['id']);
        }
    }

    /** @return list<array<string, mixed>> every job, newest first */
    private static function jobs(): array
    {
        $out = [];
        foreach (is_dir(self::dir()) ? (scandir(self::dir()) ?: []) : [] as $name) {
            if (str_ends_with($name, '.json') && self::isId(substr($name, 0, -5))) {
                $job = self::read(substr($name, 0, -5));
                if ($job !== null) {
                    $out[] = $job;
                }
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) $b['id'], (string) $a['id']));
        return $out;
    }

    // ----------------------------------------------------------------
    // Putting one back (the command line)
    // ----------------------------------------------------------------

    /**
     * Put the site back as backup `$id` has it: a backup of the site as it is
     * now first, then the database, then the uploads. Each step says what it
     * did through `$say`. The uploads' `.htaccess` files are the site's own
     * as they are now, whatever the backup holds: they are the CMS's
     * protections, not the site's content.
     *
     * @param callable(string): void $say
     */
    public static function restore(string $id, callable $say): void
    {
        $file = self::file($id);
        if ($file === null) {
            throw new RuntimeException('There is no finished backup ' . $id . '.');
        }
        $say('Backing up the site as it is now first…');
        $before = self::now('before restoring ' . $id);
        $say('  kept as ' . $before);

        $work = self::dir() . '/restore-' . $id;
        if (is_dir($work)) {
            self::emptyDir($work);
        }
        @mkdir($work . '/uploads', 0775, true);
        $say('Reading ' . basename($file) . '…');
        $database = null;
        self::extract($file, static function (string $name) use ($work, &$database): ?string {
            if ($name === 'database.sql.gz') {
                return $database = $work . '/database.sql.gz';
            }
            // NOT THE UPLOADS' PROTECTIONS. They are the CMS's, not the site's
            // (nothing uploaded runs; a private file is never served), and the
            // site keeps the ones it has, below.
            if (str_starts_with($name, 'uploads/') && !str_contains($name, '..') && basename($name) !== '.htaccess') {
                return $work . '/' . $name;
            }
            return null;
        });
        if ($database === null || !is_file($database)) {
            throw new RuntimeException('The backup has no database in it; nothing was changed.');
        }

        $say('Putting the database back…');
        DbSnapshot::restore(Database::getInstance(), $database);

        $say('Putting the uploads back…');
        $uploads = PUBLIC_PATH . '/uploads';
        foreach (self::protections($uploads) as $relative) {
            @mkdir(dirname($work . '/uploads/' . $relative), 0775, true);
            if (!@copy($uploads . '/' . $relative, $work . '/uploads/' . $relative)) {
                throw new RuntimeException('Could not keep uploads/' . $relative . '; the database is back, the uploads are as they were.');
            }
        }
        $aside   = PUBLIC_PATH . '/uploads.before-restore-' . gmdate('Ymd-His');
        if (is_dir($uploads) && !@rename($uploads, $aside)) {
            throw new RuntimeException('Could not move ' . $uploads . ' aside; the database is back, the uploads are not.');
        }
        if (!@rename($work . '/uploads', $uploads)) {
            @rename($aside, $uploads);
            throw new RuntimeException('Could not put the uploads in place; the database is back, the uploads are as they were.');
        }
        self::emptyDir($aside);
        @rmdir($aside);
        self::emptyDir($work);
        @rmdir($work);
        foreach (['.htaccess' => 'an uploaded file from running', 'private/.htaccess' => 'a private file from being served'] as $own => $stops) {
            if (!is_file($uploads . '/' . $own)) {
                $say('Note: uploads/' . $own . ' is missing, as it was before the restore. It stops ' . $stops
                    . '; put it back from this version\'s install package.');
            }
        }
        $say('Done. The site as it was before is backup ' . $before . '.');
    }

    /**
     * The `.htaccess` files in the uploads folder, as paths inside it: the
     * protections a restore keeps as the site has them.
     *
     * @return list<string>
     */
    private static function protections(string $uploads): array
    {
        $out = [];
        if (is_dir($uploads)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploads, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile() && !$file->isLink() && $file->getFilename() === '.htaccess') {
                    $out[] = substr($file->getPathname(), strlen($uploads) + 1);
                }
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    // ----------------------------------------------------------------
    // The tar format, written and read with no library
    // ----------------------------------------------------------------

    /**
     * Append `$source` to the tar as `$name`. False, and nothing written, for
     * a name the format cannot hold (over 100 bytes with no way to put the
     * rest in its 155-byte prefix).
     *
     * @param resource $tar
     */
    private static function add($tar, string $name, string $source): bool
    {
        $split = self::nameParts($name);
        if ($split === null) {
            return false;
        }
        [$prefix, $base] = $split;
        $size   = (int) filesize($source);
        $header = pack('a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            $base, '0000644', '0000000', '0000000', sprintf('%011o', $size), sprintf('%011o', (int) filemtime($source)),
            '        ', '0', '', 'ustar', '00', 'cms', 'cms', '', '', $prefix, '');
        $sum    = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($header[$i]);
        }
        $header = substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);
        fwrite($tar, $header);
        $in = fopen($source, 'rb');
        if ($in !== false) {
            stream_copy_to_stream($in, $tar);
            fclose($in);
        }
        $pad = (512 - $size % 512) % 512;
        if ($pad > 0) {
            fwrite($tar, str_repeat("\0", $pad));
        }
        return true;
    }

    /** `[prefix, name]` for a path, or null when it cannot be split to fit. @return array{0: string, 1: string}|null */
    private static function nameParts(string $path): ?array
    {
        if (strlen($path) <= 100) {
            return ['', $path];
        }
        for ($i = strlen($path) - 1; $i > 0; $i--) {
            if ($path[$i] === '/' && $i <= 155 && strlen($path) - $i - 1 <= 100) {
                return [substr($path, 0, $i), substr($path, $i + 1)];
            }
        }
        return null;
    }

    /**
     * Read a tar, writing each entry where `$where` says (null: skip it).
     *
     * @param callable(string): ?string $where
     */
    public static function extract(string $file, callable $where): void
    {
        $in = fopen($file, 'rb');
        if ($in === false) {
            throw new RuntimeException('Could not read ' . $file . '.');
        }
        try {
            while (($header = fread($in, 512)) !== false && strlen($header) === 512) {
                if (trim($header, "\0") === '') {
                    break;   // the end
                }
                $f      = unpack('a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8sum/a1type/a100link/a6magic/a2version/a32uname/a32gname/a8major/a8minor/a155prefix', $header);
                $name   = trim((string) $f['name'], "\0");
                $prefix = trim((string) $f['prefix'], "\0");
                $name   = $prefix !== '' ? $prefix . '/' . $name : $name;
                $size   = octdec(trim((string) $f['size'], "\0 "));
                $to     = in_array($f['type'], ['0', "\0"], true) ? $where($name) : null;
                $out    = null;
                if ($to !== null) {
                    @mkdir(dirname($to), 0775, true);
                    $out = fopen($to, 'wb');
                }
                $left = (int) $size;
                while ($left > 0) {
                    $chunk = fread($in, min(1048576, $left));
                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException($file . ' ends in the middle of ' . $name . '.');
                    }
                    if ($out !== null && $out !== false) {
                        fwrite($out, $chunk);
                    }
                    $left -= strlen($chunk);
                }
                if ($out !== null && $out !== false) {
                    fclose($out);
                }
                $pad = (512 - ((int) $size) % 512) % 512;
                if ($pad > 0) {
                    fread($in, $pad);
                }
            }
        } finally {
            fclose($in);
        }
    }

    // ----------------------------------------------------------------
    // Bookkeeping
    // ----------------------------------------------------------------

    /** A size as people read it: "820 KB", "1.4 GB" (`Media::sizeInWords()`). */
    public static function size(int $bytes): string
    {
        return \Cms\Models\Media::sizeInWords($bytes);
    }

    private static function prepare(): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create ' . $dir . '.');
        }
        // admin/ is never served, but a backup holds every account's password hash.
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "# Backups of this site. Never served.\nRequire all denied\n");
        }
        // A backup kept here with a wider mode, an older one or one copied in,
        // is narrowed as the next one starts.
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}\.(tar|json)$/', $name) === 1) {
                @chmod($dir . '/' . $name, 0640);
            }
        }
    }

    /** @param array<string, mixed> $job */
    private static function save(array $job): void
    {
        self::write(self::dir() . '/' . $job['id'] . '.json', (string) json_encode($job, JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed>|null */
    private static function read(string $id): ?array
    {
        if (!self::isId($id)) {
            return null;
        }
        $job = json_decode((string) @file_get_contents(self::dir() . '/' . $id . '.json'), true);
        return is_array($job) ? $job : null;
    }

    /** A job's record or its list of files: whole or not at all, and 0640, as the backup is. */
    private static function write(string $file, string $bytes): void
    {
        $written = @file_put_contents($file . '.tmp', $bytes) !== false;
        if ($written) {
            @chmod($file . '.tmp', 0640);
        }
        if (!$written || !@rename($file . '.tmp', $file)) {
            throw new RuntimeException('Could not write ' . $file . '.');
        }
    }

    private static function isId(string $id): bool
    {
        return preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $id) === 1;
    }

    private static function emptyDir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
    }
}
