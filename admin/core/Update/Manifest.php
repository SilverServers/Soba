<?php

declare(strict_types=1);

namespace Cms\Update;

use RuntimeException;

/**
 * `admin/manifest.json`: what a release shipped, file by file.
 *
 * Written into every release package and left on the site by the install, so
 * an installed site can always answer two questions: which version it is, and
 * whether any of the CMS's files has been changed since it was unpacked. The
 * updater asks the second one before it replaces anything, because an edit to
 * a core file on a live site is somebody's work, and an update that overwrote
 * it silently would lose it.
 *
 * A site installed before manifests existed has none. The update package then
 * carries a BASELINE — the same structure, computed from the tarball that site
 * was installed from — and the updater compares against that instead.
 *
 * Only the CMS's files are listed (the `core` class in `ownership.php`, for
 * the manifest's own layout). Seeds are the site's from the moment they are
 * unpacked, so a site that edits its `content-types.php` has changed nothing of
 * the CMS's.
 */
final class Manifest
{
    public const FILE = 'admin/manifest.json';

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data)
    {
        foreach (['version', 'layout', 'files'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RuntimeException("Manifest has no '{$key}'.");
            }
        }
    }

    public static function read(string $file): ?self
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new RuntimeException("{$file} is not a readable manifest.");
        }

        return new self($data);
    }

    /**
     * Hash every CMS-owned file under `$root`.
     *
     * @param array<string, mixed> $meta version, commit, tag, built, profile …
     */
    public static function build(string $root, Ownership $ownership, array $meta): self
    {
        $files = [];
        foreach ($ownership->coreFilesIn($root) as $relative) {
            $files[$relative] = self::hashFile($root . '/' . $relative);
        }

        $migrations = [];
        foreach (glob(rtrim($root, '/') . '/admin/core/Database/migrations/*.sql') ?: [] as $path) {
            $migrations[] = basename($path);
        }
        sort($migrations, SORT_STRING);

        return new self([
            'schema'     => 1,
            // Another name before 0.7.0 (Compat\OldNames). Never compared: an update accepts either.
            'product'    => 'cms',
            ...$meta,
            'layout'     => $ownership->layout(),
            'migrations' => $migrations,
            'files'      => $files,
        ]);
    }

    public function write(string $file): void
    {
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($file, $json) !== strlen($json)) {
            throw new RuntimeException("Could not write {$file}.");
        }
    }

    public function version(): string
    {
        return (string) $this->data['version'];
    }

    public function layout(): string
    {
        return (string) $this->data['layout'];
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /** @return array<string, string> relative path => sha256 */
    public function files(): array
    {
        return $this->data['files'];
    }

    /**
     * The same manifest with some of its files under other names
     * (`[old => new]`) — Compat\OldNames::manifest().
     *
     * @param array<string, string> $names
     */
    public function renamed(array $names): self
    {
        $files = [];
        foreach ($this->files() as $path => $hash) {
            $files[$names[$path] ?? $path] = $hash;
        }

        return new self(['files' => $files] + $this->data);
    }

    /** @return list<string> */
    public function migrations(): array
    {
        return $this->data['migrations'] ?? [];
    }

    /**
     * How the CMS-owned files under `$root` differ from this manifest.
     *
     *   modified  on disk and listed, with different contents
     *   missing   listed, and gone from disk
     *   unknown   on disk inside a CMS directory, and not listed — put
     *             there by the site, or left by an older release
     *
     * @return array{modified: list<string>, missing: list<string>, unknown: list<string>, same: int}
     */
    public function diffTree(string $root, Ownership $ownership): array
    {
        $listed = $this->files();
        $result = ['modified' => [], 'missing' => [], 'unknown' => [], 'same' => 0];

        // Listed files are checked where they are, whatever the ownership list
        // says today, so a path that changed hands between releases is not
        // reported as deleted.
        foreach ($listed as $relative => $hash) {
            // Where this tree keeps it: its own name for a moved admin folder or
            // a named front controller, when `$ownership` was built with them.
            $path = $root . '/' . $ownership->onSite($relative);
            if (!is_file($path) && !is_link($path)) {
                $result['missing'][] = $relative;
            } elseif (is_link($path) || self::hashFile($path) !== $hash) {
                $result['modified'][] = $relative;
            } else {
                $result['same']++;
            }
        }

        foreach ($ownership->coreFilesIn($root) as $relative) {
            if (!isset($listed[$relative])) {
                $result['unknown'][] = $relative;
            }
        }

        return $result;
    }

    public static function hashFile(string $path): string
    {
        if (is_link($path)) {
            return 'link:' . (string) readlink($path);
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException("Could not read {$path}.");
        }

        return $hash;
    }
}
