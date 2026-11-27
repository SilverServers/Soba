<?php

declare(strict_types=1);

namespace Cms\ContentType;

/**
 * COPY, AS A TYPE WANTS IT — what Copy (`ContentTypeController::handleCopy()`)
 * makes of an entry of this type, from its definition:
 *
 *     'copy' => ['suffix' => '', 'status' => 'same', 'skip' => ['legacy_id', 'legacy_*']]
 *
 * in code, or the Content Types screen's Copies (migration 147):
 *
 *   suffix  the words after a copy's title, "(copy)" unless the type says
 *           otherwise; nothing is allowed, and then the copy keeps its title
 *   status  'private', the default, since a published copy is on the site at
 *           once; or 'same', its original's
 *   skip    the fields a copy never takes, by key, `*` standing for any ending
 *           (`legacy_*`), such as an import's id for the original
 *
 * A copy's address is made from its title as a new entry's is, so a copy
 * keeping its title is at its original's address and `-2`.
 */
final class CopyRules
{
    public const SUFFIX = '(copy)';

    /**
     * The type's rules, each in its place.
     *
     * @return array{suffix: string, status: string, skip: list<string>}
     */
    public static function for(string $typeKey): array
    {
        $copy = Registry::get($typeKey)['copy'] ?? [];
        $copy = is_array($copy) ? $copy : [];

        return [
            'suffix' => array_key_exists('suffix', $copy) && is_scalar($copy['suffix']) ? trim((string) $copy['suffix']) : self::SUFFIX,
            'status' => ($copy['status'] ?? '') === 'same' ? 'same' : 'private',
            'skip'   => self::names($copy['skip'] ?? []),
        ];
    }

    /** A copy's title: its original's, and the suffix after a space, within the column's 500. */
    public static function title(string $original, string $suffix): string
    {
        if ($suffix === '') {
            return $original;
        }

        return rtrim(mb_substr($original, 0, max(0, 499 - mb_strlen($suffix)))) . ' ' . $suffix;
    }

    /**
     * Is a field never copied by these rules? A name ending in `*` covers
     * every key that begins with what comes before it.
     *
     * @param list<string> $skip
     */
    public static function skips(array $skip, string $key): bool
    {
        foreach ($skip as $name) {
            if ($name === $key || (str_ends_with($name, '*') && str_starts_with($key, substr($name, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Field names as a list: from code's list, or a line or a comma each, as
     * the Content Types screen takes them; each a key, or a key's start and `*`.
     *
     * @return list<string>
     */
    public static function names(mixed $given): array
    {
        $parts = is_array($given) ? $given : preg_split('/[\s,]+/', is_scalar($given) ? (string) $given : '');
        $out   = [];
        foreach ((array) $parts as $part) {
            $part = is_scalar($part) ? trim((string) $part) : '';
            if ($part !== '' && preg_match('/^[a-z0-9_]+\*?$/', $part) === 1 && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }

        return $out;
    }
}
