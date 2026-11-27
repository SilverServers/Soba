<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\ContentType\FieldRenderer;
use Cms\Database\Database;
use Cms\Models\Option;
use PDO;

/**
 * THE VALUES ON A SITE'S SETTINGS PAGES (`Site\SettingsPages`), read by its
 * templates.
 *
 * ONE OPTION PER FIELD, `settings.{page}.{key}`, holding what the field holds
 * on an entry (`FieldRenderer::sanitizeField()`): text as typed, a picture's
 * id, a repeater's rows as JSON. A second language keeps its own value beside
 * each, `settings.{page}.{key}@{tag}`, except a tick box's, which is the same
 * in both: it has no empty state to fall back from (`inSecondLanguage()`). An
 * empty value, an empty list included, is no row, so a field left empty is the
 * template's own default, and one left empty in the second language reads the
 * site's own.
 *
 *     \Cms\Site\Settings::text('site-text', 'motto', 'Our motto')    plain text, to escape at output
 *     \Cms\Site\Settings::image('site-text', 'banner')               what Media::image() gives, or null
 *     \Cms\Site\Settings::rows('site-text', 'prices')                a repeater's rows
 *     \Cms\Site\Settings::gallery('site-text', 'photos')             a gallery's pictures (Media::gallery())
 *     \Cms\Site\Settings::get('site-text', 'intro', '')              the value as stored (rich text is clean HTML)
 *
 * Read at render, in the language the page is drawn in, so a static copy
 * needs nothing. Every value is read in one query a request.
 */
final class Settings
{
    /** The prefix of every value's option key. */
    public const PREFIX = 'settings.';

    /** @var array<string, string>|null every stored value, read once a request */
    private static ?array $values = null;

    /** A field's option key, in a language ('' the site's own). */
    public static function optionKey(string $page, string $key, string $lang = ''): string
    {
        return self::PREFIX . $page . '.' . $key . ($lang !== '' ? '@' . $lang : '');
    }

    /** The value stored in one language ('' the site's own), or null. */
    public static function stored(string $page, string $key, string $lang = ''): ?string
    {
        self::read();

        return self::$values[self::optionKey($page, $key, $lang)] ?? null;
    }

    /**
     * A field's value as the page being drawn reads it: in the second
     * language, its value there, else the site's own; `$default` when neither
     * holds one.
     */
    public static function get(string $page, string $key, mixed $default = null): mixed
    {
        $lang  = Language::drawn();
        $value = $lang !== '' ? self::stored($page, $key, $lang) : null;
        if (self::isEmpty($value)) {
            $value = self::stored($page, $key);
        }

        return self::isEmpty($value) ? $default : $value;
    }

    /**
     * Does the second language keep its own value of this field? Every field
     * but a tick box: unticked is a value, so a box drawn there would turn off
     * in that language whatever the site's own says the first time the page
     * is saved in it.
     *
     * @param array<string, mixed> $field
     */
    public static function inSecondLanguage(array $field): bool
    {
        return ($field['type'] ?? 'text') !== 'checkbox';
    }

    /** A text field's words, as typed: escape them where they are printed. */
    public static function text(string $page, string $key, string $default = ''): string
    {
        return FieldRenderer::plain(self::get($page, $key, $default));
    }

    /**
     * A picture field's picture, as `Media::image()` gives it, or null.
     *
     * @return array{url: string, width: ?int, height: ?int, alt: string, variants: array<int, string>}|null
     */
    public static function image(string $page, string $key): ?array
    {
        return \Cms\Models\Media::image(self::get($page, $key));
    }

    /**
     * A gallery field's pictures, each what `Media::image()` gives with its id
     * and caption, in order (`Media::gallery()`).
     *
     * @return list<array<string, mixed>>
     */
    public static function gallery(string $page, string $key): array
    {
        return \Cms\Models\Media::gallery(self::get($page, $key, '[]'));
    }

    /**
     * A repeater's rows, each its sub-fields' values by key.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(string $page, string $key): array
    {
        $rows = json_decode((string) self::get($page, $key, '[]'), true);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Why the posted fields cannot be kept, or []: each field is checked as an
     * entry's is (`FieldRenderer::validateField()`), except one its logic hides.
     * In the second language an empty field reads the site's own value, so
     * none is required there.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $post
     * @return list<string>
     */
    public static function problems(array $fields, array $post, bool $second): array
    {
        $clean = [];
        foreach ($fields as $field) {
            $clean[(string) $field['key']] = FieldRenderer::sanitizeField($field, $post);
        }
        $errors = [];
        foreach ($fields as $field) {
            if (!\Cms\Forms\Logic::isVisible(\Cms\Forms\Logic::parse($field['logic'] ?? null), $clean)) {
                continue;
            }
            $value = $clean[(string) $field['key']];
            if ($second && self::isEmpty($value, $field)) {
                continue;
            }
            $error = FieldRenderer::validateField($field, $value);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * Keep a page's posted fields in one language, each as an entry's field
     * keeps it; an empty one is no row, and in the second language a tick box
     * is not kept at all.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $post
     */
    public static function save(string $page, array $fields, array $post, string $lang = ''): void
    {
        foreach ($fields as $field) {
            if ($lang !== '' && !self::inSecondLanguage($field)) {
                continue;
            }
            $value  = FieldRenderer::sanitizeField($field, $post);
            $option = self::optionKey($page, (string) $field['key'], $lang);
            if (self::isEmpty($value, $field)) {
                Option::delete($option);
            } else {
                Option::set($option, is_array($value) || is_object($value) ? (string) json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value);
            }
        }
        self::$values = null;
    }

    /** Forget the values read this request: after a save, and in tests. */
    public static function reset(): void
    {
        self::$values = null;
    }

    /**
     * Nothing in it: no value, no words, an empty list, or, for a picture
     * field, no picture, which its control posts as 0.
     *
     * @param array<string, mixed> $field
     */
    private static function isEmpty(mixed $value, array $field = []): bool
    {
        if ($value === null || $value === '' || $value === [] || $value === '[]') {
            return true;
        }

        return ($field['type'] ?? '') === 'image' && is_scalar($value) && (int) $value <= 0;
    }

    private static function read(): void
    {
        if (self::$values !== null) {
            return;
        }
        $stmt = Database::getInstance()->prepare('SELECT option_key, option_value FROM options WHERE option_key LIKE :prefix');
        $stmt->execute([':prefix' => self::PREFIX . '%']);
        self::$values = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            self::$values[(string) $row['option_key']] = (string) $row['option_value'];
        }
    }
}
