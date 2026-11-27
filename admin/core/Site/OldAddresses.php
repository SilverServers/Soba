<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Database\Database;
use Cms\Models\Option;
use Cms\Models\Revision;
use PDO;

/**
 * THE ADDRESSES THIS SITE HAS RUN ON, and what still names an old one.
 *
 * A site built at a preview address and moved to its own keeps the preview's
 * address wherever someone pasted a link: a page's text, a menu item, a
 * redirect, a form's thank-you address. Nothing breaks while the preview
 * still answers, and every such link breaks the day it is taken down. So
 * the address config.php gives the site (`base_url`) is noted each time it
 * changes (`note()`, at a sign-in, on System health, and from cron), and in
 * production System health lists everything that still names an earlier one
 * (`found()`), with a button that replaces each with the site's own
 * (`replace()`).
 *
 * WHAT IS LOOKED IN: settings (`options`), pages (text, search title and
 * description), entries (text and summary), entries' fields (`post_meta`),
 * menu items, forms, redirects and terms' descriptions. A JSON value keeps
 * its slashes escaped (`https:\/\/…`), so each address is looked for in both
 * forms. An address counts only where it ends there, so the old
 * `https://example.com` is not found in `https://example.com.au` or
 * `https://example.com:8080`.
 *
 * REPLACED ROW BY ROW, IN PHP, with the same boundary the search uses, and
 * a page or an entry gets a revision first (`Revision::snapshot()`), so a
 * replacement can be undone from its history as any other change can.
 */
final class OldAddresses
{
    /** The addresses noted, oldest first, as a JSON list. */
    public const OPTION = 'base_urls_seen';

    /** How many are kept. */
    private const KEEP = 10;

    /**
     * Where an address is looked for: a table, its id column, the columns,
     * and what one of its rows is called.
     *
     * @var list<array{table: string, id: string, columns: list<string>, one: string, many: string}>
     */
    private const PLACES = [
        ['table' => 'options',    'id' => 'option_key', 'columns' => ['option_value'], 'one' => 'setting', 'many' => 'settings'],
        ['table' => 'pages',      'id' => 'id', 'columns' => ['content', 'seo_title', 'meta_description'], 'one' => 'page', 'many' => 'pages'],
        ['table' => 'posts',      'id' => 'id', 'columns' => ['content', 'excerpt'], 'one' => 'entry', 'many' => 'entries'],
        ['table' => 'post_meta',  'id' => 'id', 'columns' => ['meta_value'], 'one' => "entry's field", 'many' => "entries' fields"],
        ['table' => 'menu_items', 'id' => 'id', 'columns' => ['url', 'description', 'settings'], 'one' => 'menu item', 'many' => 'menu items'],
        ['table' => 'forms',      'id' => 'id', 'columns' => ['fields', 'settings'], 'one' => 'form', 'many' => 'forms'],
        ['table' => 'redirects',  'id' => 'id', 'columns' => ['to_url'], 'one' => 'redirect', 'many' => 'redirects'],
        ['table' => 'terms',      'id' => 'id', 'columns' => ['description'], 'one' => "term's description", 'many' => "terms' descriptions"],
    ];

    /** The site's own address now, as config.php gives it, without its trailing slash. */
    public static function current(): string
    {
        return defined('BASE_URL') ? self::shape((string) BASE_URL) : '';
    }

    /**
     * Every address noted, oldest first.
     *
     * @return list<string>
     */
    public static function seen(): array
    {
        $list = json_decode((string) Option::get(self::OPTION, '[]'), true);

        return is_array($list) ? array_values(array_filter(array_map(
            static fn (mixed $a): string => is_string($a) ? self::shape($a) : '', $list), 'strlen')) : [];
    }

    /** The addresses noted before this one, newest first. @return list<string> */
    public static function old(): array
    {
        $here = self::current();

        return array_values(array_filter(array_reverse(self::seen()), static fn (string $a): bool => $a !== $here));
    }

