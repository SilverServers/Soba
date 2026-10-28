<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Models\Option;

/**
 * WHAT THIS SHOP CALLS THINGS.
 *
 * ── THREE SETTINGS, NOT FIFTY-FIVE ──────────────────────────────────────────
 * The shop templates had 55 hard-coded strings. The obvious response — a field
 * per string — is the one to refuse: a store invented for each thing that
 * cannot otherwise be reached brings its own screen and its own family of
 * operations.
 *
 * So each of the 55 was judged. Most are FURNITURE: a table's "Quantity"
 * header, a form's "Country" label, the checkout saying the shop has no payment
 * method enabled. Those are the system labelling its own parts and describing
 * its own state, they are marked at their line with that reason, and they are
 * a translation concern rather than content.
 *
 * These three are the ones a shop's own voice owns, and the ones shops actually
 * ask to change:
 *
 *     basket   basket / cart / bag        the noun, everywhere
 *     add      "Add to basket" / "Buy now"  the button that starts a sale
 *     thanks   the first line of a receipt
 *
 * ── BLANK MEANS THE WORD THE TEMPLATES ALREADY USED ─────────────────────────
 * The defaults live here and not in the settings table, so an install that has
 * never opened the Commerce screen renders exactly what it rendered yesterday.
 * A default written into the options row would also mean the owner cannot get
 * back to it once they have typed over it.
 */
final class Words
{
    private const FALLBACKS = [
        'basket' => 'Basket',
        'add'    => 'Add to basket',
        'thanks' => 'Thank you',
    ];

    /** The shop's word for one of the three, or the built-in one. */
    public static function of(string $which): string
    {
        $fallback = self::FALLBACKS[$which] ?? '';
        if ($fallback === '') {
            return '';
        }

        $set = trim((string) (Option::get('commerce_word_' . $which) ?? ''));

        // `commerce_thanks` breaks the `commerce_word_*` pattern because it is a
        // SENTENCE and not a noun, and naming it `commerce_word_thanks` would
        // invite the next person to add `commerce_word_checkout_intro`. One
        // exception, stated, rather than a pattern that quietly stops meaning
        // anything.
        if ($which === 'thanks') {
            $set = trim((string) (Option::get('commerce_thanks') ?? ''));
        }

        return $set !== '' ? $set : $fallback;
    }

    /**
     * The basket's name in the middle of a sentence — "items in your basket".
     *
     * LOWERCASED ONLY WHEN THE OWNER'S WORD IS A PLAIN ONE. "Bag" becomes "bag";
     * a shop that typed "Shopping Bag" gets "Shopping Bag", because lowercasing
     * a name somebody capitalised on purpose is the sort of cleverness that
     * turns "MyShop Bag" into "myshop bag".
     */
    public static function basketInline(): string
    {
        $word = self::of('basket');

        return preg_match('/^[A-Z][a-z]+$/', $word) === 1 ? strtolower($word) : $word;
    }
}
