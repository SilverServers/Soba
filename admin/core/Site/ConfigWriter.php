<?php

declare(strict_types=1);

namespace Cms\Site;

use RuntimeException;

/**
 * ONE SETTING OF config.php CHANGED, AND THE REST KEPT: its other settings,
 * its comments, its layout and its declare, byte for byte
 * (`admin/bin/config.php`).
 *
 * ── HOW ─────────────────────────────────────────────────────────────────────
 * The file is read as PHP's own tokens, and only the one value's tokens
 * change: replaced where the key is, added after the last setting where it is
 * not, taken out with its line to unset it. What that makes is written beside
 * config.php and read back by a PHP of its own, the one way a script can read
 * PHP back, since a sound host refuses data:// (allow_url_include). It takes
 * config.php's place, by rename, only when it compiles and gives exactly the
 * settings config.php gave, with that one changed. Anything else leaves
 * config.php as it was, and says why.
 *
 * ── WHAT IT TAKES ───────────────────────────────────────────────────────────
 * A config.php that returns its settings as one array, `return [ … ];` or
 * `return array( … );`, as config.example.php does. A key is a plain name
 * (`mail_transport`); a value is text, a number, true, false, or an array of
 * those. null unsets.
 */
final class ConfigWriter
{
    /**
     * Set `$key` to `$value`, or unset it when `$value` is null. True when the
     * file changed; false when it already said so.
     *
     * @throws RuntimeException when it cannot, with config.php left as it was
     */
    public static function set(string $path, string $key, mixed $value): bool
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
            throw new RuntimeException("\"{$key}\" is not a setting's name: letters, digits and _ only.");
        }
        self::checkValue($value);
        if (!is_file($path)) {
            throw new RuntimeException("There is no {$path}.");
        }
        $problems = ConfigFile::problems($path);
        if (isset($problems['fatal'])) {
            throw new RuntimeException(self::name($path) . ': ' . $problems['fatal'] . ' Nothing was changed.');
        }

        $old = self::readApart($path);
        if (self::readApart($path) !== $old) {
            throw new RuntimeException(self::name($path) . ' gives different settings each time it is read, so a change to it'
                . ' cannot be checked: change it by hand. Nothing was changed.');
        }
        $want = $old;
        if ($value === null) {
            unset($want[$key]);
        } else {
            $want[$key] = $value;
        }
        if ($want === $old) {
            return false;
        }

        $new = self::edit((string) file_get_contents($path), $key, $value, $path);
        $tmp = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        try {
            if (@file_put_contents($tmp, $new) === false) {
                throw new RuntimeException('A file could not be written beside ' . self::name($path) . '. Nothing was changed.');
            }
            @chmod($tmp, fileperms($path) & 0777);
            $after = ConfigFile::problems($tmp);
            if (isset($after['fatal'])) {
                throw new RuntimeException('The changed ' . basename($path) . ' would not compile (' . $after['fatal'] . '). Nothing was changed.');
            }
            if (self::readApart($tmp) !== $want) {
                throw new RuntimeException('The changed ' . basename($path) . " would not give the settings it gave with only {$key} changed."
                    . ' Nothing was changed.');
            }
            if (!@rename($tmp, $path)) {
                throw new RuntimeException(self::name($path) . ' could not be replaced. Nothing was changed.');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return true;
    }

    /**
     * The settings the file gives, read by a PHP of its own, so a file that
     * stops with a fatal error stops that one and not this.
     *
     * @return array<string, mixed>
     * @throws RuntimeException when it gives no array
     */
    public static function readApart(string $path): array
    {
        $code = 'ob_start(); $c = (static fn (string $__f): mixed => require $__f)($argv[1]); ob_end_clean();'
            . ' echo serialize($c);';
        $process = function_exists('proc_open') ? @proc_open([\Cms\Export\StaticCopy::php(), '-d', 'auto_prepend_file=',
            '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', $code, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes) : false;
        if (!is_resource($process)) {
            throw new RuntimeException('A command-line PHP could not be started to read ' . self::name($path) . ' back. Nothing was changed.');
        }
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $read = $code === 0 ? @unserialize($out, ['allowed_classes' => false]) : false;
        if (!is_array($read)) {
            throw new RuntimeException(self::name($path) . ' does not return its settings as an array. Nothing was changed.');
        }

        return $read;
    }

    /**
     * The file's text with that one value changed.
     *
     * @throws RuntimeException when its settings are not one array it returns
     */
    private static function edit(string $text, string $key, mixed $value, string $path): string
    {
        $tokens = \PhpToken::tokenize($text);
        $count  = count($tokens);
        $refuse = static fn (string $why): RuntimeException
            => new RuntimeException(self::name($path) . ' ' . $why . ', so it cannot be changed here: change it by hand. Nothing was changed.');

        // THE SETTINGS: the first `return` outside every brace, then `[` or `array(`.
        $depth  = 0;
        $return = null;
        foreach ($tokens as $i => $t) {
            if ($t->text === '{' || $t->id === T_CURLY_OPEN || $t->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($t->text === '}') {
                $depth--;
            } elseif ($t->id === T_RETURN && $depth === 0) {
                $return = $i;
                break;
            }
        }
        $open = $return === null ? null : self::next($tokens, $return);
        if ($open !== null && $tokens[$open]->id === T_ARRAY) {
            $open = self::next($tokens, $open);
            $open = $open !== null && $tokens[$open]->text === '(' ? $open : null;
        } elseif ($open !== null && $tokens[$open]->text !== '[') {
            $open = null;
        }
        if ($open === null) {
            throw $refuse('does not return its settings as one array, `return [ … ];`');
        }
        $closing = $tokens[$open]->text === '[' ? ']' : ')';

        // ITS ENTRIES, at the array's own depth: where each starts and ends,
        // its `=>`, its comma, and its key when that is a plain string.
        $entries = [];
        $d       = 0;
        $entry   = null;
        $close   = null;
        for ($i = $open + 1; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t->isIgnorable()) {
                continue;
            }
            if ($d === 0 && ($t->text === ',' || $t->text === $closing)) {
                if ($entry !== null) {
                    $entry['comma'] = $t->text === ',' ? $i : null;
                    $entries[]      = $entry;
                }
                $entry = null;
                if ($t->text === $closing) {
                    $close = $i;
                    break;
                }
                continue;
            }
            $entry ??= ['start' => $i, 'end' => $i, 'arrow' => null, 'comma' => null];
            if ($d === 0 && $t->id === T_DOUBLE_ARROW && $entry['arrow'] === null) {
                $entry['arrow'] = $i;
            }
            if (in_array($t->text, ['[', '(', '{'], true) || $t->id === T_CURLY_OPEN || $t->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $d++;
            } elseif (in_array($t->text, [']', ')', '}'], true)) {
                $d--;
            }
            $entry['end'] = $i;
        }
        if ($close === null) {
            throw $refuse('has an array that does not close');
        }
        $mine = [];
        foreach ($entries as $e) {
            $k = $e['arrow'] !== null && self::next($tokens, $e['start']) === $e['arrow'] ? self::keyOf($tokens[$e['start']]) : null;
            if ($k === $key) {
                $mine[] = $e;
            }
        }
        $end = static fn (int $i): int => $tokens[$i]->pos + strlen($tokens[$i]->text);

        if ($value !== null && $mine !== []) {
            // REPLACED: the last one, which is the one PHP keeps.
            $e    = end($mine);
            $from = self::next($tokens, (int) $e['arrow']);
            if ($from === null || $from > $e['end']) {
                throw $refuse("has {$key} with no value");
            }
            return substr($text, 0, $tokens[$from]->pos) . self::literal($value) . substr($text, $end($e['end']));
        }

        if ($value !== null) {
            // ADDED after the last setting, at its indent.
            $line = self::literal($key) . ' => ' . self::literal($value) . ',';
            if ($entries === []) {
                return substr($text, 0, $end($open)) . "\n    " . $line . "\n" . substr($text, $end($open));
            }
            $last   = end($entries);
            $start  = $tokens[$last['start']]->pos;
            $nl     = strrpos(substr($text, 0, $start), "\n");
            $before = $nl === false ? '' : substr($text, $nl + 1, $start - $nl - 1);
            $indent = $nl !== false && trim($before, " \t") === '' ? $before : '    ';
            $after  = $last['comma'] !== null ? $end($last['comma']) : $end($last['end']);

            return substr($text, 0, $after) . ($last['comma'] !== null ? '' : ',') . "\n" . $indent . $line . substr($text, $after);
        }

        // UNSET: each one taken out with its line, the comment that ends the
        // line included, from the last back so the places stay right.
        foreach (array_reverse($mine) as $e) {
            $from      = $tokens[$e['start']]->pos;
            $nl        = strrpos(substr($text, 0, $from), "\n");
            $lineStart = $nl === false ? 0 : $nl + 1;
            if (trim(substr($text, $lineStart, $from - $lineStart), " \t") === '') {
                $from = $lineStart;
            }
            $to = $e['comma'] !== null ? $end($e['comma']) : $end($e['end']);
            if (preg_match('/\G[ \t]*(?:(?:\/\/|#)[^\n]*)?\n/', $text, $m, 0, $to) === 1 && $from === $lineStart) {
                $to += strlen($m[0]);
            }
            $text = substr($text, 0, $from) . substr($text, $to);
        }

        return $text;
    }

    /** The next token that is not space, a comment or the open tag. @param list<\PhpToken> $tokens */
    private static function next(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            if (!$tokens[$j]->isIgnorable()) {
                return $j;
            }
        }
        return null;
    }

    /** A key written as a plain quoted string, as its text; null for any other. */
    private static function keyOf(\PhpToken $t): ?string
    {
        if ($t->id !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $inner = substr($t->text, 1, -1);

        return $t->text[0] === "'" ? strtr($inner, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($inner);
    }

    /** A value as PHP writes it, arrays in the short form on one line. */
    private static function literal(mixed $value): string
    {
        if (is_array($value)) {
            $list  = array_is_list($value);
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = ($list ? '' : var_export($k, true) . ' => ') . self::literal($v);
            }
            return '[' . implode(', ', $parts) . ']';
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default         => var_export($value, true),
        };
    }

    /** Text, numbers, true, false, null and arrays of them; nothing else is a setting. */
    private static function checkValue(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                self::checkValue($v);
            }
            return;
        }
        if ($value !== null && !is_scalar($value)) {
            throw new RuntimeException('A setting is text, a number, true, false or an array of those.');
        }
        if (is_float($value) && !is_finite($value)) {
            throw new RuntimeException('A setting cannot be an infinite number.');
        }
    }

    /** The file as an operator knows it. */
    private static function name(string $path): string
    {
        return str_ends_with($path, '/admin/config/config.php') ? 'admin/config/config.php' : $path;
    }
}
