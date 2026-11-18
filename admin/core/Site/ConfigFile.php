<?php

declare(strict_types=1);

namespace Cms\Site;

/**
 * THE SITE'S OWN FILES IN admin/config/: config.php, which returns its
 * settings; content-types.php, taxonomies.php and menus.php, which register
 * its types, taxonomies and menu locations; and any the site keeps there
 * itself (site-addresses.php, admin-tools.php). An update never touches them.
 *
 * ── READ INSIDE AN OUTPUT BUFFER (`read()`) ─────────────────────────────────
 * A blank line or a byte-order mark before `<?php` is output, printed each
 * time the file is read, and output before a page's headers stops it setting
 * them: no session, no redirect. Read through here, it is dropped.
 *
 * ── WHICH NO BUFFER HELPS IN A FILE THAT DECLARES strict_types ──────────────
 * There, anything before `<?php` puts the declare after output, and the file
 * does not compile: a fatal error on every page, before any buffer or
 * try/catch can act. `problems()` finds that, and every other error that
 * stops a file compiling, with `php -l`, so admin/bin/update.php and
 * migrate.php refuse to go on and say which file and why.
 */
final class ConfigFile
{
    /**
     * What the file returns, read inside an output buffer; null when there is
     * no such file. A file that registers (content-types.php) is read for
     * what it does.
     */
    public static function read(string $path): mixed
    {
        if (!is_file($path)) {
            return null;
        }
        ob_start();
        try {
            return (static fn (string $__f): mixed => require $__f)($path);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * What is wrong with the file, in words, or [] when nothing is:
     *   'fatal'   it does not compile (`php -l`), so whatever reads it stops
     *             with a fatal error: every page, for config.php
     *   'output'  it prints something outside its PHP (a byte-order mark, a
     *             blank line), which `read()` drops but any other reader
     *             prints
     *
     * @return array{fatal?: string, output?: string}
     */
    public static function problems(string $path): array
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            return ['fatal' => 'it cannot be read'];
        }
        $out    = [];
        $stray  = self::strayOutput($text);
        $broken = self::compileError($path);
        if ($broken !== null) {
            $out['fatal'] = isset($stray['before']) && str_contains($broken, 'strict_types declaration must be the very first statement')
                ? 'it has ' . $stray['before'] . ' before <?php, ahead of declare(strict_types=1), so it does not compile:'
                    . ' every page stops with a fatal error. Delete what is before <?php'
                    . (str_starts_with($text, "\xEF\xBB\xBF") ? ' (save it as UTF-8 without a byte-order mark)' : '') . '.'
                : 'it does not compile (' . $broken . '): whatever reads it stops with a fatal error.';
        }
        if ($stray !== [] && !isset($out['fatal'])) {
            $where = [];
            foreach (['before' => ' before <?php', 'after' => ' after ?>'] as $key => $place) {
                if (isset($stray[$key])) {
                    $where[] = $stray[$key] . $place;
                }
            }
            $out['output'] = 'it prints ' . implode(' and ', $where) . ' wherever it is read. The CMS drops it; delete it all the same.';
        }

        return $out;
    }

    /**
     * `problems()` for every PHP file in the folder, by file name, the files
     * with none left out.
     *
     * @return array<string, array{fatal?: string, output?: string}>
     */
    public static function problemsIn(string $dir): array
    {
        $files = glob(rtrim($dir, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $out = [];
        foreach ($files as $file) {
            if (($found = self::problems($file)) !== []) {
                $out[basename($file)] = $found;
            }
        }

        return $out;
    }

    /**
     * What the file prints outside its PHP, in words: 'before' `<?php` ("a
     * byte-order mark", "a blank line") and 'after' a closing `?>`; [] when
     * it prints nothing there.
     *
     * @return array{before?: string, after?: string}
     */
    public static function strayOutput(string $text): array
    {
        $said  = [];
        $first = true;
        $after = '';
        foreach (token_get_all($text) as $token) {
            if (is_array($token) && $token[0] === T_INLINE_HTML && $token[1] !== '') {
                if ($first) {
                    $said['before'] = self::describe($token[1]);
                } else {
                    $after .= $token[1];
                }
            }
            $first = false;
        }
        if ($after !== '') {
            $said['after'] = self::describe($after);
        }

        return $said;
    }

    /** Text outside the PHP, named: a byte-order mark, a blank line, spaces, text. */
    private static function describe(string $html): string
    {
        $parts = [];
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            $parts[] = 'a byte-order mark';
            $html    = substr($html, 3);
        }
        if ($html !== '' && trim($html) === '') {
            $lines   = substr_count($html, "\n") + substr_count(str_replace("\r\n", '', $html), "\r");
            $parts[] = match (true) {
                $lines === 0 => 'spaces',
                $lines === 1 => 'a blank line',
                default      => $lines . ' blank lines',
            };
        } elseif ($html !== '') {
            $parts[] = 'text';
        }

        return implode(' and ', $parts);
    }

    /**
     * What `php -l` says stops the file compiling ("syntax error, unexpected
     * end of file on line 6"), or null when it compiles, or when no
     * command-line PHP can be started to ask.
     */
    public static function compileError(string $path): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $process = @proc_open([\Cms\Export\StaticCopy::php(), '-d', 'display_errors=stderr', '-d', 'log_errors=0',
            '-d', 'auto_prepend_file=', '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $said = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) === 0) {
            return null;
        }
        if (preg_match('/(?:Parse|Fatal) error:\s*(.+?) in .+? on line (\d+)/', (string) $said, $m) === 1) {
            return $m[1] . ' on line ' . $m[2];
        }

        return trim((string) preg_replace('/\s+/', ' ', (string) $said)) ?: 'php -l refused it';
    }
}
