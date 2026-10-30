<?php

declare(strict_types=1);

namespace Cms\ContentType;

/**
 * ArchetypeDefaults — provides default field groups, supports, and icons
 * for each content archetype (Post, Event, Listing, Product).
 *
 * When a Content Type is created via the admin UI and an archetype is selected,
 * the default fields are copied into a real DB field group. The user can then
 * freely edit or delete individual fields — archetypes are a starting point.
 */
class ArchetypeDefaults
{
    /**
     * Return the default fields for an archetype.
     * Returns an array of FieldRenderer-compatible field definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getFields(string $archetype): array
    {
        return match ($archetype) {
            'event' => [
                [
                    'key'      => 'event_start_date',
                    // PLAIN TEXT, not pre-escaped. A label stored as `&amp;` is escaped
                    // AGAIN at output and renders literally as "Start Date &amp;amp;
                    // Time". Escaping belongs at the boundary (FieldRenderer, via
                    // View::escape), never in the data.
                    'label'    => 'Start Date & Time',
                    'type'     => 'datetime',
                    'required' => true,
                    // ── THE SHAPE COMES WITH THE SHAPE ──────────────────────
                    // With every generated field at `full`, a ticked archetype
                    // would produce four short values in four 700px boxes
                    // stacked down the form, laid out by accident rather than
                    // by intent, because nothing said otherwise.
                    //
                    // `width` is the field group's own Presentation setting,
                    // whitelisted in FieldGroup::saveFields() and read by
                    // FieldRenderer — so this is not a new mechanism, it is the
                    // existing one given a value. The author can change any of
                    // it afterwards on the group, which is the whole point of a
                    // starting shape.
                    //
                    // Start and End are a PAIR and sit side by side.
                    'width'    => 'half',
                ],
                [
                    'key'   => 'event_end_date',
                    'label' => 'End Date & Time',
                    'type'  => 'datetime',
                    'width' => 'half',
                ],
                [
                    'key'         => 'event_location',
                    'label'       => 'Location',
                    'type'        => 'text',
                    'placeholder' => 'e.g. Conference Room A',
                ],
                [
                    'key'         => 'event_url',
                    'label'       => 'Event URL',
                    'type'        => 'text',
                    'placeholder' => 'https://...',
                ],
            ],

            'listing' => [
                [
                    'key'         => 'listing_subtitle',
                    'label'       => 'Subtitle / Role',
                    'type'        => 'text',
                    'placeholder' => 'e.g. Lead Developer',
                ],
                [
                    'key'   => 'listing_email',
                    'label' => 'Email Address',
                    'type'  => 'text',
                    'width' => 'half',
                ],
                [
                    'key'   => 'listing_phone',
                    'label' => 'Phone Number',
                    'type'  => 'text',
                    'width' => 'half',
                ],
                [
                    'key'         => 'listing_website',
                    'label'       => 'Website',
                    'type'        => 'text',
                    'placeholder' => 'https://...',
                ],
            ],

            'product' => [
                [
                    'key'   => 'product_price',
                    'label' => 'Price',
                    'type'  => 'number',
                    'min'   => '0',
                    'width' => 'half',
                ],
                [
                    'key'   => 'product_sale_price',
                    'label' => 'Sale Price',
                    'type'  => 'number',
                    'min'   => '0',
                    'width' => 'half',
                ],
                [
                    // THE DAYS A SALE RUNS, both included, in the site's own
                    // days (`Product::saleOpen()`), under the sale price they
                    // belong to. Blank is open-ended, so a sale price with no
                    // dates is on until it is taken off. Migration 095 gives
                    // types made before this the same two, in the same place.
                    'key'   => 'product_sale_from',
                    'label' => 'Sale starts',
                    'type'  => 'date',
                    'width' => 'half',
                ],
                [
                    'key'   => 'product_sale_to',
                    'label' => 'Sale ends',
                    'type'  => 'date',
                    'width' => 'half',
                ],
                [
                    'key'   => 'product_sku',
                    'label' => 'SKU',
                    'type'  => 'text',
                    'width' => 'half',
                ],
                [
                    // DELIVERY IS OPT-IN FOR THE SAME REASON COUNTING IS. A
                    // membership, a ticket and a download are all sold here and
                    // none of them goes in a van; charging to deliver one is
                    // the kind of wrong that makes a customer close the tab.
                    //
                    // Off, `Commerce\Shipping` charges this basket nothing
                    // whatever the shop has configured — so an install that
                    // already exists cannot start charging delivery on its back
                    // catalogue the day a rate is set.
                    'key'   => 'product_needs_delivery',
                    'label' => 'Needs delivering',
                    'type'  => 'checkbox',
                    'width' => 'half',
                ],
                [
                    // COUNTING IS OPT-IN. A membership, a ticket to an
                    // unlimited event or a downloadable file never runs out,
                    // and forcing a number on them makes every owner type 999
                    // to mean "yes". Off, the status word below is the whole
                    // answer and `Commerce\Stock` does nothing at all.
                    'key'   => 'product_manage_stock',
                    'label' => 'Count stock',
                    'type'  => 'checkbox',
                    'width' => 'half',
                ],
                [
                    'key'   => 'product_stock_qty',
                    'label' => 'Units in stock',
                    'type'  => 'number',
                    'min'   => '0',
                    'width' => 'half',
                ],
                [
                    'key'     => 'product_stock_status',
                    'label'   => 'Stock Status',
                    'type'    => 'select',
                    'width'   => 'half',
                    'options' => [
                        'in_stock'     => 'In Stock',
                        'out_of_stock' => 'Out of Stock',
                        'on_backorder' => 'On Backorder',
                    ],
                ],
                [
                    // MORE PHOTOS than the featured one, in the owner's order:
                    // a repeater of images, the mechanism a field group already
                    // has, drawn on the product page as thumbnails under the
                    // main photo. A colour's own photo goes on its options.
                    'key'        => 'product_gallery',
                    'label'      => 'More photos',
                    'type'       => 'repeater',
                    'sub_fields' => [
                        ['key' => 'image', 'label' => 'Photo', 'type' => 'image'],
                    ],
                ],
            ],

            // Post archetype: no extra fields (uses supports: title, content, thumbnail, excerpt)
            default => [],
        };
    }

    /**
     * Return the default supports checkboxes for an archetype.
     *
     * @return string[]
     */
    public static function getSupports(string $archetype): array
    {
        return match ($archetype) {
            'post'    => ['title', 'content', 'thumbnail', 'excerpt'],
            'event'   => ['title', 'content', 'thumbnail'],
            'listing' => ['title', 'thumbnail'],
            'product' => ['title', 'content', 'thumbnail'],
            default   => ['title', 'content', 'thumbnail', 'excerpt'],
        };
    }

