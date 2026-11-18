<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Models\Option;

/**
 * THE LANGUAGE THE SITE IS WRITTEN IN (Settings → General → Site language),
 * said on every public page as `<html lang>`.
 *
 * Every page said `lang="en"`, so a French site was read aloud by a screen
 * reader with English sounds, hyphenated as English and offered for
 * translation from English. The owner says which language their pages are
 * written in; the admin's own words stay English.
 *
 * The tags are BCP 47's (`en`, `fr-CA`, `zh-Hant`). A language written right
 * to left is not offered: the theme is laid out left to right, and a page
 * marked right to left in it would read worse, not better.
 *
 * A SECOND LANGUAGE (Settings → General → Second language) gives a page or
 * an entry a linked translation at the language's own addresses, under its
 * prefix (/fr, /fr/a-propos, /fr/news/…). The shop, forms, archive settings
 * and the admin stay in the site's own language.
 */
final class Language
{
    public const OPTION = 'site_language';

    /** The second language's tag ('' none): Settings → General. */
    public const SECOND = 'site_language_second';

    public const DEFAULT = 'en';

    /** @var array<string, string> tag => the language's name in English, for the select */
    public const ALL = [
        'en'      => 'English',
        'en-CA'   => 'English (Canada)',
        'en-US'   => 'English (United States)',
        'en-GB'   => 'English (United Kingdom)',
        'en-AU'   => 'English (Australia)',
        'fr'      => 'French',
        'fr-CA'   => 'French (Canada)',
        'es'      => 'Spanish',
        'es-MX'   => 'Spanish (Mexico)',
        'pt'      => 'Portuguese',
        'pt-BR'   => 'Portuguese (Brazil)',
        'de'      => 'German',
        'it'      => 'Italian',
        'nl'      => 'Dutch',
        'sv'      => 'Swedish',
        'nb'      => 'Norwegian (Bokmål)',
        'da'      => 'Danish',
        'fi'      => 'Finnish',
        'is'      => 'Icelandic',
        'pl'      => 'Polish',
        'cs'      => 'Czech',
        'sk'      => 'Slovak',
        'hu'      => 'Hungarian',
        'ro'      => 'Romanian',
        'hr'      => 'Croatian',
        'sr'      => 'Serbian',
        'uk'      => 'Ukrainian',
        'ru'      => 'Russian',
        'el'      => 'Greek',
        'tr'      => 'Turkish',
        'zh-Hans' => 'Chinese (Simplified)',
        'zh-Hant' => 'Chinese (Traditional)',
        'ja'      => 'Japanese',
        'ko'      => 'Korean',
        'vi'      => 'Vietnamese',
        'th'      => 'Thai',
        'id'      => 'Indonesian',
        'ms'      => 'Malay',
        'fil'     => 'Filipino',
        'hi'      => 'Hindi',
        'pa'      => 'Punjabi',
        'gu'      => 'Gujarati',
        'ta'      => 'Tamil',
        'sw'      => 'Swahili',
        'iu'      => 'Inuktitut',
        'cr'      => 'Cree',
        'oj'      => 'Ojibwe',
    ];

    /**
     * EACH LANGUAGE'S NAME IN ITSELF, for the link a page in the other
     * language offers (`other()`): a reader looking for their language looks
     * for it as they write it.
     *
     * @var array<string, string>
     */
    public const NATIVE = [
        'en'      => 'English',
        'en-CA'   => 'English',
        'en-US'   => 'English',
        'en-GB'   => 'English',
        'en-AU'   => 'English',
        'fr'      => 'Français',
        'fr-CA'   => 'Français',
        'es'      => 'Español',
        'es-MX'   => 'Español',
        'pt'      => 'Português',
        'pt-BR'   => 'Português',
        'de'      => 'Deutsch',
        'it'      => 'Italiano',
        'nl'      => 'Nederlands',
        'sv'      => 'Svenska',
        'nb'      => 'Norsk bokmål',
        'da'      => 'Dansk',
        'fi'      => 'Suomi',
        'is'      => 'Íslenska',
        'pl'      => 'Polski',
        'cs'      => 'Čeština',
        'sk'      => 'Slovenčina',
        'hu'      => 'Magyar',
        'ro'      => 'Română',
        'hr'      => 'Hrvatski',
        'sr'      => 'Српски',
        'uk'      => 'Українська',
        'ru'      => 'Русский',
        'el'      => 'Ελληνικά',
        'tr'      => 'Türkçe',
        'zh-Hans' => '简体中文',
        'zh-Hant' => '繁體中文',
        'ja'      => '日本語',
        'ko'      => '한국어',
        'vi'      => 'Tiếng Việt',
        'th'      => 'ไทย',
        'id'      => 'Bahasa Indonesia',
        'ms'      => 'Bahasa Melayu',
        'fil'     => 'Filipino',
        'hi'      => 'हिन्दी',
        'pa'      => 'ਪੰਜਾਬੀ',
        'gu'      => 'ગુજરાતી',
        'ta'      => 'தமிழ்',
        'sw'      => 'Kiswahili',
        'iu'      => 'ᐃᓄᒃᑎᑐᑦ',
        'cr'      => 'ᓀᐦᐃᔭᐍᐏᐣ',
        'oj'      => 'Anishinaabemowin',
    ];

