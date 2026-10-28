<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Models\Option;
use Cms\Update\Manifest;
use Cms\Update\Ownership;

/**
 * HAS ANYONE CHANGED THE CMS'S FILES? — file change detection.
 *
 * Every install carries `admin/manifest.json`: what its release shipped, file
 * by file, as SHA-256 (`Update\Manifest`, written when the release is
 * packaged). This compares the files on disk with it — the comparison the
 * updater makes before it replaces anything — and names each of the CMS's
 * files that is modified, missing, or added inside the CMS's own folders.
 * Nothing is fetched and nothing is sent: the manifest is the release's own
 * record, on the site.
 *
 * ── A DEVELOPMENT TREE HAS NO MANIFEST ──────────────────────────────────────
 * Only a package writes one. Without it there is nothing to compare with, and
 * every screen says so rather than calling the files fine.
 *
 * ── LIVE ON THE SCREEN; ONCE A DAY BY ITSELF ────────────────────────────────
 * 820 files compare in about 60 ms, so Security → Files compares every time it
 * is opened: it shows what is true now, not what was true at the last check.
 * Once a day the site checks by itself, after a page has gone (`poke()`), with
 * no cron. Whichever check first sees a change the last check did not — a file
 * that differs and did not, or differs AGAIN, with other contents — is the one
 * that logs it (`note()`). So a change is logged once, not every day it stays;
 * a file put back is not logged at all; and the notification channels can send
 * what was (`Alerts`).
 */
final class FileCheck
{
    /** The last check: when, and a fingerprint of what it found. */
    public const LAST_KEY = 'security_files_last';

    public const EVERY = 86400;

    /** How many paths of each kind a screen lists; the rest are counted. */
    public const LISTED = 100;

    /**
     * How many differing files a check keeps, with their hashes — about 40 KB
     * of the 64 KB an option holds. Past it, only a print is kept.
     */
    public const KEPT = 300;

    private static bool $queued = false;

    /** The site's project root: the directory holding admin/ and www/. */
    public static function site(): string
    {
        return dirname(ROOT_PATH);
    }

    /**
     * Compare now. Null when this tree has no manifest — not an install.
     *
     * @return array{version: string, modified: list<string>, missing: list<string>, unknown: list<string>, same: int}|null
     */
    public static function compare(?string $site = null): ?array
    {
        $site ??= self::site();
        $manifest = Manifest::read($site . '/' . Manifest::FILE);
        if ($manifest === null) {
            return null;
        }
        // Read with the site's own name for its front controller
        // (`Ownership::installNames()`), or that file would be "missing" and
        // the renamed one "unknown".
        $config = @include $site . '/admin/config/config.php';
        $rules  = Ownership::load($manifest->layout(), $site . '/admin/core/Update/ownership.php');
        $names  = is_array($config) ? Ownership::installNames($config, $site, $manifest->layout() === 'router' ? $rules->front() : null) : [];
        $diff   = $manifest->diffTree($site, Ownership::load($manifest->layout(), $site . '/admin/core/Update/ownership.php', $names));

        return ['version' => $manifest->version(), 'site' => $site] + $diff;
    }

    /** How many differ. */
    public static function changed(array $result): int
    {
        return count($result['modified']) + count($result['missing']) + count($result['unknown']);
    }

    /** "2 modified, 1 added" — the log's detail, and the Overview's. */
    public static function summary(array $result): string
    {
        $parts = [];
        foreach (['modified' => 'modified', 'missing' => 'missing', 'unknown' => 'added'] as $kind => $word) {
            if ($result[$kind] !== []) {
                $parts[] = count($result[$kind]) . ' ' . $word;
            }
        }

        return $parts === [] ? 'all ' . (int) $result['same'] . ' as shipped' : implode(', ', $parts);
    }

    /**
     * What differs, as one string: the same files in the same state, the same
     * print, in whatever order they came. Takes a `state()` (paths and
     * contents) or a `compare()` result (paths only).
     */
    public static function fingerprint(array $result): string
    {
        $lists = [];
        foreach (['modified', 'missing', 'unknown'] as $kind) {
            $files = $result[$kind] ?? [];
            if (array_is_list($files)) {
                sort($files, SORT_STRING);
            } else {
                ksort($files, SORT_STRING);
            }
            $lists[$kind] = $files;
        }

        return hash('sha256', (string) json_encode($lists));
    }

