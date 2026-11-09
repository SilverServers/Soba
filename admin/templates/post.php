<?php

/**
 * A single entry — the news story, the event, the person, the membership.
 *
 * ── NO LABELS ON THE VALUES ─────────────────────────────────────────────────
 * A form's field names printed at a visitor — "When:", "Where:", "Link:",
 * "Email:", "SKU:", or the field group's own "Start Date & Time:" and "Event
 * URL:" — are wrong for somebody who came to read about a meeting.
 *
 * **Text speaks for itself.** A tooltip that paraphrases its label is noise,
 * and a label that paraphrases its value is the same noise. A date reads as a
 * date. An address reads as an address. Nobody needs the word "Location:" in
 * front of "Campus Activity Centre, Room 105", and printing it makes a public
 * page read like the form it was typed into.
 *
 * So the values stand on their own, in the order a person reads them, and the
 * archetype's labels stay where labels belong — on the edit screen.
 *
 * From Public\PostController: $post, $meta, and $archetypes via the Registry.
 */

$contentType = \Cms\ContentType\Registry::get($post['post_type']);
$archetypes  = $contentType['archetypes'] ?? ['post'];

// AS TYPED — a value stored before fields were stored plain is decoded here
// (`FieldRenderer::plain()`), and every print below escapes it once.
$val = static fn (string $key): string => trim(\Cms\ContentType\FieldRenderer::plain($meta[$key] ?? ''));

/**
 * The host, for link text.
 *
 * A raw `https://example.org/agm` printed as a sentence is a developer showing
 * their storage. The host is the part a person recognises, and it is derived
 * from the value rather than invented here — so there is still no copy in this
 * file for anyone to want to edit.
 */
