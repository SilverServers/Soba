<?php

declare(strict_types=1);

namespace Cms\ContentType;

/**
 * MenuLocationRegistry — stores registered menu locations.
 *
 * Developers call MenuLocationRegistry::register() in config/menus.php.
 * The admin menu builder reads from here to populate the location dropdown.
 * The public MenuRenderer uses findByLocation() to output menus.
 *
 * A SITE WITH A SECOND LANGUAGE (`Site\Language`) has each location twice:
 * the location itself, and its twin for the second language (`primary--fr`,
 * "Primary Navigation (French)"), with a menu and settings of its own. A page
 * drawn in the second language shows the twin's menu (`inLanguage()`).
 */
class MenuLocationRegistry
{
    /** Between a location and the second language's prefix, in its twin's slug. */
    public const TWIN = '--';

    /** @var array<string, array{label: string}> */
    private static array $locations = [];

    public static function register(string $slug, string $label): void
    {
        self::$locations[$slug] = ['label' => $label];
    }

    /**
     * Every location, each followed by its twin for the second language when
     * the site has one.
     *
     * @return array<string, array{label: string}>
     */
    public static function all(): array
    {
        $second = \Cms\Site\Language::prefix();
        if ($second === '') {
            return self::$locations;
        }
        $name = \Cms\Site\Language::nameOf($second);
        $all  = [];
        foreach (self::$locations as $slug => $location) {
            $all[$slug] = $location;
            $all[self::inLanguage((string) $slug, $second)] = ['label' => $location['label'] . ' (' . $name . ')'];
        }

        return $all;
    }

    /**
     * THE LOCATION A PAGE IN `$lang` SHOWS FOR `$slug`: the location itself
     * in the site's own language (''), its twin in the second (`primary--fr`).
     */
    public static function inLanguage(string $slug, string $lang): string
    {
        return $lang === '' ? $slug : $slug . self::TWIN . $lang;
    }

    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }
}