    /**
     * What differs, file by file, with each file's contents as they are now —
     * so a file changed AGAIN is told from one left as it was. A missing file
     * has no contents.
     *
     * @return array<string, array<string, string>>  kind => path => sha256 ('' when missing or unreadable)
     */
    public static function state(array $result): array
    {
        $site  = (string) ($result['site'] ?? '');
        $state = [];
        foreach (['modified', 'missing', 'unknown'] as $kind) {
            $state[$kind] = [];
            foreach ($result[$kind] as $path) {
                $file = $site . '/' . $path;
                $state[$kind][$path] = $kind !== 'missing' && $site !== '' && (is_file($file) || is_link($file))
                    ? Manifest::hashFile($file) : '';
            }
        }

        return $state;
    }

    /**
     * Keep what a check found, and log it when it found a change no earlier
     * check had seen: a file that now differs and did not, or differs with
     * other contents than last time. Not a file put back, and not the same
     * difference seen again. True when it logged. A check of a tree with no
     * manifest keeps nothing.
     *
     * @param array<string, mixed>|null $result  compare()
     */
    public static function note(?array $result): bool
    {
        if ($result === null) {
            return false;
        }
        $state  = self::state($result);
        $last   = self::last();
        $before = is_array($last['state'] ?? null) ? $last['state'] : null;
        $new    = false;
        if (self::changed($result) > 0) {
            if ($before === null && isset($last['print'], $last['kept']) && $last['kept'] === false) {
                // Too many to keep last time: only a print to compare with.
                $new = $last['print'] !== self::fingerprint($state);
            } else {
                foreach ($state as $kind => $files) {
                    foreach ($files as $path => $hash) {
                        if (($before[$kind][$path] ?? null) !== $hash) {
                            $new = true;
                            break 2;
                        }
                    }
                }
            }
        }
        if ($new) {
            // The site's own finding, whoever's page it ran after.
            SecurityLog::record(SecurityLog::FILES_CHANGED, null, null, self::summary($result), '0.0.0.0', false);
        }
        $keep = self::changed($result) <= self::KEPT;
        Option::set(self::LAST_KEY, (string) json_encode([
            'at'      => gmdate('Y-m-d H:i:s'),
            'print'   => self::fingerprint($state),
            'summary' => self::summary($result),
            'changed' => self::changed($result),
            'kept'    => $keep,
            'state'   => $keep ? $state : null,
        ], JSON_UNESCAPED_SLASHES));

        return $new;
    }

    /** @return array{at: string, print: string, summary: string, changed: int, kept?: bool, state?: array<string, array<string, string>>|null}|null */
    public static function last(): ?array
    {
        $raw  = Option::get(self::LAST_KEY);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) && isset($data['at'], $data['print']) ? $data : null;
    }

    /**
     * Every request asks — one settings read — whether a day has passed since
     * the last check. When one has, the check runs at shutdown, after the
     * page has gone. Registered after the audit's hook (`Audit`), so what it
     * writes is never counted as an administrator's change.
     */
    public static function poke(): void
    {
        if (self::$queued || PHP_SAPI === 'cli') {
            return;
        }
        $last = self::last();
        if ($last !== null && (int) strtotime($last['at'] . ' UTC') > time() - self::EVERY) {
            return;
        }
        if (!is_file(self::site() . '/' . Manifest::FILE)) {
            return;                                 // a development tree: nothing to compare
        }
        self::$queued = true;
        register_shutdown_function([self::class, 'daily']);
    }

    /** The daily check. Never throws: a page has been served and must stay served. */
    public static function daily(): void
    {
        try {
            if (function_exists('fastcgi_finish_request')) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                fastcgi_finish_request();
            }
            self::note(self::compare());
        } catch (\Throwable $e) {
            error_log('CMS file check: ' . $e->getMessage());
        }
    }
}
