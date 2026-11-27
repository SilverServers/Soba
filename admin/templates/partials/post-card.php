<?php
/**
 * ONE ENTRY, AS A CARD — shared by every list of entries on the site.
 *
 * ── WHY THIS IS A PARTIAL AND NOT A SECOND COPY ─────────────────────────────
 * The type archive and the term archive (migration 048) need the same card,
 * and two copies of one rule drift — the drift is always found on whichever
 * copy nobody was looking at.
 *
 * Every argument in the markup below — the picture being a link, the reader not
 * being told the same destination twice, the archetype fields being rendered at
 * all — is made once, here.
 *
 * ── WHAT IT NEEDS ───────────────────────────────────────────────────────────
 * @var array<string, mixed> $p         the entry, with `meta` attached
 * @var array<string, mixed> $cardType  the entry's OWN content type. A term
 *      archive holds entries of SEVERAL types at once and each one's address is
 *      built from its own `public_slug`; an archive of one type passes the same
 *      value every time, and a mixed list cannot.
 */

use Cms\Models\Media;

$archetypes = $cardType['archetypes'] ?? ['post'];
$isListing  = in_array('listing', $archetypes, true);
$isProduct  = in_array('product', $archetypes, true);
$isEvent    = in_array('event', $archetypes, true);
$meta       = $p['meta'] ?? [];
// A plain-text field as typed — `FieldRenderer::plain()`; each print below
// escapes it once.
$plain      = static fn (string $key): string => \Cms\ContentType\FieldRenderer::plain($meta[$key] ?? '');
$url        = \Cms\Helpers\Url::entry($p, (string) \Cms\ContentType\Registry::publicSlug((string) ($cardType['key'] ?? $p['post_type'] ?? '')));
?>
            <li class="post-list__item">

                <?php
                /* THE PICTURE IS A LINK, not a decoration beside one. Every
                   visitor expects to open the article by clicking the image,
                   not just the title: a card whose image is not clickable is
                   a bug in the card. */
                $cover = Media::getUrl($p['featured_image_id'] ?? null);
                $image = $cover !== '' ? Media::find((int) $p['featured_image_id']) : null;
                ?>
                <?php if ($cover !== ''): ?>
                    <?php /* `tabindex="-1"` + `aria-hidden`: this is the SAME
                             destination as the title link right below it, so a
                             screen reader announcing both is one thing said
                             twice and one extra tab stop for no gain. Hidden
                             from the reader, clickable with a mouse.

                             The alt text is the one the owner typed in the media
                             library, not an empty string. A CMS that collects
                             alt text and then throws it away on output is the
                             same lie as a field nothing renders. */ ?>
                    <a class="post-list__media" href="<?= htmlspecialchars($url) ?>" tabindex="-1" aria-hidden="true">
                        <?php // 96px wide: a phone's screen takes the smallest width. ?>
                        <?php $coverSet = $image !== null ? \Cms\Media\Sizes::srcset($image) : ''; ?>
                        <img src="<?= htmlspecialchars($cover) ?>"<?= $coverSet !== '' ? ' srcset="' . htmlspecialchars($coverSet) . '" sizes="96px"' : '' ?>
                             alt="<?= htmlspecialchars((string) ($image['alt_text'] ?? '')) ?>" loading="lazy">
                    </a>
                <?php endif; ?>

                <h2 class="post-list__title">
                    <a href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($p['title']) ?></a><?= \Cms\Site\EditBar::pencil($p) ?>
                </h2>

                <?php if ($isListing && !empty($meta['listing_subtitle'])): ?>
                    <p class="listing-subtitle"><?= htmlspecialchars($plain('listing_subtitle')) ?></p>
                <?php endif; ?>

                <?php if ($isEvent || (!$isListing && !$isProduct)): ?>
                    <?php /* A date belongs on a feed and on an event. On a roster
                             or a price list it is the day someone typed the
                             entry, which is nobody's business and reads as
                             clutter. */ ?>
                    <p class="post-list__meta">
                        <?php // THE START AS TYPED, in the owner's formats: an
                              // event's start is the site's wall-clock time. ?>
                        <?php $starts = (string) ($meta['event_start_date'] ?? ''); ?>
                        <?php if ($isEvent && \Cms\Helpers\Dates::asTyped($starts, \Cms\Helpers\Dates::dateFormat()) !== ''): ?>
                            <?= htmlspecialchars(\Cms\Helpers\Dates::asTyped($starts, \Cms\Helpers\Dates::dateFormat()) . ' at '
                                . \Cms\Helpers\Dates::asTyped($starts, \Cms\Helpers\Dates::timeFormat())) ?>
                            <?php if (!empty($meta['event_location'])): ?>
                                &mdash; <?= htmlspecialchars($plain('event_location')) ?>
                            <?php endif; ?>
                        <?php elseif (!empty($p['published_at'])): ?>
                            <?= htmlspecialchars(\Cms\Helpers\Dates::short((string) $p['published_at'])) ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php /* PRICE AFTER THE DATE, not before it. On a type that is an
                         Event AND a Product — a ticketed event — a price block
                         between the title and the date would make the card read
                         "Winter formal · $35 · October 11". The date is what a
                         person scanning a list of events is looking for; the price
                         is the second fact about the one they picked.

                         Only that combination moves. A price list carries no date
                         at all (the branch above is false without `event`), so on
                         Memberships the price is still the first thing under the
                         title. */ ?>
                <?php
                /* THROUGH `Commerce\Product`, AS THE PRODUCT PAGE IS. The card
                   printed a `$` and the two meta strings as typed: "$38" here
                   and "$38.00" on the page, a dollar sign on a shop in pounds, a
                   "sale" struck through even when its price was the higher one
                   or its days were over, and one figure for a product whose
                   sizes cost different amounts. The meta is already in hand,
                   and the list primes every card's options in one read
                   (`Variation::prime()`), so this costs no query per card. */
                $cardProduct = $isProduct ? \Cms\Commerce\Product::fromPost($p, null, $meta) : null;
                $cardPrice   = $cardProduct !== null ? \Cms\Commerce\Product::priceHtmlParts($cardProduct) : null;
                ?>
                <?php if ($cardPrice !== null): ?>
                    <p class="product-price">
                        <?php if ($cardPrice['was'] !== null): ?>
                            <del><?= htmlspecialchars($cardPrice['was']) ?></del>
                            <strong><?= htmlspecialchars($cardPrice['now']) ?></strong>
                        <?php else: ?>
                            <?= htmlspecialchars($cardPrice['now']) ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php if ($isListing && !empty($meta['listing_email'])): ?>
                    <p class="post-list__meta">
                        <a href="mailto:<?= htmlspecialchars($plain('listing_email')) ?>"><?= htmlspecialchars($plain('listing_email')) ?></a>
                    </p>
                <?php endif; ?>

                <?php
                /* The entry's own excerpt when it has one (migration 053), else
                   the start of its text — `Post::summary()`, the one place that
                   decides, shared with the home page and search. The ellipsis
                   only when something was actually cut: appended
                   unconditionally, a one-line blurb would render as
                   "…community members.…". */
                $summary = \Cms\Models\Post::summary($p);
                ?>
                <?php if ($summary['text'] !== ''): ?>
                    <p class="post-list__excerpt">
                        <?= htmlspecialchars($summary['text']) ?><?= $summary['cut'] ? '&hellip;' : '' ?>
                    </p>
                <?php endif; ?>
            </li>
