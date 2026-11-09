<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\ContentType\TaxonomyRegistry;
use Cms\Database\Database;
use Cms\Models\Meta;
use Cms\Models\Post;
use Cms\Models\Revision;
use Cms\Models\Term;
use PDO;

/**
 * A TYPE'S PRODUCTS AS A SPREADSHEET, OUT AND BACK IN.
 *
 * ── THE COLUMNS SHOPS ALREADY USE ───────────────────────────────────────────
 * One row for each product and one for each of its options, under the column
 * names a shop's product export commonly carries: Type (simple, variable,
 * variation), SKU, Name, Regular price, Sale price, Stock, Categories, and
 * "Attribute 1 name" with "Attribute 1 value(s)" for what an option is made
 * of. A list kept in a spreadsheet, or exported from another shop, comes in as
 * it is, and this shop's goes out the same way.
 *
 * ── MATCHED BY SKU ──────────────────────────────────────────────────────────
 * A row whose SKU a product of this type already has updates that product, and
 * any other row is a new one. An option row names its product in Parent, by
 * SKU or by the file's own ID ("id:12"), and is matched within it by what it
 * is made of, as the product's own Options panel matches it.
 *
 * ── WHAT THE FILE DOES NOT SAY, THE UPDATE DOES NOT TOUCH ───────────────────
 * Only the columns a file has change a product it updates, and an option the
 * file does not list is kept. A cell left empty in a column the file has
 * empties that value: an empty Sale price ends the sale.
 *
 * ── PRICED ONCE WHERE THE OPTIONS AGREE ─────────────────────────────────────
 * A spreadsheet prices every option; this shop prices a product once and
 * lets an option differ. So a product's price is the one most of its options
 * share, unless its own row gives one, and only an option priced differently
 * keeps a price of its own.
 *
 * ── NEW PRODUCTS ARRIVE PRIVATE ─────────────────────────────────────────────
 * As everything imported does (`Content\Import`): a file publishing fifty
 * products on upload is not a recoverable mistake. A product updated keeps its
 * status, so the Published column is not read.
 *
 * ── NOTHING IS FETCHED ──────────────────────────────────────────────────────
 * An image address on this site is the photo it names. One anywhere else is
 * listed as not brought in, and never downloaded: the CMS calls no third party.
 *
 * ── PLANNED, THEN APPLIED ───────────────────────────────────────────────────
 * `plan()` reads the file and says what it would create, update and skip,
 * writing nothing; `apply()` reads the same file again and does it. A count it
 * changes is a line in the stock history, as Imported.
 */
final class ProductSheet
{
    /** The columns written for every type, in this order; one pair per option taxonomy follows. */
    private const HEAD = ['ID', 'Type', 'SKU', 'Name', 'Published', 'Short description', 'Description',
        'Date sale price starts', 'Date sale price ends', 'In stock?', 'Stock', 'Sale price', 'Regular price',
        'Categories', 'Tags', 'Images', 'Parent'];

    /** A cell a spreadsheet would read as a formula starts with one of these. */
    private const FORMULA = "=+-@\t\r";

    // ── Out ─────────────────────────────────────────────────────────────────

