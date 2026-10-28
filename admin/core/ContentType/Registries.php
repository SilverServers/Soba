<?php

declare(strict_types=1);

namespace Cms\ContentType;

use Cms\Models\ContentTypeDefinition as ContentTypeDefinitionModel;
use Cms\Models\Taxonomy as TaxonomyModel;

/**
 * EVERY CONTENT TYPE AND TAXONOMY THIS INSTALL HAS, LOADED ONCE — those
 * registered in code (admin/config/content-types.php, taxonomies.php), then
 * those made in the admin (their tables). The front controller loads them
 * before it registers a route, and `Site\Front::owns()` loads them to answer a
 * site's own router — whichever asks first in a request; the other finds them
 * loaded.
 */
final class Registries
{
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        // 1. Code-registered Content Types (developer-defined in config/content-types.php)
        require ROOT_PATH . '/config/content-types.php';

        // 2. DB-created Content Types (created via the admin UI Structure section)
        foreach (ContentTypeDefinitionModel::allActive() as $dbPt) {
            // Skip if the key is already registered (code-registered Content Types take precedence)
            if (Registry::get($dbPt['type_key']) === null) {
                Registry::register($dbPt['type_key'], [
                    'label'        => $dbPt['plural_label'],
                    'label_single' => $dbPt['singular_label'],
                    'admin_slug'   => $dbPt['admin_slug'],
                    'public_slug'  => $dbPt['public_slug'],
                    'menu_icon'    => $dbPt['menu_icon'],
                    'supports'     => $dbPt['supports'],
                    'archetypes'   => $dbPt['archetypes'],
                    // Migration 047. A type created in the admin may belong to one
                    // registered in code and the other way round — the Registry is what
                    // resolves it, because it is the only thing that can see both.
                    'parent_type'  => $dbPt['parent_type'] ?? null,
                    // Migration 090: how many to a page (NULL follows the site's own
                    // number) and how many more on page 1 — Registry::perPage() and
                    // Registry::firstPageExtra().
                    'per_page'         => isset($dbPt['per_page']) ? (int) $dbPt['per_page'] : null,
                    'first_page_extra' => (int) ($dbPt['first_page_extra'] ?? 0),
                    'fields'       => [], // fields come from field groups, not embedded here
                    'source'       => 'database',
                    'db_id'        => $dbPt['id'],
                ]);
            }
        }

        // 1. Code-registered taxonomies (developer-defined in config/taxonomies.php)
        require ROOT_PATH . '/config/taxonomies.php';

        // 2. DB-created taxonomies (created via the admin UI Structure section)
        foreach (TaxonomyModel::allActive() as $dbTax) {
            if (TaxonomyRegistry::get($dbTax['taxonomy_key']) === null) {
                TaxonomyRegistry::register($dbTax['taxonomy_key'], [
                    'label'         => $dbTax['plural_label'],
                    'label_single'  => $dbTax['singular_label'],
                    'admin_slug'    => $dbTax['admin_slug'],
                    'public_slug'   => $dbTax['public_slug'] ?? null,   // migration 048
                    'content_types' => $dbTax['content_types'],
                    'hierarchical'  => (bool) $dbTax['is_hierarchical'],
                    'min_terms'     => (int) ($dbTax['min_terms'] ?? 0),       // migration 056
                    'max_terms'     => isset($dbTax['max_terms']) ? (int) $dbTax['max_terms'] : null,
                    'source'        => 'database',
                    'db_id'         => $dbTax['id'],
                ]);
            }
        }
    }
}