    /** The site's own language tag: the one chosen, or English when none is or the one stored is not offered. */
    public static function own(): string
    {
        $tag = (string) Option::get(self::OPTION, self::DEFAULT);

        return isset(self::ALL[$tag]) ? $tag : self::DEFAULT;
    }

    /**
     * THE LANGUAGE OF THE PAGE BEING DRAWN, as a tag, for `<html lang>` and a
     * feed's `<language>`: the second language's on a page drawn in it
     * (`draw()`), else the site's own.
     */
    public static function current(): string
    {
        return self::$drawn !== '' && self::second() !== '' ? self::second() : self::own();
    }

    /**
     * THE SECOND LANGUAGE's tag (`fr-CA`), or '' when the site has none: one
     * the select offers and not the site's own.
     */
    public static function second(): string
    {
        $tag = (string) Option::get(self::SECOND, '');

        return isset(self::ALL[$tag]) && $tag !== self::own() ? $tag : '';
    }

    /**
     * The second language as its addresses write it and its rows store it
     * (`fr`, `fr-ca`): its tag in lower case, '' when the site has none.
     */
    public static function prefix(): string
    {
        return strtolower(self::second());
    }

    /**
     * THE SITE'S OWN WORDS THAT HAVE A VERSION IN THE SECOND LANGUAGE
     * (Settings → General): each option => the option holding that version.
     */
    public const SECOND_WORDS = [
        'site_name'        => 'site_name_second',
        'site_tagline'     => 'site_tagline_second',
        'site_footer_text' => 'site_footer_text_second',
    ];

    /**
     * `$own`, one of the site's own words as stored under `$key`, in the
     * language of the page being drawn: its version in the second language
     * (`SECOND_WORDS`) on a page drawn in it, when it has one; else `$own`.
     */
    public static function words(string $key, string $own): string
    {
        if (self::$drawn === '' || !isset(self::SECOND_WORDS[$key]) || self::second() === '') {
            return $own;
        }
        $second = trim((string) (Option::get(self::SECOND_WORDS[$key], '') ?? ''));

        return $second !== '' ? $second : $own;
    }

    /**
     * ONE OF THE SITE'S OWN WORDS, read and put in the page's language
     * (`words()`): the site's title, tagline or footer text, for a whole-page
     * theme that prints them itself.
     */
    public static function option(string $key): string
    {
        return self::words($key, (string) (Option::get($key, '') ?? ''));
    }

    /** A row's language (`lang`) as a tag: '' is the site's own, a prefix the tag it was made from. */
    public static function tagOf(string $lang): string
    {
        if ($lang === '') {
            return self::own();
        }
        foreach (array_keys(self::ALL) as $tag) {
            if (strtolower($tag) === $lang) {
                return $tag;
            }
        }

        return $lang;
    }

    /**
     * A PAGE THAT IS IN BOTH LANGUAGES, as `<link rel="alternate">` names it:
     * each language's tag => its address there, the site's own first, from
     * `$at` (an address builder given '' or the second language's prefix).
     * Empty without a second language.
     *
     * @param callable(string): string $at
     * @return array<string, string>
     */
    public static function versions(callable $at): array
    {
        $second = self::prefix();
        if ($second === '') {
            return [];
        }

        return [self::own() => $at(''), self::second() => $at($second)];
    }

    /**
     * THE PAGE IN THE OTHER LANGUAGE, for a link to it in the header: that
     * language's tag, its name in itself (`NATIVE`), and this page's address
     * in it (`SEO\Head::alternates()`), or that language's front page when
     * this page has no version there. Null without a second language.
     *
     * @return array{tag: string, name: string, url: string}|null
     */
    public static function other(): ?array
    {
        $second = self::second();
        if ($second === '') {
            return null;
        }
        $tag  = self::$drawn !== '' ? self::own() : $second;
        $home = \Cms\Helpers\Url::home(self::$drawn !== '' ? '' : strtolower($second));

        return [
            'tag'  => $tag,
            'name' => self::NATIVE[$tag] ?? self::ALL[$tag] ?? $tag,
            'url'  => \Cms\SEO\Head::alternates()[$tag] ?? $home,
        ];
    }

    /** A row's language by its English name, for the admin: "French (Canada)". */
    public static function nameOf(string $lang): string
    {
        return self::ALL[self::tagOf($lang)] ?? $lang;
    }