    /**
     * Note the site's own address, when it is not the last one noted: one
     * read, and a write only when the site has moved.
     */
    public static function note(): void
    {
        $here = self::current();
        if ($here === '' || !preg_match('~^https?://~i', $here)) {
            return;
        }
        try {
            $seen = self::seen();
            if (end($seen) === $here) {
                return;
            }
            $seen   = array_values(array_filter($seen, static fn (string $a): bool => $a !== $here));
            $seen[] = $here;
            Option::set(self::OPTION, (string) json_encode(array_slice($seen, -self::KEEP), JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            error_log('CMS: the site\'s address could not be noted: ' . $e->getMessage());
        }
    }

    /**
     * What still names an old address: for each, by place, how many rows.
     *
     * @return array<string, array<string, int>> old address => [what (plural) => count]
     */
    public static function found(): array
    {
        $out = [];
        foreach (self::old() as $old) {
            foreach (self::PLACES as $place) {
                $n = count(self::rows($place, $old));
                if ($n > 0) {
                    $out[$old][$n === 1 ? '1 ' . $place['one'] : $n . ' ' . $place['many']] = $n;
                }
            }
        }

        return $out;
    }

    /**
     * Replace every old address with the site's own, everywhere `found()`
     * looks, a page's or an entry's revision taken first. How many rows
     * changed.
     */
    public static function replace(?int $by = null): int
    {
        $here = self::current();
        if ($here === '') {
            return 0;
        }
        $pdo     = Database::getInstance();
        $changed = 0;
        $kept    = [];   // each page and entry gets one revision, however many of its places change
        foreach (self::old() as $old) {
            foreach (self::PLACES as $place) {
                foreach (self::rows($place, $old) as $row) {
                    $sets = [];
                    foreach ($place['columns'] as $column) {
                        $value = $row[$column] ?? null;
                        if (!is_string($value)) {
                            continue;
                        }
                        $new = self::swap($value, $old, $here);
                        if ($new !== $value) {
                            $sets[$column] = $new;
                        }
                    }
                    if ($sets === []) {
                        continue;
                    }
                    $owner = match ($place['table']) {
                        'pages'     => [Revision::TYPE_PAGE, (int) $row['id']],
                        'posts'     => [Revision::TYPE_POST, (int) $row['id']],
                        'post_meta' => [Revision::TYPE_POST, (int) $row['post_id']],
                        default     => null,
                    };
                    if ($owner !== null && !isset($kept[$owner[0] . ':' . $owner[1]])) {
                        Revision::snapshot($owner[0], $owner[1], $by, 'manual', 'Before ' . $old . ' was replaced with ' . $here);
                        $kept[$owner[0] . ':' . $owner[1]] = true;
                    }
                    // Column names come from PLACES, never from a row.
                    $assign = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($sets)));
                    $params = [':row_id' => $row[$place['id']]];
                    foreach ($sets as $column => $value) {
                        $params[':' . $column] = $value;
                    }
                    $pdo->prepare('UPDATE ' . $place['table'] . ' SET ' . $assign . ' WHERE ' . $place['id'] . ' = :row_id')->execute($params);
                    $changed++;
                }
            }
        }

        return $changed;
    }

    /**
     * The rows of a place that name `$old`, where it ends: each with its id
     * and the columns looked in.
     *
     * @param array{table: string, id: string, columns: list<string>} $place
     * @return list<array<string, mixed>>
     */
    private static function rows(array $place, string $old): array
    {
        $escaped = str_replace('/', '\\/', $old);
        $where   = [];
        $params  = [];
        foreach ($place['columns'] as $i => $column) {
            $where[]           = "{$column} LIKE :p{$i} OR {$column} LIKE :e{$i}";
            $params[':p' . $i] = '%' . addcslashes($old, '%_\\') . '%';
            $params[':e' . $i] = '%' . addcslashes($escaped, '%_\\') . '%';
        }
        $select = array_unique(array_merge([$place['id']], $place['table'] === 'post_meta' ? ['post_id'] : [], $place['columns']));
        $sql    = 'SELECT ' . implode(', ', $select) . ' FROM ' . $place['table'] . ' WHERE (' . implode(' OR ', $where) . ')';
        if ($place['table'] === 'options') {
            // The list of addresses is not a link to one.
            $sql             .= ' AND option_key <> :own';
            $params[':own'] = self::OPTION;
        }
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);

        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static function (array $row) use ($place, $old): bool {
            foreach ($place['columns'] as $column) {
                if (is_string($row[$column] ?? null) && self::swap($row[$column], $old, 'x') !== $row[$column]) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * `$text` with `$old` replaced by `$new` wherever it ends there, as it is
     * and with its slashes escaped as JSON keeps them.
     */
    private static function swap(string $text, string $old, string $new): string
    {
        foreach ([[$old, $new], [str_replace('/', '\\/', $old), str_replace('/', '\\/', $new)]] as [$from, $to]) {
            $text = (string) preg_replace('~' . preg_quote($from, '~') . '(?![A-Za-z0-9.:_-])~i', str_replace(['\\', '$'], ['\\\\', '\\$'], $to), $text);
        }

        return $text;
    }

    /** An address as noted: trimmed, no trailing slash. */
    private static function shape(string $address): string
    {
        return rtrim(trim($address), '/');
    }
}
