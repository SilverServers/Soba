<?php

declare(strict_types=1);

namespace Cms\SEO;

use Cms\Models\Option;

/**
 * Schema — JSON-LD structured data output for public pages.
 *
 * Output as <script type="application/ld+json"> in the <head>.
 *
 * Always outputs: WebSite, Organization/LocalBusiness.
 * Per-content-type: Article, Event, Product, WebPage.
 */
class Schema
{
    /**
     * Build the site-wide schema block (WebSite + Organization).
     * Output on every public page.
     */
    public static function siteWide(): array
    {
        $opts = Option::getMany([
            'site_name' => \Cms\SEO\Head::globalTokens()['site_name'],
            'seo_org_type' => 'Organization',
            'seo_org_name' => '',
            'seo_org_url'  => '',
            'seo_org_logo' => '',
        ]);

        $siteUrl = defined('BASE_URL') ? BASE_URL : '';

        $graph = [];

        // ── EACH NODE HAS AN `@id`, SO OTHERS CAN POINT AT IT ───────────────
        // An Article's `publisher` is this organisation, and says so by
        // reference — `{"@id": "…/#organization"}` — rather than repeating it.
        // Without an id there is nothing to point at, and an Article goes out
        // with no publisher at all.
        $graph[] = [
            '@type'     => 'WebSite',
            '@id'       => self::id('website'),
            'name'      => $opts['site_name'],
            'url'       => $siteUrl,
            'publisher' => ['@id' => self::id('organization')],
        ];

        // Organization or LocalBusiness
        $orgName = $opts['seo_org_name'] ?: $opts['site_name'];
        $org = [
            '@type' => $opts['seo_org_type'] === 'LocalBusiness' ? 'LocalBusiness' : 'Organization',
            '@id'   => self::id('organization'),
            'name'  => $orgName,
        ];
        if ($opts['seo_org_url'] !== '') {
            $org['url'] = $opts['seo_org_url'];
        }
        if ($opts['seo_org_logo'] !== '') {
            $org['logo'] = $opts['seo_org_logo'];
        }
        $graph[] = $org;

        return $graph;
    }

    /**
     * Build schema for a single content entry.
     *
     * ── `@type` TAKES A LIST, BECAUSE A CONTENT TYPE CARRIES SEVERAL SHAPES ──
     * A Content Type declares one or more archetypes and they ADD to each other.
     * A ticketed event is an Event and a Product at the same time. Published as
     * `"@type": "Event"` alone, its price, its SKU and its availability would be
     * dropped from the structured data on the way out: the owner filled in the
     * fields, the page printed them, and the search engine would be told the
     * page had no offer on it.
     *
     * JSON-LD takes an ARRAY of types for exactly this — `["Event","Product"]`
     * is a valid node that is both. A single type still encodes as a bare
     * string, which is the commoner form and what every existing caller and
     * consumer expects.
     *
     * ── WHAT AN ARTICLE CARRIES ──────────────────────────────────────────
     *   · `datePublished` / `dateModified` — ISO 8601 with the site's offset,
     *     never MySQL's `Y-m-d H:i:s`;
     *   · `url` and `mainEntityOfPage` — the entry's canonical address;
     *   · `description` — the one the page's own meta description uses;
     *   · `publisher` — the site's Organization, by `@id`, on anything that is
     *     a creative work (an Article, a WebPage). An Event or a Product has no
     *     publisher in schema.org's sense.
     *   · `author` — a Person with the byline's name (the display name, from
     *     migration 055).
     *
     * @param string|string[]      $schemaType  'Article' · ['Event', 'Product'] · …
     * @param array<string, mixed> $entry       The post/page data array
     * @param array<string, mixed> $meta        Post meta values
     * @param array{url?: string, description?: string} $page  what the page itself says
     */
    public static function forEntry(string|array $schemaType, array $entry, array $meta = [], array $page = []): array
    {
        $types = array_values(array_unique((array) $schemaType));
        $is    = static fn (string $t): bool => in_array($t, $types, true);

        $schema = [
            '@type'         => count($types) === 1 ? $types[0] : $types,
            'name'          => $entry['title'] ?? '',
            'headline'      => $entry['title'] ?? '',
            'datePublished' => self::isoDate($entry['published_at'] ?? $entry['created_at'] ?? null),
            'dateModified'  => self::isoDate($entry['updated_at'] ?? $entry['created_at'] ?? null),
        ];

        $url = (string) ($page['url'] ?? '');
        if ($url !== '') {
            $schema['url']              = $url;
            $schema['mainEntityOfPage'] = $url;
        }
        $description = trim((string) ($page['description'] ?? ''));
        if ($description !== '') {
            $schema['description'] = $description;
        }
        if ($is('Article') || $is('WebPage')) {
            $schema['publisher'] = ['@id' => self::id('organization')];
        }

        // A PUBLIC QUERY'S `author_name` IS NEVER A SIGN-IN NAME
        // (`User::bylineSql()`): an author with no display name is credited to
        // the site. Then the author IS the organisation, so it points at that
        // node rather than inventing a Person named after the company.
        if (!empty($entry['author_name'])) {
            $schema['author'] = (string) $entry['author_name'] === (string) Head::globalTokens()['site_name']
                ? ['@id' => self::id('organization')]
                : ['@type' => 'Person', 'name' => $entry['author_name']];
        }

        // Featured image
        if (!empty($entry['featured_image_id'])) {
            $imageUrl = \Cms\Models\Media::getUrl((int) $entry['featured_image_id']);
            if ($imageUrl !== '') {
                $schema['image'] = $imageUrl;
            }
        }

        // Archetype-specific schema properties from standardised meta keys
        if ($is('Event')) {
            // ISO 8601 as well — an event's times are typed as the site's wall
            // clock (`Y-m-d H:i`), so they take the site's offset.
            if (!empty($meta['event_start_date'])) {
                $schema['startDate'] = self::isoDate((string) $meta['event_start_date'], true);
            }
            if (!empty($meta['event_end_date'])) {
                $schema['endDate'] = self::isoDate((string) $meta['event_end_date'], true);
            }
            if (!empty($meta['event_location'])) {
                $schema['location'] = [
                    '@type' => 'Place',
                    'name'  => \Cms\ContentType\FieldRenderer::plain($meta['event_location']),
                ];
            }
        }

        if ($is('Product')) {
            $offers = ['@type' => 'Offer'];
            if (!empty($meta['product_price'])) {
                $offers['price'] = $meta['product_price'];
                $offers['priceCurrency'] = 'USD';
            }
            if (!empty($meta['product_stock_status'])) {
                $statusMap = [
                    'in_stock'     => 'https://schema.org/InStock',
                    'out_of_stock' => 'https://schema.org/OutOfStock',
                    'on_backorder' => 'https://schema.org/BackOrder',
                ];
                $offers['availability'] = $statusMap[$meta['product_stock_status']] ?? '';
            }
            if (count($offers) > 1) {
                $schema['offers'] = $offers;
            }
            if (!empty($meta['product_sku'])) {
                $schema['sku'] = \Cms\ContentType\FieldRenderer::plain($meta['product_sku']);
            }
        }

        return $schema;
    }

