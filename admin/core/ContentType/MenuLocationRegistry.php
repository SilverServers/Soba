<?php

declare(strict_types=1);

namespace Cms\ContentType;

/**
 * MenuLocationRegistry — stores registered menu locations.
 *
 * Developers call MenuLocationRegistry::register() in config/menus.php.
 * The admin menu builder reads from here to populate the location dropdown.
 * The public MenuRenderer uses findByLocation() to output menus.
 */
class MenuLocationRegistry
{
    /** @var array<string, array{label: string}> */
    private static array $locations = [];

    public static function register(string $slug, string $label): void
    {
        self::$locations[$slug] = ['label' => $label];
    }

    /**
     * @return array<string, array{label: string}>
     */
    public static function all(): array
    {
        return self::$locations;
    }

    public static function get(string $slug): ?array
    {
        return self::$locations[$slug] ?? null;
    }
}
