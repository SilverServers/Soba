<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Helpers\Url;
use Cms\Models\Meta;
use Cms\Models\Media;
use PDO;

/**
 * THE SHOP'S PRODUCTS AS A FEED: what Google Merchant Center and Meta's
 * catalogue read to list them on Google Shopping, Facebook and Instagram.
 *
 * ── ONE FEED FOR BOTH ───────────────────────────────────────────────────────
 * RSS 2.0 with the product fields Google publishes under its `g:` namespace,
 * which Meta's catalogue reads too. An owner gives each of them this one
 * address (Settings → Shop) and they fetch it on their own timetable.
 *
 * ── AN ITEM FOR EACH THING A CUSTOMER CAN CHOOSE ────────────────────────────
 * A product sold as itself is one item. A product sold in options is an item
 * for each option, grouped by the product (`g:item_group_id`), with its own
 * code, price, stock and photo, and its size and colour named — the way both
 * show a size run as one product with choices.
 *
 * ── AS THE SHOP SELLS IT ────────────────────────────────────────────────────
 * Only products on sale on the site: published, priced (`Product::find()`).
 * Not one behind a password, though its page sells it: it is for the people
 * given the password, and neither Google nor Meta can open the page. Each price is the one the page charges: its regular price, and the sale
 * price with its days while one is set. In stock, sold out or on backorder as
 * the counts say. Brand is the business's name (Settings → Shop), or the
 * site's; there is no barcode, so `g:identifier_exists` says so.
 */
final class ProductFeed
{
    private const NS = 'http://base.google.com/ns/1.0';

    /** The feed, as the XML both read. */
    public static function xml(): string
    {
        $site  = \Cms\SEO\Head::globalTokens()['site_name'];
        $brand = trim((string) (\Cms\Models\Option::get('commerce_business_name') ?? '')) ?: $site;

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:g', self::NS);
        $doc->appendChild($rss);
        $channel = $doc->createElement('channel');
        $rss->appendChild($channel);
        foreach (['title' => $site, 'link' => Url::page(''), 'description' => 'Products from ' . $site] as $tag => $text) {
            $channel->appendChild($doc->createElement($tag))->appendChild($doc->createTextNode($text));
        }

        foreach (self::items($brand) as $item) {
            $node = $doc->createElement('item');
            foreach ($item as $field => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $el = $doc->createElementNS(self::NS, 'g:' . $field);
                $el->appendChild($doc->createTextNode((string) $value));
                $node->appendChild($el);
            }
            $channel->appendChild($node);
        }

        return (string) $doc->saveXML();
    }

