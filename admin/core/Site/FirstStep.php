<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Auth\Auth;
use Cms\ContentType\Registry;
use Cms\Helpers\Url;

/**
 * WHAT AN EMPTY LIST OFFERS ITS EDITOR. A visitor to a list with nothing in
 * it reads the site's own plain line (Settings → Words); someone signed in
 * who can write sees, beneath it, the link that puts the first thing there.
 * A visitor carries no session (`Security\Session`), so a page a cache may
 * keep never holds the link.
 */
final class FirstStep
{
    /**
     * For a list of these types' entries, or, with none named, the front
     * page's: the link to the first entry, or null for anyone who cannot
     * write one. With no type to write in, an administrator is offered the
     * Content Types screen.
     *
     * @param list<string> $typeKeys
     * @return array{href: string, label: string}|null
     */
    public static function forList(array $typeKeys = []): ?array
    {
        if (!Auth::check() || !Auth::hasRole('author')) {
            return null;
        }
        $types = [];
        foreach ($typeKeys as $key) {
            if (($type = Registry::get($key)) !== null) {
                $types[$key] = $type;
            }
        }
        if ($typeKeys === []) {
            $types = Registry::withArchetype('post') ?: Registry::all();
        }
        $type = reset($types);
        if ($type === false) {
            return Auth::hasRole('admin') ? ['href' => Url::admin('content-types/new'), 'label' => 'Add a Content Type to write in'] : null;
        }

        return [
            'href'  => Url::admin(trim((string) ($type['admin_slug'] ?? ''), '/') . '/new'),
            'label' => 'Write the first ' . mb_strtolower((string) ($type['label_single'] ?? 'entry')),
        ];
    }
}