    /**
     * The archetype's own GLYPH — archetype key => icon slug.
     *
     * It answers the question the two Content Type screens have to answer:
     * **what kind of content is this?** The archetype decides the fields, the
     * entry form and the public page, and a kind announced only by printing its
     * name in a chip is told, not shown — on the screen a site owner uses to
     * build their content model.
     *
     * ── WHY NOT THE MENU ICON'S SLUG ────────────────────────────────────────
     * The DEFAULT `menu_icon` for a type of each archetype — `calendar` for an
     * event, `file-text` for a post — would make the content types list draw
     * the type's chosen glyph and this one side by side, and they would usually
     * be the SAME MARK TWICE on one row. They are two different facts that
     * coincide by default: the menu icon is the mark its author picked for the
     * sidebar, and this is what kind of thing the type is. Each slug below is
     * taken from `describe()`'s own sentence instead, and none of them collides
     * with a glyph offered in the Glyph picker.
     *
     * Every slug is a real file in `www/admin/assets/icon/` — a glyph registry
     * that has gone stale renders NOTHING at all, which reads as "this row has
     * no kind".
     *
     * @return array<string, string>
     */
    public static function glyphs(): array
    {
        return [
            // "rich content in a feed" — lines of prose, not a document icon.
            // `file` is what the Glyph picker's Document option resolves to.
            'post'    => 'align-left',
            // "anchored to a TIME". Not `calendar`, which is the Glyph picker's
            // Calendar option and the obvious menu icon for an events type.
            'event'   => 'clock',
            // "shown as CARDS" — `table-cells-large`, not `table-cells`. The
            // dense grid renders as a smudge at the 12px the chip draws it at.
            // Four big cells survive the size and say "cards" better than
            // sixteen small ones.
            'listing' => 'table-cells-large',
            // "something with a transaction".
            'product' => 'box-open',
        ];
    }

