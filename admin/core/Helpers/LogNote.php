<?php

declare(strict_types=1);

namespace Cms\Helpers;

use Cms\Models\Option;

/**
 * A LINE IN THE ERROR LOG FOR WHAT A SITE'S OWN FILE GETS WRONG, said once and
 * then again an hour later while it is still so.
 *
 * Something every admin page reads, such as the column a site arranges
 * (`Site\Column`) or a type's sidebar rows, would otherwise put the same line
 * in the log on every page an editor opens. The line is said, and remembered
 * by what it says and when under its own option; another line is said at once.
 */
final class LogNote
{
    /** Say `$said` in the log, unless the same was said under `$key` within the hour. */
    public static function hourly(string $key, string $said): void
    {
        $noted = (string) Option::get($key, '');
        [$hash, $at] = array_pad(explode('|', $noted, 2), 2, '0');
        if ($hash === md5($said) && time() - (int) $at < 3600) {
            return;
        }
        error_log($said);
        Option::set($key, md5($said) . '|' . time());
    }
}
