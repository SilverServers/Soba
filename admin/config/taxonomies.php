<?php

declare(strict_types=1);

use Cms\ContentType\TaxonomyRegistry;

/**
 * CMS — Taxonomy Definitions
 *
 * Register code-defined taxonomies here. These are loaded at bootstrap before
 * any UI-created taxonomies from the database (same pattern as config/content-types.php).
 *
 * Configuration options:
 *   'label'         — Plural label shown in the admin UI
 *   'label_single'  — Singular label (breadcrumbs, empty states)
 *   'admin_slug'    — URL segment: /admin/taxonomies/{admin_slug}/terms
 *   'content_types' — Content Type keys this taxonomy attaches to (sidebar panels on edit screen)
 *   'hierarchical'  — true: category style (checkbox tree with parent/child nesting)
 *                     false: tag style (chip input with typeahead)
 */

// -----------------------------------------------------------------------
// Add your taxonomies here for code-based registration.
// Code-registered taxonomies are loaded at bootstrap and merged with
// UI-created taxonomies (UI-created take precedence on key collision).
// -----------------------------------------------------------------------


// -----------------------------------------------------------------------
// DEMO: Uncomment below alongside the Events content type in content-types.php.
// -----------------------------------------------------------------------

// TaxonomyRegistry::register('event_category', [
//     'label'         => 'Event Categories',
//     'label_single'  => 'Event Category',
//     'admin_slug'    => 'event-categories',
//     'content_types' => ['event'],
//     'hierarchical'  => true,
// ]);
