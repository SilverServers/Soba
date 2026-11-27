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

        // 1. Code-registered Content Types (developer-defined in config/content-types.php),
        // read quietly (`Site\ConfigFile::read()`)
        \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/content-types.php');

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
                    // Migration 130: its entry list's columns and its order when
                    // nobody has chosen (`ListColumns`). NULL is the type's own.
                    'list_columns'     => isset($dbPt['list_columns']) ? json_decode((string) $dbPt['list_columns'], true) : null,
                    'default_sort'     => isset($dbPt['list_sort']) ? json_decode((string) $dbPt['list_sort'], true) : null,
                    // Migration 131: the Status a new entry starts at.
                    'default_status'   => ($dbPt['default_status'] ?? null) === 'published' ? 'published' : 'private',
                    // Migration 150: its own word for its entries' title.
                    'title_label'      => isset($dbPt['title_label']) ? (string) $dbPt['title_label'] : null,
                    // Migration 145: the child types its editor shows, with
                    // each one's columns (`ContentType\Children`).
                    'children'         => isset($dbPt['children']) ? (array) (json_decode((string) $dbPt['children'], true) ?: []) : [],
                    // Migration 140: what staff see on its screens (`StaffView`).
                    'panels'           => isset($dbPt['panels']) ? (array) json_decode((string) $dbPt['panels'], true) : [],
                    'list'             => isset($dbPt['list_view']) ? (array) json_decode((string) $dbPt['list_view'], true) : [],
                    // Migration 151: how many terms of each taxonomy its entries take.
                    'terms'            => isset($dbPt['term_rules']) && is_array($rules = json_decode((string) $dbPt['term_rules'], true)) ? $rules : [],
                    // Migration 146: its titles and addresses made from its fields.
                    'title_pattern'    => (string) ($dbPt['title_pattern'] ?? ''),
                    'slug_pattern'     => (string) ($dbPt['slug_pattern'] ?? ''),
                    // Migration 147: what Copy makes of its entries.
                    'copy'             => isset($dbPt['copy_rules']) ? (array) (json_decode((string) $dbPt['copy_rules'], true) ?: []) : [],
                    // Migration 148: how its editor names each entry it may belong to.
                    'parent_label'     => (string) ($dbPt['parent_label'] ?? ''),
                    // Migration 149: where its fields go on its editor.
                    'fields_position'  => ($dbPt['fields_position'] ?? null) === 'high' ? 'high' : '',
                    'fields'       => [], // fields come from field groups, not embedded here
                    'source'       => 'database',
                    'db_id'        => $dbPt['id'],
                ]);
            }
        }

        // 1. Code-registered taxonomies (developer-defined in config/taxonomies.php)
        \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/taxonomies.php');

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
                    'is_option'     => !empty($dbTax['is_option']),                // migration 095
                    // Migration 140: who may add a term; 'none' is no one.
                    'add_terms'     => ($dbTax['add_terms'] ?? null) === 'none' ? false : (($dbTax['add_terms'] ?? null) ?: null),
                    'source'        => 'database',
                    'db_id'         => $dbTax['id'],
                ]);
            }
        }
    }
}