    /**
     * A node's `@id`: the site's address and a fragment.
     */
    public static function id(string $node): string
    {
        return (defined('BASE_URL') ? BASE_URL : '') . '/#' . $node;
    }

    /**
     * A date as ISO 8601 with the site's offset — what schema.org's Date and
     * DateTime mean, and what every consumer of this markup parses. Storage is
     * UTC (`Database::init()`), so a stored timestamp is read as UTC; a value
     * the owner TYPED — an event's start — is the site's wall clock.
     * Unreadable or empty gives ''.
     */
    public static function isoDate(?string $when, bool $local = false): string
    {
        $when = trim((string) $when);
        if ($when === '') {
            return '';
        }
        try {
            $zone = \Cms\Helpers\Dates::zone();
            $at   = new \DateTimeImmutable($when, $local ? $zone : new \DateTimeZone('UTC'));
            return $at->setTimezone($zone)->format(DATE_ATOM);
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * Build a BreadcrumbList schema from an array of breadcrumb items.
     *
     * @param array<int, array{label: string, url: string}> $items
     */
    public static function breadcrumbList(array $items): array
    {
        $listItems = [];
        foreach ($items as $i => $item) {
            $listItems[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $item['label'],
                'item'     => $item['url'],
            ];
        }

        return [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $listItems,
        ];
    }

    /**
     * Encode a schema graph as a JSON-LD string.
     *
     * ── `JSON_HEX_TAG` IS THE SECURITY OF THIS FUNCTION ─────────────────────
     * The string lands inside `<script type="application/ld+json">`, and a
     * browser ends that element at the first `</script>` whatever JSON thinks
     * it is in. An entry's title goes into `headline` as typed, so without it a
     * title reading `</script><script>…` would close the block and run as
     * script on the public page — for any visitor, a signed-in owner included —
     * and what is left of the JSON-LD could not be
     * parsed. `\u003C` and `\u003E` are the same characters to every JSON
     * parser, and never a tag to an HTML one.
     *
     * @param array<int, array<string, mixed>> $graph
     */
    public static function encode(array $graph): string
    {
        $wrapper = [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];

        return json_encode($wrapper, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG) ?: '{}';
    }
}