    /**
     * How many pages and entries are in each language other than the site's
     * own: `lang => count`. A second language is not changed while another
     * one's translations exist (`problem()`).
     *
     * @return array<string, int>
     */
    public static function translations(): array
    {
        $out = [];
        $rows = \Cms\Database\Database::getInstance()->query(
            "SELECT lang, COUNT(*) AS n FROM (SELECT lang FROM pages WHERE lang <> ''
                                              UNION ALL SELECT lang FROM posts WHERE lang <> '') l
              GROUP BY lang ORDER BY lang"
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $out[(string) $row['lang']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * WHY `$tag` CANNOT BE THE SECOND LANGUAGE, or null when it can ('' is
     * none, and always can be: clearing it keeps the translations, unreached,
     * as a switched-off module's data is kept). Not the site's own language;
     * not while another language's translations exist; and not at an address
     * something else has: a page, a type or a taxonomy, one of the CMS's own,
     * the sign-in page's, or one of the site's own in the router layout.
     */
    public static function problem(string $tag): ?string
    {
        if ($tag === '') {
            return null;
        }
        if (!isset(self::ALL[$tag])) {
            return 'That is not a language this site offers.';
        }
        if ($tag === self::own()) {
            return 'The second language must be another language than the site\'s own.';
        }
        $prefix = strtolower($tag);
        foreach (self::translations() as $lang => $count) {
            if ($lang !== $prefix) {
                return 'There ' . ($count === 1 ? 'is 1 page or entry' : 'are ' . $count . ' pages and entries')
                    . ' in ' . self::nameOf($lang) . '. Delete ' . ($count === 1 ? 'it' : 'them')
                    . ' before choosing another second language.';
            }
        }
        if ($prefix === self::prefix()) {
            return null;
        }
        $at = '/' . $prefix;
        if (in_array($prefix, \Cms\Helpers\Slug::reservedFirstSegments(), true)
            || in_array($prefix, \Cms\Site\Front::siteAddresses(), true)) {
            return $at . ' is one of the site\'s own addresses, so the second language cannot be at it.';
        }
        foreach (\Cms\ContentType\Registry::all() as $type) {
            if (explode('/', trim((string) ($type['public_slug'] ?? ''), '/'))[0] === $prefix) {
                return 'The ' . strtolower((string) ($type['label'] ?? 'entries')) . ' are at ' . $at . '. Give them another address first.';
            }
        }
        foreach (\Cms\ContentType\TaxonomyRegistry::all() as $taxonomy) {
            if (explode('/', trim((string) ($taxonomy['public_slug'] ?? ''), '/'))[0] === $prefix) {
                return 'The ' . strtolower((string) ($taxonomy['label'] ?? 'terms')) . ' are at ' . $at . '. Give them another address first.';
            }
        }
        if (\Cms\Models\Page::slugExists($prefix, null, '')) {
            return 'A page is at ' . $at . '. Give it another address first.';
        }

        return null;
    }

    /**
     * A LANGUAGE AS AN ADDRESS WRITES IT, and as a row stores it (`lang`): ''
     * for the site's own, else a tag in lower case (`fr`, `fr-ca`, `zh-hant`).
     */
    public const PREFIX = '/^(?:[a-z]{2,3}(?:-[a-z0-9]{2,8})*)?$/';

    /** The language of the page being drawn: '' for the site's own (`draw()`). */
    private static string $drawn = '';

    /**
     * THE LANGUAGE OF THE PAGE BEING DRAWN, as an address writes it: '' for
     * the site's own language, whose pages have no prefix. What a page links
     * to that has no language of its own (an archive, a term's page, search)
     * is in this one (`Helpers\Url`).
     */
    public static function drawn(): string
    {
        return self::$drawn;
    }

    /** Is the page being drawn one a visitor reads (`draw()`), not the admin's? */
    private static bool $visiting = false;

    /**
     * The page being drawn is in this language ('' for the site's own), and
     * is one a visitor reads when `$visiting`: a public page's dates are
     * written in its language's words (`Words::dateFormat()`), and the
     * admin's in PHP's.
     */
    public static function draw(string $lang, bool $visiting = false): void
    {
        self::$drawn    = self::checked($lang);
        self::$visiting = $visiting;
    }

    /** Is the page being drawn one a visitor reads? */
    public static function visiting(): bool
    {
        return self::$visiting;
    }

    /**
     * `$lang` when it is a language as an address writes one (`PREFIX`, ''
     * included); anything else throws, so it is never stored, drawn or
     * written into a query.
     */
    public static function checked(string $lang): string
    {
        if (preg_match(self::PREFIX, $lang) !== 1) {
            throw new \InvalidArgumentException('Not a language as an address writes one: ' . $lang);
        }

        return $lang;
    }
}
