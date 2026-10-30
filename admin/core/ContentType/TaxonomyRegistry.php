<?php

declare(strict_types=1);

namespace Cms\ContentType;

/**
 * Taxonomy Registry — the single source of truth for all Taxonomy definitions.
 *
 * Developers call TaxonomyRegistry::register() in config/taxonomies.php.
 * The admin UI, routes, sidebars, and Content Type edit screen panels all read from here.
 * Nothing is stored in the database for code-registered taxonomies — definitions are code.
 * UI-created taxonomies are loaded from the `taxonomies` DB table at bootstrap and merged in.
 *
 * Taxonomy definition shape:
 * [
 *   'label'         => 'Event Categories',  // Plural label
 *   'label_single'  => 'Event Category',    // Singular label
 *   'admin_slug'    => 'event-categories',  // URL segment: /admin/taxonomies/event-categories/terms
 *   'content_types' => ['event'],           // Content Type keys this taxonomy attaches to
 *   'hierarchical'  => true,               // true = category style (checkboxes + parent/child)
 *                                          // false = tag style (chip input + typeahead)
 *   'source'        => 'code',             // 'code' | 'database'
 * ]
 */
class TaxonomyRegistry
{
    /** @var array<string, array<string, mixed>> */
    private static array $taxonomies = [];

    /**
     * Register a taxonomy definition.
     *
     * @param string               $key        Unique identifier (e.g. 'event_category', 'tag')
     * @param array<string, mixed> $definition Taxonomy definition array
     */
    public static function register(string $key, array $definition): void
    {
        // Normalize: both code-registered and DB-loaded taxonomies use 'content_types'.
        // The 'post_types' fallback is kept as a safety shim for any legacy code-registered calls.
        $definition['content_types'] = $definition['content_types'] ?? $definition['post_types'] ?? [];
        unset($definition['post_types']);

        self::$taxonomies[$key] = array_merge([
            'key'           => $key,
            'label'         => ucfirst($key) . 's',
            'label_single'  => ucfirst($key),
            'admin_slug'    => str_replace('_', '-', $key) . 's',
            // MIGRATION 048. Null means no public archive — a taxonomy used to
            // organise the admin should not gain public pages because a column
            // appeared underneath it. `www/index.php` registers the routes only
            // for a taxonomy that has one.
            'public_slug'   => null,
            'content_types' => [],
            'hierarchical'  => false,
            'min_terms'     => 0,       // how many an entry must have (0 or 1)
            'max_terms'     => null,    // how many it may have; null = no limit
            // MIGRATION 095. A taxonomy a customer chooses from when buying —
            // Size, Colour — whose terms make a product's options.
            'is_option'     => false,
            'source'        => 'code',
        ], $definition, ['key' => $key]); // key always matches $key
    }

    /**
     * Get a taxonomy definition by its key. Returns null if not registered.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        return self::$taxonomies[$key] ?? null;
    }

    /**
     * Look up a taxonomy by its admin_slug (for routing context where only the URL slug is known).
     *
     * @return array<string, mixed>|null
     */
    public static function getByAdminSlug(string $adminSlug): ?array
    {
        foreach (self::$taxonomies as $taxonomy) {
            if ($taxonomy['admin_slug'] === $adminSlug) {
                return $taxonomy;
            }
        }
        return null;
    }

    /**
     * Return all registered taxonomies, keyed by their taxonomy key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::$taxonomies;
    }

    /**
     * Return only the taxonomies applicable to a given content type key.
     * Used by ContentTypeController to determine which sidebar panels to inject on edit screens.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getForContentType(string $contentTypeKey): array
    {
        return array_filter(
            self::$taxonomies,
            fn(array $tax): bool => in_array($contentTypeKey, $tax['content_types'], true)
        );
    }
}
