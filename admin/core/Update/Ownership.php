<?php

declare(strict_types=1);

namespace Cms\Update;

use Cms\Compat\OldNames;
use RuntimeException;

/**
 * `ownership.php`, resolved for one layout and asked questions of.
 *
 * Both the packager and the updater read the list through this class, so there
 * is one reading of it as well as one copy.
 *
 * ── CANONICAL NAMES, AND AN INSTALL'S OWN ───────────────────────────────────
 * Every list here, and every manifest, says `www/admin/` and the layout's
 * front controller. An install may call them something else
 * (`installNames()`: its named front controller, and an admin folder still at
 * an old address until the updater moves it back), so
 * an Ownership built WITH those names answers for that site: `onSite()` gives
 * where a canonical path sits there, `fromSite()` the reverse, and
 * `coreFilesIn()` walks the site's folders and answers in canonical names.
 * Built without them — a package, the source tree — both are the identity.
 */
final class Ownership
{
    /** @var list<string> the CMS's directories, relative, no trailing slash. */
    private array $coreDirs = [];

    /** @var list<string> the CMS's single files, relative. */
    private array $coreFiles = [];

    /** @var list<string> */
    private array $seed = [];

    /** @var list<string> Entries as written, directories keeping their `/`. */
    private array $site = [];

    /** @var array<string, string> canonical prefix => the site's, longest first */
    private array $toSite = [];

    /**
     * @param array<string, mixed>  $data  The array `ownership.php` returns.
     * @param array<string, string> $names An install's own names: '@front', '@admin' => path.
     */
    private function __construct(private readonly string $layout, private readonly array $data, array $names = [])
    {
        if (!isset($data['layouts'][$layout])) {
            throw new RuntimeException(
                "Unknown layout '{$layout}'. Known: " . implode(', ', array_keys($data['layouts'] ?? []))
            );
        }

        // A list from before 0.7.0 (a tag re-cut by today's packager) calls the
        // class by its old name (Compat\OldNames::OWNERSHIP_CLASS).
        foreach ($data['core'] ?? $data[OldNames::OWNERSHIP_CLASS] ?? [] as $entry) {
            $path = $this->resolve($entry);
            if ($path === null) {
                continue;
            }
            if (str_ends_with($path, '/')) {
                $this->coreDirs[] = rtrim($path, '/');
            } else {
                $this->coreFiles[] = $path;
            }
        }
        foreach ($data['seed'] as $entry) {
            $path = $this->resolve($entry);
            if ($path !== null) {
                $this->seed[] = $path;
            }
        }
        foreach ($data['site'] as $entry) {
            $this->site[] = $entry;
        }
        foreach ($names as $token => $path) {
            $canonical = $data['layouts'][$layout][$token] ?? null;
            if (!is_string($canonical) || !is_string($path) || $path === '' || $path === $canonical) {
                continue;
            }
            if (str_ends_with($canonical, '/') !== str_ends_with($path, '/')) {
                throw new RuntimeException("The install's name for {$token} ({$path}) is not the same kind of path as {$canonical}.");
            }
            $this->toSite[$canonical] = $path;
        }
    }

    /** @param array<string, string> $names an install's own names — see installNames() */
    public static function load(string $layout, ?string $file = null, array $names = []): self
    {
        $file ??= __DIR__ . '/ownership.php';
        if (!is_file($file)) {
            throw new RuntimeException("Ownership list not found at {$file}.");
        }

        return new self($layout, require $file, $names);
    }

    /**
     * The names an install gives two of the CMS's paths, from its config.php:
     *
     *   front_controller   `www/{name}`, when the site named its front
     *                      controller (e.g. `cms.php`)
     *   @admin             `www/{slug}/` ONLY while an install from before
     *                      still keeps its admin there (`oldAdminFolder()`),
     *                      until the updater moves it back to `www/admin/`
     *
     * Refuses a front controller that is not a plain name: it becomes a path
     * an update moves things to. In the router layout (`$routerFront`: its
     * front controller's name now, passed by the caller — never read from
     * ownership.php here, which an update moves mid-way), an install that
     * names none and still has the old front controller's file keeps that
     * name (Compat\OldNames::routerFront()).
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public static function installNames(array $config, ?string $site = null, ?string $routerFront = null): array
    {
        $names = [];
        $slug  = self::oldAdminFolder($config, $site);
        if ($slug !== null) {
            $names['@admin'] = 'www/' . $slug . '/';
        }
        $front = trim((string) ($config['front_controller'] ?? ''));
        if ($front !== '') {
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}\.php$/', $front) !== 1) {
                throw new RuntimeException("config.php's front_controller '{$front}' is not a plain file name ending .php.");
            }
            $names['@front'] = 'www/' . $front;
        } elseif ($routerFront !== null && $site !== null) {
            $old = OldNames::routerFront($site, $routerFront);
            if ($old !== null) {
                $names['@front'] = $old;
            }
        }

        return $names;
    }

    /**
     * AN ADMIN STILL AT ITS OLD ADDRESS. The admin lives at /admin; hiding it
     * is Security → Login protection, a setting. A fixed admin address in
     * config.php (`admin_url_slug`, with `www/admin/` renamed to match) is no
     * longer read — but an install from before keeps its admin's folder at
     * `www/{slug}/` until the updater moves it back (`Updater`). That is the
     * slug: a plain folder name, not `admin` and none of the site's own
     * addresses, its folder there and `www/admin/` not. Anything else is null.
     *
     * @param array<string, mixed> $config
     */
    public static function oldAdminFolder(array $config, ?string $site): ?string
    {
        $slug = trim((string) ($config['admin_url_slug'] ?? ''), '/');
        if ($site === null || $slug === '' || $slug === 'admin'
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $slug) !== 1
            || in_array(strtolower($slug), [...\Cms\Helpers\Slug::PUBLIC_SEGMENTS, 'site'], true)
            || !is_dir($site . '/www/' . $slug) || file_exists($site . '/www/admin')) {
            return null;
        }

