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
 *   text, textarea, rich_text, number, phone, email, date, datetime, select,
 *   checkbox, image, file, gallery, repeater, relationship, taxonomy
 *
 * Every option a field takes is named in Cms\ContentType\FieldOptions. One the
 * CMS does not know, a type it does not know, or a logic rule it cannot read is
 * said in the PHP error log, once and then hourly while it is still there.
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
 *
 * A type whose entries are only data for the site's own templates, such as
 * the reviews or questions a block lists, has no page for any entry:
 * `'pages' => false` (or `'public_slug' => null`). Its entries have no
 * address, archive or feed, and stay out of the sitemap, search and menus.
 *
 * A repeater of short values, such as prices by weight, can be drawn as a
 * table, `'layout' => 'table'`: its sub-fields as columns and each row on one
 * line, Enter moving down a column and a block pasted from a spreadsheet
 * filling the cells. `'preset_rows' => ['field' => 'weight']` makes a select
 * sub-field's options the rows, each named by its option, with no Add row or
 * Remove. A number sub-field takes `'decimals' => 2` and `'prefix' => '$'`.
 * What is kept is the same JSON in either layout.
 *
 * Its entry list shows the title, status and date; a type adds columns
 * beside the title, a field, a taxonomy's terms or how many entries of a child
 * type each has, and says how the list is ordered until someone presses a
 * heading:
 *
 *   'list_columns' => ['event_start_date' => 'Starts', 'tax:venue' => 'Venue', 'children:session' => 'Sessions'],
 *   'default_sort' => ['field' => 'event_start_date', 'order' => 'asc'],
 *
 * A field's column sorts the list by it when its heading is pressed. An event
 * type's list opens on Upcoming, soonest first, beside Past, newest first,
 * and shows when each starts unless it names its own columns. The Content
 * Types screen sets the same for a type made there.
 *
 * A type whose entries are made to be seen at once, a weekly event's, say,
 * starts each new entry Published on its editor: 'default_status' =>
 * 'published'. Private is the default; copies and translations keep their own.
 *
 * A type calls its entries' title by its own word, 'title_label' => 'Name':
 * over the editor's title box and its list, and in the sentence refusing an
 * entry saved without one. Without it the box says "{Singular} title".
 *
 * A type whose fields are the point, an event's date and place, has its
 * editor open on them, between the title and the text: 'fields_position' =>
 * 'high'. After the text is the default. A field group's own Position, on its
 * screen in Structure → Field groups, wins: High, Normal or Side.
 *
 * A parent's children can be added and arranged on its own editor, a panel of
 * rows for each type that belongs to it ('parent_type'), with the columns each
 * row shows: the child's title or one of its fields that is text, a number, a
 * date, a choice, a tick box, a picture, a file or a gallery.
 *
 *   'children' => ['session' => ['columns' => ['title', 'speaker', 'photos']]],
 *
 * Each row is saved as it is added or changed, through the child's own checks,
 * and the rows' order is kept within the parent, as Post::childrenOf() gives it.
 *
 * What staff see on a type's screens can follow their roles, each part shown
 * to the role named and those above it, or false for no one; a part not named
 * stays as it is:
 *   'panels' => ['author' => 'admin', 'seo' => 'admin'],
 *   'list'   => ['address' => 'admin', 'author_filter' => 'admin', 'bulk' => 'admin', 'group_by' => 'section'],
 * 'group_by' is a Choice field's key or a taxonomy's as 'tax:location'.
 *
 * A type takes its own number of a taxonomy's terms, over the taxonomy's own
 * rule for its entries alone: 'terms' => ['location' => ['min' => 1, 'max' =>
 * 1]], min 0 or 1 and max 1 to 99 or none (exactly one, here).
 *
 * A type whose entries are always titled the same way, a weekly report named
 * for its place and its date, makes their titles and addresses from their
 * fields, in the token form search titles use:
 *
 *   'title_pattern' => '{tax:location} report, {report_date:F j, Y}',
 *   'slug_pattern'  => '{id}-{tax:location}-report-{report_date}',
 *
 * {field} is a field's value (a choice's label, a date as kept), {field:F j, Y}
 * a date in PHP's date letters, {tax:key} the entry's terms in a taxonomy and
 * {id} its number; the site's own, such as {sep}, too. An entry saved with its
 * title left blank is titled by the pattern, and one whose address is not
 * typed is addressed by it, an {id} filled in once it is first saved.
 *
 * What Copy makes of the type's entries: the words after a copy's title
 * ("(copy)" unless the type says otherwise; '' keeps the title), whether it
 * keeps its original's status ('same', or Private, the default), and the
 * fields it never takes, by key, a `*` for any ending:
 *
 *   'copy' => ['suffix' => '', 'status' => 'same', 'skip' => ['legacy_id', 'legacy_*']],
 *
 * A copy's address is made from its title as a new entry's is.
 *
 * A child type names each entry it may belong to, where its editor offers
 * them, by the parent's own fields, in the same token form: a weekly event's
 * date, place and title, rather than one title many times over. An event
 * type's are listed those to come first, soonest first, then those over:
 *
 *   'parent_label' => '{event_start_date:D, M j} · {event_location} · {title}',
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
