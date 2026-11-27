<?php

declare(strict_types=1);

use Cms\ContentType\TaxonomyRegistry;

/**
 * CMS — Taxonomy Definitions
 *
 * Register code-defined taxonomies here. These are loaded at bootstrap before
 * any made on the Taxonomies screen (same pattern as config/content-types.php),
 * and one registered here wins a key clash with one made there, which is then
 * not loaded.
 *
 * Configuration options:
 *   'label'         — Plural label shown in the admin UI
 *   'label_single'  — Singular label (breadcrumbs, empty states)
 *   'admin_slug'    — URL segment: /admin/taxonomies/{admin_slug}/terms
 *   'public_slug'   — the address of a term's page: /{public_slug}/{term}
 *   'content_types' — Content Type keys this taxonomy attaches to (sidebar panels on edit screen)
 *   'hierarchical'  — true: category style (checkbox tree with parent/child nesting)
 *                     false: tag style (chip input with typeahead)
 *   'add_terms'     — the least role that may add a term ('admin'), or false for no
 *                     one; as its shape says unless named (authors on a flat one,
 *                     editors on a nested one)
 */

// -----------------------------------------------------------------------
// Add your taxonomies here for code-based registration.
// Code-registered taxonomies are loaded at bootstrap, before those made on
// the Taxonomies screen, and win a key clash with one.
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