$linkText = static function (string $url): string {
    $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');

    return $host !== '' ? preg_replace('/^www\./', '', $host) : $url;
};
?>
<article>
    <header class="post-header">
        <a href="<?= htmlspecialchars(BASE_URL . '/') ?>" class="post-header__back">&larr; Home</a>
        <h1><?= htmlspecialchars($post['title']) ?></h1>
        <?php
        /* A BYLINE BELONGS ON A STORY. Printed above everything, a membership
           tier would read "By admin — August 27, 2026" and a staff profile would
           credit the account that typed it, which is nobody's business and is
           the day the row was created rather than any fact about the person.
           The cards make the same call. */
        $isStory = !in_array('listing', $archetypes, true)
                && !in_array('product', $archetypes, true);
        ?>
        <?php if ($isStory && !empty($post['published_at'])): ?>
            <p class="post-header__meta">
                By <?= htmlspecialchars($post['author_name']) ?>
                &mdash;
                <?php // The theme's format, the SITE's day (Dates::format) — a
                      // late-evening post in Kamloops is already tomorrow in UTC. ?>
                <?= htmlspecialchars(\Cms\Helpers\Dates::format((string) $post['published_at'], 'F j, Y')) ?>
            </p>
        <?php endif; ?>
    </header>

    <?php
    /* THE ENTRY'S OWN PICTURE. The card on every list shows it, so the entry it
       leads to does too — a visitor who clicks a photo must not arrive at a page
       without it. Its alt text is the one typed in the media library. */
    $cover = \Cms\Models\Media::find((int) ($post['featured_image_id'] ?? 0));

    /* A PRODUCT, READ ONCE for the photo and the buy block below: its options
       can carry photos of their own, and `product.js` puts one here when the
       option is chosen. */
    $shopProduct = in_array('product', $archetypes, true) ? \Cms\Commerce\Product::fromPost($post) : null;
    ?>
    <?php if ($cover !== null): ?>
        <figure class="post-featured"<?= $shopProduct !== null ? ' data-product-photo' : '' ?>>
            <img src="<?= htmlspecialchars(\Cms\Models\Media::getUrl((int) $cover['id'])) ?>"
                 alt="<?= htmlspecialchars((string) ($cover['alt_text'] ?? '')) ?>"
                 <?php if ((int) ($cover['width'] ?? 0) > 0 && (int) ($cover['height'] ?? 0) > 0): ?>
                     width="<?= (int) $cover['width'] ?>" height="<?= (int) $cover['height'] ?>"
                 <?php endif; ?>>
        </figure>
    <?php endif; ?>

    <?php
    /* MORE PHOTOS — the featured one first, then the product's own, in its
       order. LINKS, so each opens without `product.js`; with it, a click puts
       the photo in the frame above instead. */
    $morePhotos = $shopProduct !== null && $cover !== null && $shopProduct['gallery'] !== []
        ? array_values(array_filter(array_map(
            static fn (int $id): ?array => \Cms\Models\Media::find($id),
            array_merge([(int) $cover['id']], $shopProduct['gallery'])
        )))
        : [];
    ?>
    <?php if (count($morePhotos) > 1): ?>
        <div class="product-thumbs">
            <?php foreach ($morePhotos as $n => $shot): ?>
                <?php $shotUrl = \Cms\Models\Media::getUrl((int) $shot['id']); ?>
                <a class="product-thumbs__item" href="<?= htmlspecialchars($shotUrl) ?>"
                   data-photo-thumb data-full="<?= htmlspecialchars($shotUrl) ?>"<?= $n === 0 ? ' aria-current="true"' : '' ?>>
                    <img src="<?= htmlspecialchars($shotUrl) ?>" alt="<?= htmlspecialchars((string) ($shot['alt_text'] ?? '')) ?>" loading="lazy">
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (in_array('event', $archetypes, true)): ?>
        <?php
        /* ONE DATE LINE, not two. A meeting that starts at 6:30 and ends at 8:30
           on the same evening would print its date twice, in full, on two rows —
           "September 10, 2026 at 6:30 PM" above "September 10, 2026 at 8:30 PM".
           A person writes that as a range, so the page does too, and only spells
           out the second date when the event genuinely crosses a day. */
        $start = $val('event_start_date') !== '' ? strtotime($val('event_start_date')) : null;
        $end   = $val('event_end_date') !== '' ? strtotime($val('event_end_date')) : null;

        $when = '';
        if ($start !== null) {
            $when = date('F j, Y', $start) . ' &middot; ' . date('g:i A', $start);
            if ($end !== null && $end > $start) {
                $when .= ' &ndash; ' . (date('Y-m-d', $end) === date('Y-m-d', $start)
                    ? date('g:i A', $end)
                    : date('F j, Y', $end) . ' &middot; ' . date('g:i A', $end));
            }
        }
        ?>
        <?php if ($when !== '' || $val('event_location') !== '' || $val('event_url') !== ''): ?>
            <div class="entry-meta entry-meta--event">
                <?php if ($when !== ''): ?>
                    <p class="entry-meta__when"><?= $when // built from dates above; no user input ?></p>
                <?php endif; ?>
                <?php if ($val('event_location') !== ''): ?>
                    <p class="entry-meta__where"><?= htmlspecialchars($val('event_location')) ?></p>
                <?php endif; ?>
                <?php if ($val('event_url') !== ''): ?>
                    <p><a href="<?= htmlspecialchars($val('event_url')) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($linkText($val('event_url'))) ?></a></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (in_array('listing', $archetypes, true)): ?>
        <?php if ($val('listing_subtitle') !== '' || $val('listing_email') !== ''
                  || $val('listing_phone') !== '' || $val('listing_website') !== ''): ?>
            <div class="entry-meta entry-meta--listing">
                <?php if ($val('listing_subtitle') !== ''): ?>
                    <p class="listing-subtitle"><?= htmlspecialchars($val('listing_subtitle')) ?></p>
                <?php endif; ?>
                <?php if ($val('listing_email') !== ''): ?>
                    <p><a href="mailto:<?= htmlspecialchars($val('listing_email')) ?>"><?= htmlspecialchars($val('listing_email')) ?></a></p>
                <?php endif; ?>
                <?php if ($val('listing_phone') !== ''): ?>
                    <?php /* A phone number on a page a visitor is holding in their
                             hand should dial. `tel:` keeps only the digits and a
                             leading `+`, so a number typed as "(250) 555-0134"
                             still works. */ ?>
                    <p><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $val('listing_phone')) ?? '') ?>"><?= htmlspecialchars($val('listing_phone')) ?></a></p>
                <?php endif; ?>
                <?php if ($val('listing_website') !== ''): ?>
                    <p><a href="<?= htmlspecialchars($val('listing_website')) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($linkText($val('listing_website'))) ?></a></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (in_array('product', $archetypes, true)): ?>
        <?php
        /* Availability, declared by the `product` archetype. Left unrendered,
           an editor could set a membership to "Out of Stock" and the page would
           not say so.

           `options` is the field's own declared map, so the page shows the words
           the edit screen offered rather than the stored key. That is data the
           owner controls, not copy typed into this file. */
        $stock  = $val('product_stock_status');
        $states = (array) (\Cms\ContentType\ContentTypeSchema::field(
            (string) $post['post_type'], 'product_stock_status')['options'] ?? []);

        /* THE PRICE COMES THROUGH `Commerce\Product`, NOT OFF THE META STRING.
           A `$` followed by `$val('product_price')` raw hard-codes a dollar sign
           and a template's own way of formatting money: an install set to GBP
           would show pounds priced in dollars, and `19.9` typed by the owner
           would render as "$19.9".

           `Product::fromPost()` (read once, above the photo) also decides what
           "on sale" means — a sale price ABOVE the regular price is not a sale,
           nor is one outside its days — and `priceHtmlParts()` turns a product
           sold in options into the range its options cost, with the regular
           range struck through when any of them is on sale.

           Null when the entry is not sellable — no readable price, or not
           published — and then the price block is simply absent. Stock and SKU
           still render, because those are facts about the thing even when it
           has no price yet. */
        $product  = $shopProduct;
        $price    = $product !== null ? \Cms\Commerce\Product::priceHtmlParts($product) : null;
        $variants = $product !== null ? $product['variations'] : [];
        $buyable  = array_values(array_filter(
            $variants,
            static fn (array $v): bool => \Cms\Commerce\Variation::isPurchasable($v)
        ));

        // ── A CHOOSER FOR EACH THING A CUSTOMER PICKS ───────────────────────
        // Size and Colour, when the options are made of them: the terms the
        // options use, in the taxonomy's own order. `product.js` shows these
        // and hides the combined select; without it the combined select is the
        // whole form, and still names a sold-out option rather than hiding it.
        $choosers = [];
        if ($product !== null && $variants !== []) {
            foreach (\Cms\Commerce\Variation::optionTaxonomies((string) $product['post_type']) as $taxKey => $tax) {
                $used = [];
                foreach ($variants as $v) {
                    $id = \Cms\Commerce\Variation::termsOf($v)[$taxKey] ?? 0;
                    if ($id > 0) {
                        $used[$id] = true;
                    }
                }
                if ($used === []) {
                    continue;
                }
                $terms = array_values(array_filter(
                    \Cms\Models\Term::allForTaxonomy((string) $taxKey),
                    static fn (array $t): bool => isset($used[(int) $t['id']])
                ));
                $choosers[(string) $taxKey] = [
                    'label' => (string) ($tax['label_single'] ?? $tax['label'] ?? $taxKey),
                    'terms' => $terms,
                ];
            }
        }
        ?>
        <?php if ($price !== null || isset($states[$stock]) || $val('product_sku') !== ''): ?>
            <div class="entry-meta entry-meta--product">
                <?php if ($price !== null): ?>
                    <p class="product-price" data-product-price>
                        <?php if ($price['was'] !== null): ?>
                            <del><?= htmlspecialchars($price['was']) ?></del>
                            <strong><?= htmlspecialchars($price['now']) ?></strong>
                        <?php else: ?>
                            <?= htmlspecialchars($price['now']) ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php if ($stock !== '' && isset($states[$stock])): ?>
                    <p class="entry-meta__stock" data-stock="<?= htmlspecialchars($stock) ?>">
                        <?= htmlspecialchars((string) $states[$stock]) ?>
                    </p>
                <?php endif; ?>

                <?php if ($val('product_sku') !== ''): ?>
                    <p class="u-text-muted u-text-sm"><?= htmlspecialchars($val('product_sku')) ?></p>
                <?php endif; ?>

                <?php
                // ── "ONLY 2 LEFT" IS TOLD, NOT COUNTED DOWN FROM 500 ──────
                // A number beside every product is noise; a number beside the
                // last few is the reason somebody buys today. The threshold is
                // stated here rather than made a setting, because a setting
                // nobody changes is a screen nobody needed.
                //
                // Null means this product does not count stock at all — a
                // membership, a ticket — and says nothing. An option's own
                // count is told by `product.js` once that option is chosen.
                $left = $product['stock_left'] ?? null;
                ?>
                <?php if ($left !== null && $left > 0 && $left <= 5): ?>
                    <p class="product-stock-low">
                        Only <?= (int) $left ?> left<?= $left === 1 ? '' : ' in stock' ?>.<?php /* audit-ok: a COMPOSED COUNT — "Only 3 left" is a number with words either side, not a sentence a shop rewrites */ ?>
                    </p>
                <?php endif; ?>

                <?php // ── A PRODUCT WITH VARIANTS AND NONE LEFT ────────────
                      // Every variant sold out is the same situation as the
                      // product being out of stock, and it has to say so rather
                      // than drawing a form whose only choice is empty. ?>
                <?php if ($product !== null && $product['purchasable'] && $variants !== [] && $buyable === []): ?>
                    <p class="shop-add__unavailable u-text-muted">
                        Every option is out of stock at the moment.<?php /* audit-ok: the SYSTEM describing its own state: stock decided this, and an editable version could contradict it */ ?>
                    </p>
                <?php elseif ($product !== null && $product['purchasable']): ?>
                    <?php /* A POST WITH A CSRF TOKEN. A link would let any page
                             on the internet fill a visitor's basket with an
                             <img> tag, and would let the browser prefetch it.
                             `ShopController` answers 303 so a refresh does not
                             add a second one. */ ?>
                    <form class="shop-add" method="post"
                          action="<?= htmlspecialchars(\Cms\Helpers\Url::page('cart/add')) ?>"><?php /* audit-ok: a route on this site, not words a shop owner would rewrite */ ?>
                        <?= \Cms\Security\Csrf::field() ?>
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

                        <?php if ($variants !== []): ?>
                            <?php // ── THE CHOICE IS REQUIRED AND HAS NO DEFAULT
                                  // A select that opens on "Small" sells smalls
                                  // to everybody who did not read it. The first
                                  // option is a prompt with no value, and
                                  // `required` makes the browser insist — with
                                  // `ShopController` refusing the POST as well,
                                  // because a browser is not a guarantee.
                                  //
                                  // EVERY OPTION IS LISTED, a sold-out one
                                  // disabled and saying so. Left out, it read as
                                  // never having been made, and a customer
                                  // looking for XL in Moss could not tell.
                                  //
                                  // THE PRICE IS IN THE OPTION when the options
                                  // differ. Without it a customer picks Large and
                                  // finds out it costs more on the basket page,
                                  // which is the surprise a price range exists to
                                  // prevent.
                                  //
                                  // Each option also carries what `product.js`
                                  // needs to keep the choosers in step: its
                                  // terms, whether it can be bought, its prices
                                  // as the shop formats them, its count, and its
                                  // photo. ?>
                            <?php
                            $variantPrices = array_map(
                                static fn (array $v): array => \Cms\Commerce\Variation::prices($v, $product),
                                $variants
                            );
                            $variedPricing = count(array_unique(array_column($variantPrices, 1))) > 1;
                            ?>
                            <span class="shop-variant-wrap" data-variant-wrap>
                                <label class="u-visually-hidden" for="add-variation">Option</label><?php /* audit-ok: the field's own label, mirroring the select below it */ ?>
                                <select class="shop-variant" id="add-variation" name="variation_id" required data-variant-select>
                                    <option value="">Choose an option…</option><?php /* audit-ok: the prompt a select opens on, so it cannot open on a real option */ ?>
                                    <?php foreach ($variants as $i => $v): ?>
                                        <?php
                                        [$vWas, $vNow] = $variantPrices[$i];
                                        $vBuy   = \Cms\Commerce\Variation::isPurchasable($v);
                                        $vLeft  = \Cms\Commerce\Variation::available($v);
                                        $vImage = (int) ($v['image_id'] ?? 0) > 0
                                            ? \Cms\Models\Media::getUrl((int) $v['image_id']) : '';
                                        ?>
                                        <option value="<?= (int) $v['id'] ?>"<?= $vBuy ? '' : ' disabled' ?>
                                                data-terms="<?= htmlspecialchars((string) json_encode(\Cms\Commerce\Variation::termsOf($v))) ?>"
                                                data-buy="<?= $vBuy ? '1' : '0' ?>"
                                                data-price="<?= htmlspecialchars(\Cms\Commerce\Money::format($vNow)) ?>"
                                                data-was="<?= $vNow < $vWas ? htmlspecialchars(\Cms\Commerce\Money::format($vWas)) : '' ?>"
                                                data-left="<?= $vLeft !== null ? (int) $vLeft : '' ?>"
                                                data-image="<?= htmlspecialchars($vImage) ?>">
                                            <?= htmlspecialchars((string) $v['label']) ?><?php
                                            if ($variedPricing) {
                                                echo ' — ' . htmlspecialchars(\Cms\Commerce\Money::format($vNow));
                                            }
                                            if (!$vBuy) {
                                                echo ' — sold out';   // audit-ok: the system describing stock, as the line below the form does
                                            } ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </span>

                            <?php if ($choosers !== []): ?>
                                <span class="shop-choosers" data-choosers hidden>
                                    <?php foreach ($choosers as $taxKey => $chooser): ?>
                                        <label class="u-visually-hidden" for="choose-<?= htmlspecialchars($taxKey) ?>"><?= htmlspecialchars($chooser['label']) ?></label>
                                        <select class="shop-variant" id="choose-<?= htmlspecialchars($taxKey) ?>"
                                                data-chooser="<?= htmlspecialchars($taxKey) ?>">
                                            <option value="">Choose a <?= htmlspecialchars(mb_strtolower($chooser['label'])) ?>…</option><?php /* audit-ok: the prompt a select opens on, naming what is being chosen */ ?>
                                            <?php foreach ($chooser['terms'] as $term): ?>
                                                <option value="<?= (int) $term['id'] ?>"><?= htmlspecialchars((string) $term['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endforeach; ?>
                                    <span class="shop-choosers__note" data-chooser-note aria-live="polite" hidden></span>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <label class="u-visually-hidden" for="add-qty">Quantity</label><?php /* audit-ok: the number field's label */ ?>
                        <?php // THE CEILING IS WHAT IS ACTUALLY THERE. Still
                              // only a courtesy — `Stock::take()` is what
                              // refuses a crafted POST — but a box that lets
                              // you type 10 of something there are 2 of is a
                              // form that invites a failure it already knows
                              // about. ?>
                        <input class="shop-qty" type="number" inputmode="numeric" id="add-qty"
                               name="quantity" value="1" min="1"
                               max="<?= $left !== null ? (int) $left : \Cms\Commerce\Cart::MAX_QTY ?>"
                               step="1">
                        <button type="submit" class="shop-btn"><?= htmlspecialchars(\Cms\Commerce\Words::of('add')) ?></button>
                    </form>
                <?php elseif ($product !== null): ?>
                    <?php // audit-ok: the SYSTEM describing its own state, not
                          // the shop's copy. Stock decides this sentence, and a
                          // field here would let an owner word it into a
                          // contradiction of what the code just decided. ?>
                    <p class="shop-add__unavailable u-text-muted">
                        This is out of stock and cannot be ordered at the moment.<?php /* audit-ok: the SYSTEM describing its own state: stock decided this, and an editable version could contradict it */ ?>
                    </p>
                <?php endif; ?>

                <?php
                // ── TOLD WHEN IT IS BACK ───────────────────────────────────
                // A sold-out option, or a product sold out, takes an address
                // to email once when it is back (`StockAlert`), and nothing
                // else is done with it. Only one whose count is kept: "back"
                // is its count coming up from nothing, and a thing marked out
                // of stock with no count would keep the address for an email
                // that never comes.
                $soldOut = [];
                if ($product !== null) {
                    foreach ($variants as $soldVariant) {
                        if (!\Cms\Commerce\Variation::isPurchasable($soldVariant)
                            && \Cms\Commerce\Variation::available($soldVariant) !== null) {
                            $soldOut[(int) $soldVariant['id']] = (string) $soldVariant['label'];
                        }
                    }
                    if ($variants === [] && !$product['purchasable'] && $product['stock_left'] !== null) {
                        $soldOut[0] = (string) $product['title'];
                    }
                }
                ?>
                <?php if ($soldOut !== []): ?>
                    <form class="shop-waitlist" method="post" action="<?= htmlspecialchars(\Cms\Helpers\Url::page('shop/notify')) ?>" data-waitlist><?php /* audit-ok: a route on this site */ ?>
                        <?= \Cms\Security\Csrf::field() ?>
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                        <label class="shop-waitlist__lead" for="waitlist-email">Sold out in the one you want? Leave your email to hear when it is back.</label><?php /* audit-ok: the SYSTEM describing what this form does with an address */ ?>
                        <?php if ($variants !== []): ?>
                            <label class="u-visually-hidden" for="waitlist-option">Which one</label><?php /* audit-ok: the select's label */ ?>
                            <?php // ITS OWN NAME: `variation_id` is the basket form's, and
                                  // what reads that form reads the option it adds. ?>
                            <select class="shop-variant" id="waitlist-option" name="waitlist_option">
                                <?php foreach ($soldOut as $soldId => $soldLabel): ?>
                                    <option value="<?= (int) $soldId ?>"><?= htmlspecialchars($soldLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <input class="shop-field__input" type="email" id="waitlist-email" name="email" required autocomplete="email">
                        <button type="submit" class="shop-btn shop-btn--quiet">Email me</button><?php /* audit-ok: the button names what it does */ ?>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($variants !== [] || ($product['gallery'] ?? []) !== []): ?>
            <script src="<?= htmlspecialchars(\Cms\Helpers\Asset::site('assets/js/product.js')) ?>" defer></script><?php /* audit-ok: an asset path on this site */ ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    /* THE OWNER'S OWN FIELDS. The blocks above print the fields an archetype
       brings, by name; a group an owner builds in Field Groups would otherwise
       be saved and shown on the edit screen and never reach a visitor. Each
       such group is a fact panel — the same panel as an event's — with its
       fields LABELLED: unlike a date or a phone number, "Tuesdays" means
       nothing without "Meets". Empty fields are left out, and a group with
       nothing filled in is not drawn. `ContentType\FieldDisplay` turns each
       stored value into what a visitor reads. */
    foreach (\Cms\Models\FieldGroup::getActiveGroupsForContentType((string) $post['post_type']) as $group):
        if (!empty($group['is_archetype'])) {
            continue;
        }
        $rows = [];
        foreach ((array) ($group['fields'] ?? []) as $field) {
            $shown = \Cms\ContentType\FieldDisplay::html($field, $meta[(string) ($field['key'] ?? '')] ?? null);
            if ($shown !== '') {
                $rows[] = [(string) ($field['label'] ?? $field['key']), $shown];
            }
        }
        if ($rows === []) {
            continue;
        }
    ?>
        <section class="entry-meta entry-fields" aria-label="<?= htmlspecialchars((string) ($group['title'] ?? '')) ?>">
            <dl class="entry-fields__list">
                <?php foreach ($rows as [$label, $shown]): ?>
                    <dt><?= htmlspecialchars($label) ?></dt>
                    <dd><?= $shown /* FieldDisplay escapes; rich text is sanitised there */ ?></dd>
                <?php endforeach; ?>
            </dl>
        </section>
    <?php endforeach; ?>

    <div class="post-content"><?= \Cms\Helpers\Shortcode::process($post['content']) ?></div>

    <?php
    /* ── ITS NEIGHBOURS, AND ENTRIES LIKE IT ──────────────────────────────────
       A story is read in a sequence — Previous / Next — and leads
       on to what is like it. Both questions live in `Post` (`adjacent()`,
       `related()`) so a site's own theme asks them the same way; this is the
       core theme asking. Only on a story: nobody reads on from one staff
       profile or price tier to the next by date.

       RELATED IS BY SHARED TERMS and is left out when nothing is shared — a
       "related" list that is really "latest" is a claim the page cannot back. */
    if ($isStory):
        $older    = \Cms\Models\Post::adjacent($post, true);
        $newer    = \Cms\Models\Post::adjacent($post, false);
        $related  = \Cms\Models\Post::related($post, 3);
        $typeSlug = (string) ($contentType['public_slug'] ?? '');
        $entryUrl = static fn (array $e): string => \Cms\Helpers\Url::page($typeSlug . '/' . $e['slug']);
    ?>
        <?php if ($older !== null || $newer !== null): ?>
            <nav class="post-nav" aria-label="More <?= htmlspecialchars(strtolower((string) ($contentType['label'] ?? ''))) ?>">
                <?php if ($older !== null): ?>
                    <a class="post-nav__link post-nav__link--prev" href="<?= htmlspecialchars($entryUrl($older)) ?>" rel="prev">
                        <?php // audit-ok: the theme's own navigation word, as the pager's "Previous" is — a site renames it in its own template, not per entry. ?>
                        <span class="post-nav__label">&larr; Previous</span>
                        <span class="post-nav__title"><?= htmlspecialchars((string) $older['title']) ?></span>
                    </a>
                <?php endif; ?>
                <?php if ($newer !== null): ?>
                    <a class="post-nav__link post-nav__link--next" href="<?= htmlspecialchars($entryUrl($newer)) ?>" rel="next">
                        <?php // audit-ok: as "Previous" above. ?>
                        <span class="post-nav__label">Next &rarr;</span>
                        <span class="post-nav__title"><?= htmlspecialchars((string) $newer['title']) ?></span>
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

        <?php if ($related !== []): ?>
            <section class="post-related" aria-labelledby="post-related-title">
                <?php // audit-ok: the theme's name for this list, like "Previous" — renamed in a site's own template. ?>
                <h2 class="post-related__title" id="post-related-title">Related</h2>
                <ul class="post-list">
                    <?php
                    // The archive's own card, meta attached in one query.
                    $relatedMeta = \Cms\Models\Meta::getAllFor(array_map(static fn (array $r): int => (int) $r['id'], $related));
                    $cardType    = $contentType;
                    foreach ($related as $p):
                        $p['meta'] = $relatedMeta[(int) $p['id']] ?? [];
                        require \Cms\Helpers\Template::locate('partials/post-card.php');
                    endforeach;
                    ?>
                </ul>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</article>
