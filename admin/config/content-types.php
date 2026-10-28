<?php

declare(strict_types=1);

/**
 * CMS — Content Type Definitions
 *
 * Register all Content Types here using Registry::register().
 * This file is part of the codebase and should be committed to version control.
 * Developers add their client-specific Content Types in this file.
 *
 * Field types supported:
 *   text, textarea, rich_text, number, date, datetime, select, image, repeater, relationship, taxonomy
 *
 * Archetypes (additive capability frameworks):
 *   'post'     — rich content in a feed (title, body, featured image, categories, publish date)
 *   'event'    — content anchored to time (date, location, recurrence, calendar view)
 *   'listing'  — structured record in a directory (card/grid display, minimal body)
 *   'product'  — content with a transaction (price, variants, inventory, buy action)
 *
 * Example Content Type with custom fields:
 *
 *   // Registry::register('event', [
//  *       'label'        => 'Events',
//  *       'label_single' => 'Event',
//  *       'admin_slug'   => 'events',
//  *       'public_slug'  => 'events',
//  *       'menu_icon'    => 'calendar',
//  *       'supports'     => ['title', 'content'],
//  *       'archetypes'   => ['event'],
//  *       'fields'       => [
//  *           ['key' => 'event_date', 'label' => 'Date',     'type' => 'date',     'required' => true],
//  *           ['key' => 'location',   'label' => 'Location', 'type' => 'text',     'required' => true],
//  *           ['key' => 'capacity',   'label' => 'Capacity', 'type' => 'number'],
//  *           ['key' => 'speakers',   'label' => 'Speakers', 'type' => 'repeater',
//  *            'sub_fields' => [
//  *                ['key' => 'name',  'label' => 'Name',  'type' => 'text'],
//  *                ['key' => 'bio',   'label' => 'Bio',   'type' => 'textarea'],
//  *            ]],
//  *       ],
//  *   ]);
//  */
//
// use Cms\ContentType\Registry;
//
// // -----------------------------------------------------------------------
// // Add your Content Types here for code-based registration.
// // Code-registered types are loaded at bootstrap and merged with
// // UI-created types (UI-created types take precedence on key collision).
// // -----------------------------------------------------------------------
//
//
// // -----------------------------------------------------------------------
// // DEMO: Uncomment below to see Events and Staff content types in action.
// // These demonstrate the Event and Listing archetypes respectively.
// // -----------------------------------------------------------------------
//
// Registry::register('event', [
//     'label'        => 'Events',
//     'label_single' => 'Event',
//     'admin_slug'   => 'events',
//     'public_slug'  => 'events',
//     'menu_icon'    => 'calendar',
//     'supports'     => ['title', 'content', 'thumbnail'],
//     'archetypes'   => ['event'],
//     'fields'       => [
//         ['key' => 'event_start_date', 'label' => 'Start Date & Time', 'type' => 'datetime', 'required' => true],
//         ['key' => 'event_end_date',   'label' => 'End Date & Time',   'type' => 'datetime'],
//         ['key' => 'event_location',   'label' => 'Location',          'type' => 'text', 'placeholder' => 'e.g. Conference Room A'],
//         ['key' => 'event_url',        'label' => 'Event URL',         'type' => 'text', 'placeholder' => 'https://...'],
//         ['key' => 'speakers',         'label' => 'Speakers',          'type' => 'repeater',
//          'sub_fields' => [
//              ['key' => 'name',  'label' => 'Name',  'type' => 'text'],
//              ['key' => 'role',  'label' => 'Role',  'type' => 'text'],
//              ['key' => 'bio',   'label' => 'Bio',   'type' => 'textarea', 'rows' => 3],
//          ]],
//     ],
// ]);
//
// Registry::register('staff', [
//     'label'        => 'Staff',
//     'label_single' => 'Staff Member',
//     'admin_slug'   => 'staff',
//     'public_slug'  => 'staff',
//     'menu_icon'    => 'users',
//     'supports'     => ['title', 'thumbnail'],
//     'archetypes'   => ['listing'],
//     'fields'       => [
//         ['key' => 'listing_subtitle', 'label' => 'Job Title',      'type' => 'text'],
//         ['key' => 'listing_email',    'label' => 'Email Address',  'type' => 'text'],
//         ['key' => 'listing_phone',    'label' => 'Phone Number',   'type' => 'text'],
//         ['key' => 'bio',              'label' => 'Biography',      'type' => 'rich_text', 'rows' => 8],
//         ['key' => 'photo',            'label' => 'Profile Photo',  'type' => 'image'],
//     ],
// ]);