    /**
     * Every item: each product sold as itself, each option of one sold in
     * options.
     *
     * @return list<array<string, string>>
     */
    public static function items(string $brand): array
    {
        $out = [];
        foreach (Registry::all() as $key => $type) {
            if (!Product::isSellableType((string) $key) || (string) ($type['public_slug'] ?? '') === '') {
                continue;
            }
            $stmt = Database::getInstance()->prepare(
                'SELECT id FROM posts WHERE post_type = :t AND ' . \Cms\Models\Post::live() . ' ORDER BY created_at DESC, id DESC'
            );
            $stmt->execute([':t' => (string) $key]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $product = Product::find((int) $id);
                if ($product === null) {
                    continue;
                }
                foreach (self::itemsOf($product, (string) $type['public_slug'], $brand) as $item) {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /**
     * One product's items.
     *
     * @return list<array<string, string>>
     */
    private static function itemsOf(array $product, string $publicSlug, string $brand): array
    {
        $id    = (int) $product['id'];
        $post  = \Cms\Models\Post::find($id);
        $meta  = Meta::getAll($id);
        $link  = Url::page(trim($publicSlug, '/') . '/' . (string) $product['slug']);
        $image = (int) ($post['featured_image_id'] ?? 0) > 0 ? Media::getUrl((int) $post['featured_image_id']) : '';
        $more  = array_values(array_filter(array_map(static fn (int $m): string => Media::getUrl($m), $product['gallery'] ?? [])));
        $text  = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($post['excerpt'] ?? '') !== '' ? (string) $post['excerpt'] : (string) ($post['content'] ?? ''))) ?? '');
        $days  = self::saleDays((string) ($meta[Product::SALE_FROM] ?? ''), (string) ($meta[Product::SALE_TO] ?? ''));
        $base  = [
            'title'              => (string) $product['title'],
            'description'        => mb_substr($text !== '' ? $text : (string) $product['title'], 0, 5000),
            'link'               => $link,
            'image_link'         => $image,
            'additional_image_link' => implode(',', array_slice($more, 0, 10)),
            'brand'              => $brand,
            'condition'          => 'new',
            'identifier_exists'  => 'no',
            'product_type'       => self::categories($id, (string) $product['post_type']),
        ];

        // `array_replace()`, NOT `+`: an array union keeps the LEFT value, so an
        // option's own title and photo would never replace the product's.
        if ($product['variations'] === []) {
            $sku = (string) $product['sku'];
            return [array_replace(
                ['id' => $sku !== '' ? $sku : 'post-' . $id],
                $base,
                self::money((int) $product['regular_price'], $product['on_sale'] ? (int) $product['price'] : null, $days),
                ['availability' => self::availability((string) $product['stock'], $product['stock_left'])]
            )];
        }

        $axes  = Variation::optionTaxonomies((string) $product['post_type']);
        $items = [];
        foreach ($product['variations'] as $v) {
            [$regular, $now] = Variation::prices($v, $product);
            $terms = Variation::termsOf($v);
            $named = [];
            foreach ($axes as $tax => $axis) {
                $term = isset($terms[$tax]) ? \Cms\Models\Term::find((int) $terms[$tax]) : null;
                if ($term !== null) {
                    $named[mb_strtolower((string) ($axis['label_single'] ?? $axis['label'] ?? $tax))] = (string) $term['name'];
                }
            }
            $own     = (int) ($v['image_id'] ?? 0);
            $items[] = array_replace(
                [
                    'id'            => (string) $v['sku'] !== '' ? (string) $v['sku'] : 'option-' . (int) $v['id'],
                    'item_group_id' => (string) $product['sku'] !== '' ? (string) $product['sku'] : 'post-' . $id,
                ],
                $base,
                [
                    'title'      => (string) $product['title'] . ' — ' . (string) $v['label'],
                    'image_link' => $own > 0 ? Media::getUrl($own) : $image,
                    'size'       => $named['size'] ?? '',
                    'color'      => $named['colour'] ?? $named['color'] ?? '',
                ],
                self::money($regular, $now < $regular ? $now : null, $days),
                ['availability' => self::availability((string) ($v['stock_status'] ?? ''), Variation::available($v))]
            );
        }

        return $items;
    }

    /**
     * The price and, while a sale is set, the sale price and its days, as Google
     * writes them: "38.00 CAD".
     *
     * @return array<string, string>
     */
    private static function money(int $regular, ?int $sale, string $days): array
    {
        $code = Money::currency();

        return [
            'price'                      => Money::edit($regular) . ' ' . $code,
            'sale_price'                 => $sale !== null ? Money::edit($sale) . ' ' . $code : '',
            'sale_price_effective_date'  => $sale !== null ? $days : '',
        ];
    }

    /** "in_stock", "out_of_stock" or "backorder", as the counts and the status word say. */
    private static function availability(string $status, ?int $left): string
    {
        if ($status === Product::ON_BACKORDER) {
            return 'backorder';
        }

        return $status === Product::OUT_OF_STOCK || ($left !== null && $left <= 0) ? 'out_of_stock' : 'in_stock';
    }

    /** A sale's days as Google reads them, both ends in the site's zone; '' when it has none. */
    private static function saleDays(string $from, string $to): string
    {
        if ($from === '' && $to === '') {
            return '';
        }
        $zone  = \Cms\Helpers\Dates::zone();
        $start = $from !== '' ? new \DateTimeImmutable($from . ' 00:00:00', $zone) : new \DateTimeImmutable('today', $zone);
        $end   = $to !== '' ? new \DateTimeImmutable($to . ' 23:59:59', $zone) : $start->modify('+1 year');

        return $start->format('Y-m-d\TH:i:sP') . '/' . $end->format('Y-m-d\TH:i:sP');
    }

    /** The product's categories, as one "A > B" line, from the type's first taxonomy that is not an option. */
    private static function categories(int $postId, string $type): string
    {
        foreach (\Cms\ContentType\TaxonomyRegistry::getForContentType($type) as $key => $tax) {
            if (empty($tax['is_option'])) {
                $names = array_map(static fn (array $t): string => (string) $t['name'], \Cms\Models\Term::getPostTerms($postId, (string) $key));
                if ($names !== []) {
                    return implode(', ', $names);
                }
            }
        }

        return '';
    }
}