    /**
     * The glyph slug for one archetype.
     *
     * Falls back to `post`'s mark for an unknown key, matching every other
     * accessor here — `getFields()`, `getSupports()` and `describe()` all treat
     * `post` as the default archetype.
     */
    public static function getIcon(string $archetype): string
    {
        return self::glyphs()[$archetype] ?? self::glyphs()['post'];
    }

    /**
     * What an archetype MEANS, in the words a site owner would use.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
     * Labelling these rows with the name of the field group each one generates
     * — `Post — Article details` — on the reasoning that an archetype IS a
     * field group does not avoid explaining the concept; it leaves the concept
     * unexplained and answers a question nobody asked. "Article details" does
     * not tell anyone whether News is a Post or a Listing.
     *
     * So each archetype has one plain sentence saying what it means, kept in
     * one place so the form, the list and anything else read the same sentence.
     *
     * ARCHETYPES ARE ADDITIVE. A type may declare one or more, which is why the
     * control is a checkbox list and not a picker, and why the copy has to say
     * so out loud rather than leaving it to be discovered.
     */
    public static function describe(string $archetype): string
    {
        return match ($archetype) {
            'event'   => 'anchored to a time — start and end, location, a calendar view',
            'listing' => 'a record in a directory — people, places, clubs, shown as cards',
            'product' => 'something with a transaction — price, options, a buy action',
            default   => 'rich content in a feed — news, articles, anything dated',
        };
    }

    /**
     * The heading above an archetype's group of fields, on the edit screen.
     *
     * Named for what the fields *are* rather than for the archetype they came
     * from. "Event Fields" and "Listing Fields" describe the CMS's internal
     * taxonomy to someone who has never heard of an archetype and does not need
     * to; "Date and location" describes what they are about to fill in.
     */
    public static function getGroupTitle(string $archetype): string
    {
        return match ($archetype) {
            'event'   => 'Date and location',
            'listing' => 'Contact details',
            'product' => 'Price and plan',
            default   => 'Article details',
        };
    }

    /**
     * Are this type's entries ordered BY HAND, or by date?
     *
     * ── WHY THIS IS ONE FUNCTION AND NOT THREE COPIES ───────────────────────
     * `Post::all()`, the public archive and the entry form's Position panel all
     * need this rule, and the entry form's copy is what decides whether the
     * owner is SHOWN a control. Copies of a rule that decides whether a control
     * exists and whether anything reads it will eventually disagree, and the
     * shape of that disagreement is a box the owner fills in that changes
     * nothing.
     *
     * ── AND WHY AN EVENT IS NEVER HAND-ORDERED ──────────────────────────────
     * A ticketed event carries `event` AND `product`, so a plain
     * `in_array('listing') || in_array('product')` would make it hand-ordered —
     * while the public archive sorts events by their start date regardless,
     * because nobody wants next term's formal listed above tomorrow's meeting.
     * The result would be a Position box on every event, with a number in it,
     * that the public page ignores.
     *
     * An event's order is its DATE.
     *
     * @param string[] $archetypes
     */
    public static function isHandOrdered(array $archetypes): bool
    {
        if (in_array('event', $archetypes, true)) {
            return false;
        }

        return in_array('listing', $archetypes, true)
            || in_array('product', $archetypes, true);
    }

    /**
     * Merge fields from multiple archetypes, deduplicating by key.
     *
     * @param  string[] $archetypes
     * @return array<int, array<string, mixed>>
     */
    public static function getMergedFields(array $archetypes): array
    {
        $merged = [];
        $seen   = [];

        foreach ($archetypes as $archetype) {
            foreach (self::getFields($archetype) as $field) {
                if (!in_array($field['key'], $seen, true)) {
                    $merged[] = $field;
                    $seen[]   = $field['key'];
                }
            }
        }

        return $merged;
    }

    /**
     * Merge supports from multiple archetypes (union).
     *
     * @param  string[] $archetypes
     * @return string[]
     */
    public static function getMergedSupports(array $archetypes): array
    {
        $merged = [];
        foreach ($archetypes as $archetype) {
            $merged = array_merge($merged, self::getSupports($archetype));
        }
        return array_values(array_unique($merged));
    }
}
