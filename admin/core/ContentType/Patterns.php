<?php

declare(strict_types=1);

namespace Cms\ContentType;

use Cms\SEO\Head;

/**
 * TITLES AND ADDRESSES MADE FROM FIELDS — a type whose entries are always
 * titled the same way, a weekly report named for its place and its date,
 * says so once:
 *
 *     'title_pattern' => '{tax:location} report, {report_date:F j, Y}',
 *     'slug_pattern'  => '{id}-{tax:location}-report-{report_date}',
 *
 * in code, or on the Content Types screen (migration 146). An entry whose
 * title is left blank is titled by the pattern, and one whose address is not
 * typed is addressed by it; the title box shows what the pattern makes.
 *
 * THE TOKEN FORM SEARCH TITLES USE (`SEO\Head::buildTitle()`), with what an
 * entry adds to it:
 *
 *   {field}          a field's value: its words, a choice's label, a date as kept
 *   {field:format}   a date or a time in a format of PHP's, `F j, Y`
 *   {tax:taxonomy}   the entry's terms in a taxonomy, by name
 *   {id}             the entry's number, known once it is first saved
 *   {title}          its title, in an address's pattern
 *
 * and the site's own, `{sep}`, `{site_name}` and the rest, as a search title
 * has them. A token that names nothing the type has is left out and said in
 * the log, so a typo never fails silently.
 */
final class Patterns
{
    /** A token: a name, and what follows its colon (a format, or a taxonomy). */
    private const TOKEN = '/\{([a-z][a-z0-9_]*)(?::([^{}]*))?\}/';

    /** The field types whose value is words a title can carry. */
    private const WORDS = ['text', 'textarea', 'number', 'date', 'datetime', 'select', 'checkbox'];

    /** The type's title pattern, or '' for none. */
    public static function titlePattern(string $typeKey): string
    {
        return trim((string) (Registry::get($typeKey)['title_pattern'] ?? ''));
    }

    /** The type's address pattern, or '' for none. */
    public static function slugPattern(string $typeKey): string
    {
        return trim((string) (Registry::get($typeKey)['slug_pattern'] ?? ''));
    }

    /**
     * A pattern filled from one entry: its fields as kept (`$values`, by key),
     * its terms (`$terms`, ids by taxonomy) and its id when it has one. ''
     * when nothing in it had a value.
     *
     * @param array<string, mixed>     $values
     * @param array<string, list<int>> $terms
     */
    public static function fill(string $pattern, string $typeKey, array $values, array $terms, ?int $id, string $title = ''): string
    {
        if (trim($pattern) === '') {
            return '';
        }
        $fields = self::fields($typeKey);
        // WHETHER THE ENTRY GAVE IT ANYTHING: a pattern naming the entry's own
        // fields, none of which has a value yet, makes nothing, rather than a
        // title of its bare words ("report,").
        $named  = 0;
        $given  = 0;
        $own    = (string) preg_replace_callback(self::TOKEN, static function (array $m) use ($typeKey, $fields, $values, $terms, $id, $title, $pattern, &$named, &$given): string {
            $name  = $m[1];
            $after = $m[2] ?? '';
            $made  = null;
            if ($name === 'id') {
                $made = $id !== null && $id > 0 ? (string) $id : '';
            } elseif ($name === 'title' && !isset($fields['title'])) {
                $made = $title;
            } elseif ($name === 'tax') {
                if (TaxonomyRegistry::get($after) === null || !isset(TaxonomyRegistry::getForContentType($typeKey)[$after])) {
                    self::unknown($typeKey, $pattern, $m[0]);
                    return '';
                }
                $made = self::termNames($after, $terms[$after] ?? []);
            } elseif (isset($fields[$name])) {
                $made = self::words($fields[$name], $values[$name] ?? null, $after);
            }
            if ($made !== null) {
                $named++;
                $given += $made !== '' ? 1 : 0;
                return $made;
            }
            // THE SITE'S OWN, for `Head::buildTitle()` below; anything else is nothing.
            if ($after === '' && array_key_exists($name, self::site())) {
                return $m[0];
            }
            self::unknown($typeKey, $pattern, $m[0]);
            return '';
        }, $pattern);

        return $named > 0 && $given === 0 ? '' : Head::buildTitle($own, self::site());
    }

    /**
     * THE PATTERN AS WORDS, for a title box with nothing filled in yet: each
     * token its field's or taxonomy's label, so "{tax:location} report,
     * {report_date:F j, Y}" reads "Location report, Report date".
     */
    public static function hint(string $pattern, string $typeKey): string
    {
        $fields = self::fields($typeKey);
        $words  = (string) preg_replace_callback(self::TOKEN, static function (array $m) use ($fields, $typeKey): string {
            if ($m[1] === 'id') {
                return 'Number';
            }
            if ($m[1] === 'tax') {
                $tax = TaxonomyRegistry::get($m[2] ?? '');
                return $tax !== null && isset(TaxonomyRegistry::getForContentType($typeKey)[$m[2]]) ? (string) ($tax['label_single'] ?? $tax['label'] ?? '') : '';
            }
            if (isset($fields[$m[1]])) {
                return (string) ($fields[$m[1]]['label'] ?? $m[1]);
            }
            return $m[1] === 'title' ? 'Title' : $m[0];
        }, $pattern);

        return Head::buildTitle($words, Head::globalTokens());
    }