    /** Every product of this type that is not in the trash, each followed by its options. */
    public static function export(string $typeKey): string
    {
        $axes          = self::axes($typeKey);
        [$cats, $tags] = self::filing($typeKey);

        $head = self::HEAD;
        $n    = 1;
        foreach ($axes as $axis) {
            $head[] = 'Attribute ' . $n . ' name';
            $head[] = 'Attribute ' . $n . ' value(s)';
            $n++;
        }

        $out = fopen('php://temp', 'r+');
        fputcsv($out, $head, ',', '"', '');
        foreach (self::posts($typeKey) as $post) {
            $id      = (int) $post['id'];
            $meta    = Meta::getAll($id);
            $options = Variation::forProduct($id);
            $price   = Money::parse((string) ($meta[Product::PRICE] ?? ''));
            $sale    = Money::parse((string) ($meta[Product::SALE_PRICE] ?? ''));
            $sku     = trim((string) ($meta[Product::SKU] ?? ''));
            $shown   = in_array((string) $post['status'], ['published', 'password'], true) ? '1' : '-1';

            $row = [
                (string) $id, $options === [] ? 'simple' : 'variable', $sku, (string) $post['title'], $shown,
                (string) ($post['excerpt'] ?? ''), (string) ($post['content'] ?? ''),
                (string) ($meta[Product::SALE_FROM] ?? ''), (string) ($meta[Product::SALE_TO] ?? ''),
                self::inStockCell((string) ($meta[Product::STOCK] ?? '')),
                Stock::isManaged($meta) ? (string) (int) ($meta[Stock::QTY] ?? 0) : '',
                $sale !== null ? Money::edit($sale) : '', $price !== null ? Money::edit($price) : '',
                $cats !== null ? self::termNames($id, $cats) : '', $tags !== null ? self::termNames($id, $tags) : '',
                implode(', ', array_map(
                    static fn (int $m): string => \Cms\Models\Media::getUrl($m),
                    array_values(array_filter(array_merge([(int) ($post['featured_image_id'] ?? 0)],
                        array_map('intval', explode(',', (string) ($meta[Product::GALLERY] ?? '')))), static fn (int $m): bool => $m > 0))
                )),
                '',
            ];
            foreach ($axes as $tax => $axis) {
                $used = [];
                foreach ($options as $o) {
                    $termId = Variation::termsOf($o)[$tax] ?? 0;
                    if ($termId > 0 && ($term = Term::find($termId)) !== null) {
                        $used[$termId] = (string) $term['name'];
                    }
                }
                $row[] = $used !== [] ? $axis['name'] : '';
                $row[] = implode(', ', $used);
            }
            fputcsv($out, array_map([self::class, 'cell'], $row), ',', '"', '');

            // ── ITS OPTIONS, each priced as it sells: its own, or the product's ──
            foreach ($options as $o) {
                $own   = $o['price'] !== null;
                $terms = Variation::termsOf($o);
                $vrow  = [
                    '', 'variation', (string) $o['sku'], (string) $post['title'] . ' - ' . (string) $o['label'], $shown, '', '',
                    '', '', self::inStockCell((string) $o['stock_status']),
                    !empty($o['manage_stock']) ? (string) (int) $o['stock_qty'] : '',
                    $own ? ($o['sale_price'] !== null ? Money::edit((int) $o['sale_price']) : '') : ($sale !== null ? Money::edit($sale) : ''),
                    $own ? Money::edit((int) $o['price']) : ($price !== null ? Money::edit($price) : ''),
                    '', '', (int) ($o['image_id'] ?? 0) > 0 ? \Cms\Models\Media::getUrl((int) $o['image_id']) : '',
                    $sku !== '' ? $sku : 'id:' . $id,
                ];
                foreach ($axes as $tax => $axis) {
                    $term   = isset($terms[$tax]) ? Term::find((int) $terms[$tax]) : null;
                    $vrow[] = $term !== null ? $axis['name'] : '';
                    $vrow[] = $term !== null ? (string) $term['name'] : '';
                }
                fputcsv($out, array_map([self::class, 'cell'], $vrow), ',', '"', '');
            }
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    // ── In ──────────────────────────────────────────────────────────────────

    /**
     * What importing this file into this type would do, writing nothing.
     *
     * @return array{ok: bool, error?: string, plan?: array<string, mixed>}
     */
    public static function plan(string $csv, string $typeKey): array
    {
        $read = self::read($csv, $typeKey);
        if ($read['error'] !== null) {
            return ['ok' => false, 'error' => $read['error']];
        }
        $entries = [];
        foreach ($read['products'] as $p) {
            $entries[] = [
                'type'   => $typeKey,
                'slug'   => $p['slug'],
                'title'  => $p['title'],
                'action' => $p['existing'] !== null ? 'update' : 'create',
                'detail' => $p['options'] !== null ? count($p['options']) . ' option' . (count($p['options']) === 1 ? '' : 's') : '',
            ];
        }

        return ['ok' => true, 'plan' => [
            'sheet'    => true,
            'pages'    => [],
            'entries'  => $entries,
            'settings' => [],
            'skipped'  => $read['skipped'],
            'notes'    => $read['notes'],
        ]];
    }

    /**
     * Do it: each product created or updated whole, or skipped and said.
     *
     * @return array{sheet: bool, created: int, updated: int, overwritten: int, skipped: int, settings: int, log: list<string>}
     */
    public static function apply(string $csv, string $typeKey, int $userId, string $by): array
    {
        $out  = ['sheet' => true, 'created' => 0, 'updated' => 0, 'overwritten' => 0, 'skipped' => 0, 'settings' => 0, 'log' => []];
        $read = self::read($csv, $typeKey);
        if ($read['error'] !== null) {
            $out['log'][] = $read['error'];
            return $out;
        }
        foreach ($read['skipped'] as $s) {
            $out['skipped']++;
            $out['log'][] = 'skipped ' . $s['what'] . ' — ' . $s['why'];
        }
        [$cats, $tags] = self::filing($typeKey);

        $db = Database::getInstance();
        foreach ($read['products'] as $p) {
            $db->beginTransaction();
            try {
                $id     = $p['existing'];
                $before = $id !== null ? Stock::countsOf($id) : [];
                if ($id === null) {
                    $id = Post::create([
                        'post_type' => $typeKey,
                        'title'     => $p['title'],
                        'slug'      => Post::generateSlug($p['title']),
                        'content'   => (string) ($p['fields']['content'] ?? ''),
                        'excerpt'   => (string) ($p['fields']['excerpt'] ?? ''),
                        'status'    => 'private',
                        'author_id' => $userId,
                    ] + (($p['fields']['featured_image_id'] ?? null) !== null ? ['featured_image_id' => $p['fields']['featured_image_id']] : []));
                } else {
                    // REVERSIBLE LIKE A SAVE: the revision is taken first.
                    Revision::snapshot(Revision::TYPE_POST, $id, $userId);
                    Revision::prune(Revision::TYPE_POST, $id);
                    if ($p['fields'] !== []) {
                        Post::update($id, $p['fields']);
                    }
                }
                foreach ($p['meta'] as $key => $value) {
                    Meta::set($id, $key, $value);
                }
                foreach (['categories' => $cats, 'tags' => $tags] as $column => $tax) {
                    if ($tax !== null && $p['terms'][$column] !== null) {
                        $ids     = array_map(static fn (string $name): int => self::termFor($tax, $name), $p['terms'][$column]);
                        $refused = Term::countViolation($tax, $ids);
                        if ($refused === null) {
                            Term::setPostTerms($id, $tax, $ids);
                        } else {
                            $out['log'][] = 'kept the ' . $column . ' of ' . $p['what'] . ' as they were — ' . $refused;
                        }
                    }
                }
                if ($p['options'] !== null) {
                    Variation::sync($id, self::optionRows($id, $p['options'], $p['existing'] !== null));
                }
                StockLog::edited($before, Stock::countsOf($id), $by, 'imported');
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                $out['skipped']++;
                $out['log'][] = 'skipped ' . $p['what'] . ' — it could not be written: ' . $e->getMessage();
                continue;
            }
            if ($p['existing'] === null) {
                $out['created']++;
                $out['log'][] = 'created ' . $typeKey . ' /' . (string) (Post::find($id)['slug'] ?? $p['slug']) . ($p['options'] !== null ? ' with ' . count($p['options']) . ' option(s)' : '');
            } else {
                $out['updated']++;
                $out['log'][] = 'updated ' . $typeKey . ' /' . $p['slug'] . ($p['options'] !== null ? ', ' . count($p['options']) . ' option(s) from the file' : '');
            }
        }

        return $out;
    }

    // ── Reading a file ──────────────────────────────────────────────────────

    /**
     * The file read for this type: each product it would write, worked out
     * whole, and each row it would not, with why.
     *
     * @return array{error: ?string, products: list<array<string, mixed>>, skipped: list<array{what: string, why: string}>, notes: list<string>}
     */
    private static function read(string $csv, string $typeKey): array
    {
        $result = ['error' => null, 'products' => [], 'skipped' => [], 'notes' => []];
        $fail   = static fn (string $why): array => ['error' => $why] + $result;

        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (trim($csv) === '') {
            return $fail('That file is empty.');
        }
        // A COMMA, OR THE SEMICOLON a spreadsheet set to a European locale saves with.
        $first = strtok($csv, "\n");
        $delim = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : ',';
        $fh    = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);

        $head = fgetcsv($fh, null, $delim, '"', '');
        if (!is_array($head)) {
            return $fail('That file has no first row to name its columns.');
        }
        $col   = [];
        $attrs = [];
        $notes = [];
        foreach ($head as $i => $name) {
            $key = mb_strtolower(trim((string) $name));
            if (preg_match('/^attribute (\d+) (name|value\(s\))$/', $key, $m)) {
                $attrs[(int) $m[1]][$m[2] === 'name' ? 'name' : 'value'] = $i;
            } elseif (in_array($key, array_map('mb_strtolower', self::HEAD), true)) {
                $col[$key] = $i;
            } elseif ($key !== '' && !preg_match('/^attribute \d+ (visible|global|default)$/', $key)) {
                $notes[] = trim((string) $name);
            }
        }
        if (!isset($col['name']) && !isset($col['sku'])) {
            return $fail('The first row names the columns, and none of them is Name or SKU. A spreadsheet of products needs one of the two.');
        }
        if ($notes !== []) {
            $result['notes'][] = 'Not read: ' . implode(', ', $notes) . '.';
        }
        if (isset($col['published'])) {
            $result['notes'][] = 'Published is not read: new products come in Private, and a product updated keeps its status.';
        }

        $cellOf = $cell = static function (array $r, string $key) use ($col): ?string {
            if (!isset($col[$key])) {
                return null;
            }
            $v = trim((string) ($r[$col[$key]] ?? ''));
            // A CELL KEPT FROM BEING A FORMULA on the way out comes back as typed.
            return strlen($v) > 1 && $v[0] === "'" && strpbrk($v[1], self::FORMULA) !== false ? substr($v, 1) : $v;
        };

        // ── EVERY ROW, PRODUCTS FIRST AND THEIR OPTIONS AFTER ───────────────
        $products = [];
        $byFileId = [];
        $bySku    = [];
        $children = [];
        $line     = 1;
        while (($r = fgetcsv($fh, null, $delim, '"', '')) !== false) {
            $line++;
            if ($r === [null] || implode('', array_map('strval', $r)) === '') {
                continue;
            }
            $type = mb_strtolower(trim(explode(',', (string) ($cell($r, 'type') ?? ''))[0]));
            if ($type === '') {
                $type = ($cell($r, 'parent') ?? '') !== '' ? 'variation' : 'simple';
            }
            if ($type === 'variation') {
                $children[] = ['line' => $line, 'r' => $r];
                continue;
            }
            if (!in_array($type, ['simple', 'variable'], true)) {
                $result['skipped'][] = ['what' => 'line ' . $line, 'why' => 'its Type, "' . $type . '", is not simple, variable or variation'];
                continue;
            }
            $idx        = count($products);
            $products[] = ['line' => $line, 'r' => $r, 'children' => [], 'stub' => false];
            if (($fileId = $cell($r, 'id') ?? '') !== '') {
                $byFileId[$fileId] = $idx;
            }
            if (($sku = $cell($r, 'sku') ?? '') !== '') {
                $bySku[mb_strtolower($sku)] = $idx;
            }
        }
        foreach ($children as $child) {
            $parent = $cell($child['r'], 'parent') ?? '';
            $idx    = str_starts_with(mb_strtolower($parent), 'id:') ? ($byFileId[trim(substr($parent, 3))] ?? null)
                                                                 : ($bySku[mb_strtolower($parent)] ?? null);
            // AN OPTION FOR A PRODUCT ALREADY HERE, its row not in the file.
            if ($idx === null && $parent !== '' && !str_starts_with(mb_strtolower($parent), 'id:')
                && self::bySku($typeKey, $parent) !== null) {
                $idx                         = count($products);
                $products[]                  = ['line' => $child['line'], 'r' => [], 'children' => [], 'stub' => true, 'sku' => $parent];
                $bySku[mb_strtolower($parent)] = $idx;
            }
            if ($idx === null) {
                $result['skipped'][] = ['what' => 'the option on line ' . $child['line'],
                    'why' => $parent === '' ? 'its Parent is empty, so there is no product to add it to'
                                           : 'its Parent, "' . $parent . '", is not a product in the file or in this shop'];
                continue;
            }
            $products[$idx]['children'][] = $child;
        }

        // ── EACH PRODUCT, WORKED OUT WHOLE ──────────────────────────────────
        $axes          = self::axes($typeKey);
        [$cats, $tags] = self::filing($typeKey);
        $foreign       = 0;
        $seenSlugs     = [];
        foreach ($products as $p) {
            // A PRODUCT NAMED ONLY AS ITS OPTIONS' PARENT has no row of its own
            // here, so none of its own columns change.
            $r    = $p['stub'] ? [] : $p['r'];
            $cell = $p['stub'] ? static fn (array $r, string $key): ?string => null : $cellOf;
            $sku  = $p['stub'] ? (string) $p['sku'] : (string) ($cell($r, 'sku') ?? '');
            $name = $p['stub'] ? null : $cell($r, 'name');
            $what = $name !== null && $name !== '' ? '"' . $name . '"' : ($sku !== '' ? 'the product ' . $sku : 'line ' . $p['line']);
            $skip = static function (string $why) use (&$result, $what): void {
                $result['skipped'][] = ['what' => $what, 'why' => $why];
            };

            $held = $sku !== '' ? self::bySku($typeKey, $sku) : null;
            if ($held !== null) {
                $heldMeta      = Meta::getAll((int) $held['id']);
                $held['price'] = Money::parse((string) ($heldMeta[Product::PRICE] ?? ''));
                $held['sale']  = Money::parse((string) ($heldMeta[Product::SALE_PRICE] ?? ''));
            }
            if ($held !== null && $held['status'] === 'trash') {
                $skip('the product with the SKU ' . $sku . ' is in the trash — restore it to update it, or empty the trash to import this as new');
                continue;
            }
            if ($held === null && ($name === null || $name === '')) {
                $skip('it has no Name, and no product here has its SKU');
                continue;
            }

            // ── ITS OWN COLUMNS ──
            $fields = [];
            $meta   = [];
            if ($name !== null && $name !== '') {
                $fields['title'] = mb_substr($name, 0, 255);
            }
            foreach (['description' => 'content', 'short description' => 'excerpt'] as $column => $field) {
                if (($v = $cell($r, $column)) !== null) {
                    $fields[$field] = $v;
                }
            }
            if (!$p['stub'] && ($held === null || $sku !== '')) {
                $meta[Product::SKU] = $sku;
            }
            $regular = $cell($r, 'regular price');
            $saleRaw = $cell($r, 'sale price');
            $own     = ['price' => null, 'sale' => null];
            foreach (['regular price' => [$regular, 'price'], 'sale price' => [$saleRaw, 'sale']] as $column => [$v, $slot]) {
                if ($v !== null && $v !== '') {
                    $own[$slot] = Money::parse($v);
                    if ($own[$slot] === null) {
                        $skip('its ' . ucfirst($column) . ', "' . $v . '", is not an amount');
                        continue 2;
                    }
                }
            }
            foreach (['date sale price starts' => Product::SALE_FROM, 'date sale price ends' => Product::SALE_TO] as $column => $key) {
                if (($v = $cell($r, $column)) !== null) {
                    $day = $v === '' ? '' : substr($v, 0, 10);
                    if ($day !== '' && \DateTimeImmutable::createFromFormat('!Y-m-d', $day) === false) {
                        $skip('its ' . ucfirst($column) . ', "' . $v . '", is not a date such as 2026-11-20');
                        continue 2;
                    }
                    $meta[$key] = $day;
                }
            }
            $stock = self::stockOf($cell($r, 'stock'), $cell($r, 'in stock?'));
            if ($stock === false) {
                $skip('its Stock, "' . (string) $cell($r, 'stock') . '", is not a whole number, 0 or more');
                continue;
            }

            // ── ITS PHOTOS, from this site only ──
            // An empty cell takes the photos off; addresses all elsewhere,
            // never fetched, leave the photos it has.
            if (($images = $cell($r, 'images')) !== null) {
                $urls = array_values(array_filter(array_map('trim', explode(',', $images))));
                $ids  = [];
                foreach ($urls as $url) {
                    if (($m = self::mediaAt($url)) !== null) {
                        $ids[] = $m;
                    } else {
                        $foreign++;
                    }
                }
                if ($urls === [] || $ids !== []) {
                    $fields['featured_image_id'] = $ids[0] ?? null;
                    $meta[Product::GALLERY]      = implode(',', array_slice($ids, 1));
                }
            }

            // ── ITS CATEGORIES AND TAGS, BY NAME ──
            $terms = ['categories' => null, 'tags' => null];
            foreach (['categories' => $cats, 'tags' => $tags] as $column => $tax) {
                if (($v = $cell($r, $column)) !== null && $tax !== null) {
                    $terms[$column] = array_values(array_unique(array_filter(array_map(
                        static fn (string $t): string => trim((string) preg_replace('/^.*>\s*/', '', $t)),
                        explode(',', $v)
                    ))));
                }
            }

            // ── ITS OPTIONS ──
            $options = null;
            if ($p['children'] !== []) {
                $options = [];
                foreach ($p['children'] as $child) {
                    $o = self::optionOf($child, $cellOf, $attrs, $axes);
                    if (is_string($o)) {
                        $result['skipped'][] = ['what' => 'the option on line ' . $child['line'], 'why' => $o];
                        continue;
                    }
                    if (isset($options[mb_strtolower($o['label'])])) {
                        $result['skipped'][] = ['what' => 'the option on line ' . $child['line'],
                            'why' => 'its product already has "' . $o['label'] . '" from an earlier line'];
                        continue;
                    }
                    $options[mb_strtolower($o['label'])] = $o;
                }
                $options = array_values($options);
            }

            // ── PRICED ONCE WHERE THE OPTIONS AGREE ──
            if ($options !== null && $options !== []) {
                $pPrice = $own['price'] ?? ($p['stub'] ? $held['price'] : self::most(array_map(static fn (array $o): ?int => $o['price'], $options)));
                $pSale  = $saleRaw !== null && $saleRaw !== '' ? $own['sale']
                        : ($p['stub'] ? $held['sale'] : self::most(array_map(static fn (array $o): ?int => $o['sale'],
                              array_filter($options, static fn (array $o): bool => $o['price'] === $pPrice))));
                foreach ($options as $i => $o) {
                    $inherit                 = $o['price'] === null || ($o['price'] === $pPrice && $o['sale'] === $pSale);
                    $options[$i]['ownPrice'] = $inherit ? null : $o['price'];
                    $options[$i]['ownSale']  = $inherit ? null : $o['sale'];
                }
                // A PARENT NAMED ONLY BY ITS OPTIONS keeps its own prices.
                if ($pPrice !== null && !$p['stub']) {
                    $meta[Product::PRICE]      = Money::edit($pPrice);
                    $meta[Product::SALE_PRICE] = $pSale !== null ? Money::edit($pSale) : '';
                }
            } else {
                if ($regular !== null) {
                    $meta[Product::PRICE] = $own['price'] !== null ? Money::edit($own['price']) : '';
                }
                if ($saleRaw !== null) {
                    $meta[Product::SALE_PRICE] = $own['sale'] !== null ? Money::edit($own['sale']) : '';
                }
            }
            if ($held === null && !isset($meta[Product::PRICE])) {
                $skip('it has no Regular price, and a product for sale needs one');
                continue;
            }
            if ($stock !== null) {
                $meta += $stock;
            }

            // ── A SKU ANOTHER PRODUCT HAS, the rule the product form keeps ──
            $problem = Product::skuProblems($sku, array_map(static fn (array $o): array => ['label' => $o['label'], 'sku' => $o['sku']],
                $options ?? []), $held !== null ? (int) $held['id'] : null)[0] ?? '';
            if ($problem !== '') {
                $skip($problem);
                continue;
            }

            $slug = $held !== null ? (string) $held['slug'] : Post::generateSlug((string) $name);
            if ($held === null && isset($seenSlugs[$slug])) {
                $slug = $slug . '-' . count($seenSlugs);
            }
            $seenSlugs[$slug]     = true;
            $result['products'][] = [
                'what'     => $what,
                'existing' => $held !== null ? (int) $held['id'] : null,
                'slug'     => $slug,
                'title'    => (string) ($fields['title'] ?? $held['title'] ?? $sku),
                'fields'   => $fields,
                'meta'     => $meta,
                'terms'    => $terms,
                'options'  => $options,
            ];
        }
        if ($foreign > 0) {
            $result['notes'][] = $foreign . ' image' . ($foreign === 1 ? '' : 's') . ' from other sites not brought in: this site does not fetch'
                . ' from elsewhere. Add them in Files and media, then to the products.';
        }

        return $result;
    }

    /**
     * One option row: what it is made of, its code, prices and count; or the
     * sentence saying why it cannot be one.
     *
     * @param array<int, array{name?: int, value?: int}> $attrs
     * @param array<string, array{name: string, plural: string}> $axes
     * @return array<string, mixed>|string
     */
    private static function optionOf(array $child, callable $cell, array $attrs, array $axes): array|string
    {
        $r     = $child['r'];
        $made  = [];
        $loose = [];
        foreach ($attrs as $pair) {
            $name  = trim((string) ($r[$pair['name'] ?? -1] ?? ''));
            $value = trim((string) ($r[$pair['value'] ?? -1] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $tax = self::axisFor($axes, $name);
            if ($tax === null) {
                if ($axes !== []) {
                    return 'it is made of ' . $name . ', and this shop\'s options are made of '
                        . implode(' and ', array_column($axes, 'name'));
                }
                $loose[] = $value;
                continue;
            }
            $made[$tax] = $value;
        }
        // IN THE ORDER THE OPTIONS PANEL NAMES THEM: "M / Navy".
        $label = [];
        foreach (array_keys($axes) as $tax) {
            if (isset($made[$tax])) {
                $label[] = $made[$tax];
            }
        }
        $label = implode(' / ', array_merge($label, $loose));
        if ($label === '') {
            return 'it names no size, colour or other value to tell it from the product\'s other options';
        }
        $money = ['price' => null, 'sale' => null];
        foreach (['regular price' => 'price', 'sale price' => 'sale'] as $column => $slot) {
            $v = $cell($r, $column);
            if ($v !== null && $v !== '') {
                $money[$slot] = Money::parse($v);
                if ($money[$slot] === null) {
                    return 'its ' . ucfirst($column) . ', "' . $v . '", is not an amount';
                }
            }
        }
        $stock = self::stockOf($cell($r, 'stock'), $cell($r, 'in stock?'));
        if ($stock === false) {
            return 'its Stock, "' . (string) $cell($r, 'stock') . '", is not a whole number, 0 or more';
        }

        return [
            'label'  => mb_substr($label, 0, 120),
            'sku'    => (string) ($cell($r, 'sku') ?? ''),
            'price'  => $money['price'],
            'sale'   => $money['sale'],
            'terms'  => $made,
            'stock'  => $stock,
            'image'  => ($images = $cell($r, 'images')) !== null && $images !== '' ? self::mediaAt(trim(explode(',', $images)[0])) : null,
        ];
    }

    /**
     * The options as `Variation::sync()` takes them: each of the file's, as
     * this product's own option of the same label, and every option the file
     * does not list, as it is.
     *
     * @param list<array<string, mixed>> $options
     * @return list<array<string, mixed>>
     */
    private static function optionRows(int $postId, array $options, bool $existing): array
    {
        $have = [];
        foreach ($existing ? Variation::forProduct($postId) : [] as $v) {
            $have[mb_strtolower((string) $v['label'])] = $v;
        }
        $rows = [];
        foreach ($options as $o) {
            $v      = $have[mb_strtolower($o['label'])] ?? null;
            $terms  = [];
            foreach ($o['terms'] as $tax => $name) {
                $terms[$tax] = self::termFor($tax, (string) $name);
            }
            $counted = $o['stock'] !== null ? $o['stock'] : ($v !== null ? [
                Stock::MANAGE => (string) (int) $v['manage_stock'], Stock::QTY => (string) (int) $v['stock_qty'],
                Product::STOCK => (string) $v['stock_status'],
            ] : []);
            $rows[] = [
                'id'           => $v !== null ? (int) $v['id'] : 0,
                'label'        => $o['label'],
                'sku'          => $o['sku'] !== '' ? $o['sku'] : (string) ($v['sku'] ?? ''),
                'price'        => $o['ownPrice'] !== null ? Money::edit($o['ownPrice']) : '',
                'sale_price'   => $o['ownSale'] !== null ? Money::edit($o['ownSale']) : '',
                'terms'        => $terms,
                'manage_stock' => ($counted[Stock::MANAGE] ?? '') === '1' ? '1' : '',
                'stock_qty'    => (string) (int) ($counted[Stock::QTY] ?? 0),
                'stock_status' => (string) ($counted[Product::STOCK] ?? Product::IN_STOCK),
                'image_id'     => $o['image'] ?? (int) ($v['image_id'] ?? 0),
            ];
            unset($have[mb_strtolower($o['label'])]);
        }
        // AN OPTION THE FILE DOES NOT LIST IS KEPT, as it is.
        foreach ($have as $v) {
            $rows[] = [
                'id' => (int) $v['id'], 'label' => (string) $v['label'], 'sku' => (string) $v['sku'],
                'price' => $v['price'] !== null ? Money::edit((int) $v['price']) : '',
                'sale_price' => $v['sale_price'] !== null ? Money::edit((int) $v['sale_price']) : '',
                'terms' => Variation::termsOf($v), 'manage_stock' => !empty($v['manage_stock']) ? '1' : '',
                'stock_qty' => (string) (int) $v['stock_qty'], 'stock_status' => (string) $v['stock_status'],
                'image_id' => (int) ($v['image_id'] ?? 0),
            ];
        }

        return $rows;
    }

    // ── Small things ────────────────────────────────────────────────────────

    /**
     * The Stock and In stock? cells as the meta they set: counted when Stock
     * has a number, not counted when it is empty, and false when it is neither.
     *
     * @return array<string, string>|null|false
     */
    private static function stockOf(?string $stock, ?string $inStock): array|null|false
    {
        $meta = [];
        if ($stock !== null) {
            if ($stock !== '' && !preg_match('/^\d{1,7}$/', $stock)) {
                return false;
            }
            $meta[Stock::MANAGE] = $stock === '' ? '' : '1';
            $meta[Stock::QTY]    = $stock === '' ? '0' : (string) (int) $stock;
        }
        if ($inStock !== null || $stock !== null) {
            $word = mb_strtolower((string) $inStock);
            $meta[Product::STOCK] = match (true) {
                $word === 'backorder'                       => Product::ON_BACKORDER,
                $stock !== null && $stock !== ''            => (int) $stock > 0 ? Product::IN_STOCK : Product::OUT_OF_STOCK,
                in_array($word, ['0', 'no', 'false'], true) => Product::OUT_OF_STOCK,
                default                                     => Product::IN_STOCK,
            };
        }

        return $meta === [] ? null : $meta;
    }

    /** "1", "0" or "backorder", as In stock? is written. */
    private static function inStockCell(string $status): string
    {
        return match ($status) {
            Product::OUT_OF_STOCK => '0',
            Product::ON_BACKORDER => 'backorder',
            default               => '1',
        };
    }

    /**
     * This type's option taxonomies, in the Options panel's order: what an
     * "Attribute" column names.
     *
     * @return array<string, array{name: string, plural: string}>
     */
    private static function axes(string $typeKey): array
    {
        $out = [];
        foreach (Variation::optionTaxonomies($typeKey) as $key => $tax) {
            $out[(string) $key] = [
                'name'   => (string) ($tax['label_single'] ?? $tax['label'] ?? $key),
                'plural' => (string) ($tax['label'] ?? $key),
            ];
        }

        return $out;
    }

    /** The option taxonomy an attribute's name means: "Size", "Sizes" or "pa_size". */
    private static function axisFor(array $axes, string $name): ?string
    {
        $want = mb_strtolower(trim((string) preg_replace('/^pa_/', '', $name)));
        foreach ($axes as $tax => $axis) {
            if (in_array($want, [mb_strtolower($axis['name']), mb_strtolower($axis['plural']), mb_strtolower($tax)], true)) {
                return $tax;
            }
        }

        return null;
    }

    /**
     * The taxonomies Categories and Tags fill: of this type's that are not
     * options, the one named for categories, else the first that nests; and
     * the one named for tags, else the first other that does not.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function filing(string $typeKey): array
    {
        $plain = array_filter(TaxonomyRegistry::getForContentType($typeKey), static fn (array $t): bool => empty($t['is_option']));
        $named = static function (string $pattern) use ($plain): ?string {
            foreach ($plain as $key => $t) {
                if (preg_match($pattern, (string) $key . ' ' . (string) ($t['label'] ?? ''))) {
                    return (string) $key;
                }
            }
            return null;
        };
        $cats = $named('/categor/i');
        foreach ($cats === null ? $plain : [] as $key => $t) {
            if (!empty($t['hierarchical'])) {
                $cats = (string) $key;
                break;
            }
        }
        $tags = $named('/\btags?\b/i');
        foreach ($tags === null ? $plain : [] as $key => $t) {
            if ((string) $key !== $cats && empty($t['hierarchical'])) {
                $tags = (string) $key;
                break;
            }
        }

        return [$cats, $tags !== $cats ? $tags : null];
    }

    /** A term of this taxonomy by its name, made when there is none. */
    private static function termFor(string $tax, string $name): int
    {
        foreach (Term::allForTaxonomy($tax) as $t) {
            if (mb_strtolower((string) $t['name']) === mb_strtolower($name)) {
                return (int) $t['id'];
            }
        }

        return Term::create(['taxonomy_key' => $tax, 'name' => $name, 'slug' => Term::generateSlug($name, $tax)]);
    }

    /** An entry's terms in one taxonomy, by name. */
    private static function termNames(int $postId, string $tax): string
    {
        return implode(', ', array_map(static fn (array $t): string => (string) $t['name'], Term::getPostTerms($postId, $tax)));
    }

    /** The product of this type with this SKU, in any status, or null. */
    private static function bySku(string $typeKey, string $sku): ?array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT p.id, p.slug, p.title, p.status FROM posts p
               JOIN post_meta m ON m.post_id = p.id AND m.meta_key = :k
              WHERE p.post_type = :t AND m.meta_value = :sku
           ORDER BY p.status = 'trash', p.id LIMIT 1"
        );
        $stmt->execute([':k' => Product::SKU, ':t' => $typeKey, ':sku' => $sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** The attachment an address on this site names, or null for anywhere else. */
    private static function mediaAt(string $url): ?int
    {
        $base = (defined('BASE_URL') ? BASE_URL : '') . '/uploads/';
        if ($url === '' || !str_starts_with($url, $base)) {
            return null;
        }
        $stmt = Database::getInstance()->prepare('SELECT id FROM attachments WHERE filename = :f LIMIT 1');
        $stmt->execute([':f' => rawurldecode(substr($url, strlen($base)))]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** The products of this type, not in the trash, in the list's order. */
    private static function posts(string $typeKey): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT id, title, slug, content, excerpt, status, featured_image_id FROM posts
              WHERE post_type = :t AND status != 'trash' ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute([':t' => $typeKey]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The value most of them share; the lowest of those tied. */
    private static function most(array $values): ?int
    {
        $count = [];
        foreach ($values as $v) {
            $count[$v === null ? '' : (string) $v] = ($count[$v === null ? '' : (string) $v] ?? 0) + 1;
        }
        if ($count === []) {
            return null;
        }
        $top  = max($count);
        $best = array_keys(array_filter($count, static fn (int $n): bool => $n === $top));
        sort($best, SORT_NUMERIC);

        return $best[0] === '' ? null : (int) $best[0];
    }

    /** A cell a person typed, kept from being read as a formula. */
    private static function cell(string $value): string
    {
        return $value !== '' && strpbrk($value[0], self::FORMULA) !== false ? "'" . $value : $value;
    }
}