        return $slug;
    }

    /** Where a canonical path sits on this install. */
    public function onSite(string $canonical): string
    {
        foreach ($this->toSite as $from => $to) {
            if ($canonical === $from || (str_ends_with($from, '/') && ($canonical . '/' === $from || str_starts_with($canonical, $from)))) {
                return $canonical . '/' === $from ? rtrim($to, '/') : $to . substr($canonical, strlen($from));
            }
        }

        return $canonical;
    }

    /** The canonical name of a path on this install. */
    public function fromSite(string $sitePath): string
    {
        foreach ($this->toSite as $from => $to) {
            if ($sitePath === $to || (str_ends_with($to, '/') && ($sitePath . '/' === $to || str_starts_with($sitePath, $to)))) {
                return $sitePath . '/' === $to ? rtrim($from, '/') : $from . substr($sitePath, strlen($to));
            }
        }

        return $sitePath;
    }

    /**
     * From the array `ownership.php` returns, already read. The updater reads
     * the file once, up front: halfway through an update the file it started
     * from has been moved into the site.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $layout, array $data, array $names = []): self
    {
        return new self($layout, $data, $names);
    }

    /** @return list<string> */
    public static function layouts(?string $file = null): array
    {
        $data = require ($file ?? __DIR__ . '/ownership.php');

        return array_keys($data['layouts']);
    }

    public function layout(): string
    {
        return $this->layout;
    }

    /** Where the CMS's front controller sits in this layout — its canonical name; `onSite()` for this install's. */
    public function front(): string
    {
        return (string) $this->data['layouts'][$this->layout]['@front'];
    }

    /** Where the front controller sits in the source repository. */
    public function sourceFront(): string
    {
        return (string) $this->data['source_front'];
    }

    /** @return list<string> */
    public function coreDirs(): array
    {
        return $this->coreDirs;
    }

    /** @return list<string> */
    public function coreFiles(): array
    {
        return $this->coreFiles;
    }

    /**
     * Every CMS entry, directories first, each as the unit an update swaps.
     *
     * @return list<string>
     */
    public function coreEntries(): array
    {
        return [...$this->coreDirs, ...$this->coreFiles];
    }

    /** @return list<string> */
    public function seedFiles(): array
    {
        return $this->seed;
    }

    /**
     * 'core', 'seed' or 'site' — the most specific entry that covers the path.
     * A path no entry covers is the site's.
     */
    public function classify(string $relative): string
    {
        // A path in this install's own names is asked about by its canonical one.
        $relative = $this->fromSite(ltrim($relative, '/'));
        $best     = 'site';
        $bestLen  = -1;

        $consider = function (string $entry, string $class, bool $isDir) use ($relative, &$best, &$bestLen): void {
            $matches = $isDir
                ? str_starts_with($relative . '/', $entry . '/')
                : $relative === $entry;
            if ($matches && strlen($entry) > $bestLen) {
                $best    = $class;
                $bestLen = strlen($entry);
            }
        };

        foreach ($this->coreDirs as $dir) {
            $consider($dir, 'core', true);
        }
        foreach ($this->coreFiles as $file) {
            $consider($file, 'core', false);
        }
        foreach ($this->seed as $file) {
            $consider($file, 'seed', false);
        }
        foreach ($this->site as $entry) {
            $isDir = str_ends_with($entry, '/');
            $consider(rtrim($entry, '/'), 'site', $isDir);
        }

        return $best;
    }

    /**
     * Every CMS-owned file present under `$root`, relative and sorted.
     *
     * Walks each CMS directory completely — a file in there that no release
     * shipped is exactly what the caller needs to see — and takes the listed
     * single files that exist. `admin/manifest.json` is left out: it is the
     * record of the others and cannot hold its own hash.
     *
     * A symlink is returned like a file, so a caller hashing it sees it: nothing
     * The CMS ships is a link, and one inside the CMS's tree is worth naming.
     *
     * @return list<string>
     */
    public function coreFilesIn(string $root): array
    {
        $root  = rtrim($root, '/');
        $found = [];

        foreach ($this->coreDirs as $dir) {
            $abs = $root . '/' . $this->onSite($dir);
            if (!is_dir($abs)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                /** @var \SplFileInfo $item */
                if ($item->isLink() || $item->isFile()) {
                    $found[] = $this->fromSite(substr($item->getPathname(), strlen($root) + 1));
                }
            }
        }

        foreach ($this->coreFiles as $file) {
            if ($file === 'admin/manifest.json') {
                continue;
            }
            $onSite = $this->onSite($file);
            if (is_file($root . '/' . $onSite) || is_link($root . '/' . $onSite)) {
                $found[] = $file;
            }
        }

        $found = array_values(array_unique($found));
        sort($found, SORT_STRING);

        return $found;
    }

    private function resolve(string $entry): ?string
    {
        if (!str_starts_with($entry, '@')) {
            return $entry;
        }
        $layout = $this->data['layouts'][$this->layout];
        if (!array_key_exists($entry, $layout)) {
            throw new RuntimeException("Ownership entry '{$entry}' has no path in layout '{$this->layout}'.");
        }

        return $layout[$entry];
    }
}
