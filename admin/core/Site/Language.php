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
 */
final class Language
{
    public const OPTION = 'site_language';

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

    /** The site's language tag: the one chosen, or English when none is or the one stored is not offered. */
    public static function current(): string
    {
        $tag = (string) Option::get(self::OPTION, self::DEFAULT);

        return isset(self::ALL[$tag]) ? $tag : self::DEFAULT;
    }
}