    /**
     * What is wrong with a pattern typed on the Content Types screen, or null:
     * a token that names nothing the type has.
     */
    public static function problem(string $pattern, string $typeKey, string $what): ?string
    {
        $fields = self::fields($typeKey);
        preg_match_all(self::TOKEN, $pattern, $all, PREG_SET_ORDER);
        foreach ($all as $m) {
            $name  = $m[1];
            $after = $m[2] ?? '';
            $known = $name === 'id' || ($name === 'title' && $after === '')
                || ($name === 'tax' && isset(TaxonomyRegistry::getForContentType($typeKey)[$after]))
                || isset($fields[$name])
                || ($after === '' && array_key_exists($name, self::site()));
            if (!$known) {
                return 'The ' . $what . ' names ' . $m[0] . ', which is not one of this type\'s fields or taxonomies.';
            }
        }

        return null;
    }

    /**
     * What a pattern may name on this type, for the Content Types screen: each
     * field whose value is words and each taxonomy. Its number, `{id}`, and
     * the site's own are said beside them.
     *
     * @return list<string>
     */
    public static function tokens(string $typeKey): array
    {
        $out = [];
        foreach (self::fields($typeKey) as $key => $field) {
            $out[] = '{' . $key . '}';
        }
        foreach (array_keys(TaxonomyRegistry::getForContentType($typeKey)) as $taxKey) {
            $out[] = '{tax:' . $taxKey . '}';
        }

        return $out;
    }

    /** The type's first date or time field, for an example of a format, or null. */
    public static function dateField(string $typeKey): ?string
    {
        foreach (self::fields($typeKey) as $key => $field) {
            if (in_array((string) ($field['type'] ?? ''), ['date', 'datetime'], true)) {
                return (string) $key;
            }
        }

        return null;
    }

    /** Does the pattern name the entry's number, which it has only once it is saved? */
    public static function needsId(string $pattern): bool
    {
        return preg_match('/\{id\}/', $pattern) === 1;
    }

    /** @var array<string, string>|null the site's own tokens, once a request */
    private static ?array $site = null;

    /** @var array<string, array<string, array<string, mixed>>> each type's fields, once a request */
    private static array $fieldsOf = [];

    /**
     * The site's own tokens (`Head::globalTokens()`), read once a request: a
     * list of a hundred parents fills a pattern for each.
     *
     * @return array<string, string>
     */
    private static function site(): array
    {
        return self::$site ??= Head::globalTokens();
    }

    /**
     * The type's fields whose value is words, by key.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fields(string $typeKey): array
    {
        if (isset(self::$fieldsOf[$typeKey])) {
            return self::$fieldsOf[$typeKey];
        }
        $out = [];
        foreach (ContentTypeSchema::fields($typeKey) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key !== '' && in_array((string) ($field['type'] ?? 'text'), self::WORDS, true)) {
                $out[$key] = $field;
            }
        }

        return self::$fieldsOf[$typeKey] = $out;
    }

    /**
     * A field's value as words: a choice's label, a tick its field's label, a
     * date or a time in the format given, else as kept.
     *
     * @param array<string, mixed> $field
     */
    private static function words(array $field, mixed $value, string $format): string
    {
        $text = is_scalar($value) ? trim(FieldRenderer::plain((string) $value)) : '';
        if ($text === '') {
            return '';
        }

        return match ((string) ($field['type'] ?? 'text')) {
            'select'           => (string) (((array) ($field['options'] ?? []))[$text] ?? $text),
            'checkbox'         => $text === '1' ? (string) ($field['label'] ?? '') : '',
            'date', 'datetime' => $format !== '' ? self::date($text, $format) : $text,
            default            => preg_replace('/\s+/u', ' ', $text) ?? $text,
        };
    }

    /** A date as kept (`2026-11-26`, `2026-11-26T09:30`), in a format of PHP's, in the site's time. */
    private static function date(string $kept, string $format): string
    {
        try {
            $at = new \DateTimeImmutable($kept, \Cms\Helpers\Dates::zone());
        } catch (\Exception) {
            return $kept;
        }

        return $at->format($format);
    }

    /** @param list<int> $ids */
    private static function termNames(string $taxKey, array $ids): string
    {
        $names = [];
        foreach ($ids as $id) {
            $term = \Cms\Models\Term::find((int) $id);
            if ($term !== null && (string) $term['taxonomy_key'] === $taxKey) {
                $names[] = (string) $term['name'];
            }
        }

        return implode(', ', $names);
    }

    /** @var array<string, true> what has been said this request */
    private static array $said = [];

    private static function unknown(string $typeKey, string $pattern, string $token): void
    {
        if (isset(self::$said[$typeKey . $token])) {
            return;
        }
        self::$said[$typeKey . $token] = true;
        error_log('Content types: "' . $typeKey . '" names ' . $token . ' in the pattern "' . $pattern . '", which is not one of its fields or taxonomies; left out');
    }
}
