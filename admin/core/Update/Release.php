<?php

declare(strict_types=1);

namespace Cms\Update;

/**
 * WHICH RELEASE IS THIS? — `admin/VERSION`, the one line every release commit
 * bumps.
 *
 * The one reader of that file for anything this site serves: the admin's
 * footer, Tools → System Health, the default theme's card and the security
 * webhook's payload. `Updater` and `bin/migrate.php` keep their own reads,
 * because they are asked about the site named on their command line, which
 * need not be this one.
 */
final class Release
{
    /** '' when the file is missing, which no install should be. */
    public static function version(): string
    {
        $file = dirname(__DIR__, 2) . '/VERSION';

        return is_file($file) ? trim((string) file_get_contents($file)) : '';
    }

    /**
     * System Health's row: this version, against the release the manifest
     * records.
     *
     * An update copies `admin/manifest.json` LAST (`Updater::apply()`), so the
     * two disagree only when an update stopped part-way or someone edited one
     * of them. A copy with no manifest (a development tree, or a site installed
     * before manifests existed) has nothing to compare, and says so rather than
     * calling itself fine.
     *
     * @return array{label: string, value: string, status: string, hint: string}
     */
    public static function health(string $version, string $manifestFile): array
    {
        $row = ['label' => 'CMS version', 'value' => $version, 'status' => 'ok', 'hint' => ''];
        if ($version === '') {
            return ['value' => 'Unknown', 'status' => 'error', 'hint' => 'admin/VERSION is missing.'] + $row;
        }
        try {
            $manifest = Manifest::read($manifestFile);
        } catch (\RuntimeException $e) {
            return ['status' => 'warning', 'hint' => $e->getMessage()] + $row;
        }

        return match (true) {
            $manifest === null => ['status' => 'info', 'hint' => 'No admin/manifest.json to check it against.'] + $row,
            $manifest->version() !== $version => ['status' => 'warning', 'hint' => 'admin/manifest.json says '
                . $manifest->version() . ': an update stopped part-way, or one of the two was edited.'] + $row,
            default => ['hint' => 'Installed from the ' . $manifest->layout() . ' package.'] + $row,
        };
    }
}
