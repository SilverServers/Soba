<?php

declare(strict_types=1);

/**
 * CMS — Content Type Definitions
 *
 * Content Types registered in code, with Registry::register(). This is the
 * site's own file: an update never changes it. A type made on the Content
 * Types screen needs nothing here.
 *
 * A KEY IN BOTH: a type registered here wins over one of the same key made on
 * the Content Types screen, and that one is not loaded.
 *
 * Field types:
 *   text, textarea, rich_text, number, date, datetime, select, checkbox, image,
 *   repeater, relationship, taxonomy
 *
 * Archetypes (each adds its own fields to a type):
 *   'post'     — articles in a feed: a title, text, a featured image and an
 *                excerpt. It brings no taxonomy: categories and tags are
 *                taxonomies of their own (config/taxonomies.php, or the
 *                Taxonomies screen), attached to the type.
 *   'event'    — content anchored to time: a start, an end, a location and a
 *                link, and the type's events as a calendar to subscribe to
 *   'listing'  — a record in a directory: a subtitle, an email, a phone and a
 *                website, and no body text
 *   'product'  — something the shop sells: a price, a sale price and its dates,
 *                a code, delivery, stock and more photos
 *
 * Example, with fields of its own:
 *
 *   Registry::register('event', [
 *       'label'        => 'Events',
 *       'label_single' => 'Event',
 *       'admin_slug'   => 'events',
 *       'public_slug'  => 'events',
 *       'menu_icon'    => 'calendar',
 *       'supports'     => ['title', 'content'],
 *       'archetypes'   => ['event'],
 *       'fields'       => [
 *           ['key' => 'event_date', 'label' => 'Date',     'type' => 'date',     'required' => true],
 *           ['key' => 'location',   'label' => 'Location', 'type' => 'text',     'required' => true],
 *           ['key' => 'capacity',   'label' => 'Capacity', 'type' => 'number'],
 *           ['key' => 'speakers',   'label' => 'Speakers', 'type' => 'repeater',
 *            'sub_fields' => [
 *                ['key' => 'name',  'label' => 'Name',  'type' => 'text'],
 *                ['key' => 'bio',   'label' => 'Bio',   'type' => 'textarea'],
 *            ]],
 *       ],
 *   ]);
 */
//
// use Cms\ContentType\Registry;
//
// // -----------------------------------------------------------------------
// // Add your Content Types here for code-based registration.
// // Code-registered types are loaded at bootstrap, before those made on
// // the Content Types screen, and win a key clash with one.
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
