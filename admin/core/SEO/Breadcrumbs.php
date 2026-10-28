<?php

declare(strict_types=1);

namespace Cms\SEO;

/**
 * Breadcrumbs — auto-generated breadcrumb trail for public pages.
 *
 * Set by the public controllers (`set()`), output in templates with `render()`.
 * Includes BreadcrumbList JSON-LD schema automatically.
 */
class Breadcrumbs
{
    /** @var array<int, array{label: string, url: string}> */
    private static array $items = [];

    /**
     * Set the breadcrumb trail for the current page.
     *
     * @param array<int, array{label: string, url: string}> $items
     */
    public static function set(array $items): void
    {
        self::$items = $items;
    }

    /**
     * Get the current breadcrumb items.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public static function get(): array
    {
        return self::$items;
    }

    /**
     * Render breadcrumbs as HTML.
     *
     * @param string $separator  The separator character between items
     */
    public static function render(string $separator = ' / '): string
    {
        if (empty(self::$items)) {
            return '';
        }

        $parts = [];
        $last  = count(self::$items) - 1;

        foreach (self::$items as $i => $item) {
            if ($i === $last) {
                // Last item: no link, just text
                $parts[] = '<span class="c-breadcrumbs__current">'
                         . htmlspecialchars($item['label'])
                         . '</span>';
            } else {
                $parts[] = '<a href="' . htmlspecialchars($item['url']) . '" class="c-breadcrumbs__link">'
                         . htmlspecialchars($item['label'])
                         . '</a>';
            }
        }

        $sep  = '<span class="c-breadcrumbs__sep">' . htmlspecialchars($separator) . '</span>';
        $html = '<nav class="c-breadcrumbs" aria-label="Breadcrumb">'
              . implode($sep, $parts)
              . '</nav>';

        return $html;
    }
}
