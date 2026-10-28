<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Models\Option;

/**
 * HOW A CUSTOMER SAYS THEY WILL PAY.
 *
 * ── WHY THERE IS NO CARD FIELD, AND WHY THAT IS NOT A GAP ───────────────────
 * Nothing is fetched from a third party at runtime, and no third-party script
 * runs on a page of ours. Stripe Elements and PayPal Buttons are exactly that —
 * a `<script src>` from another host, executing inside our checkout, with our
 * customer's card in it.
 *
 * Authoring a card field HERE instead is not the alternative it sounds like.
 * A form that posts a PAN to our own server puts this install inside PCI DSS
 * scope, and that is a compliance regime, not a feature — it is not something
 * that can be satisfied by writing careful PHP.
 *
 * So this ships OFFLINE METHODS: bank transfer, cheque, cash on delivery. The
 * order is taken, the customer is told how to pay, the owner marks it paid.
 * That is a complete and honest shop for the businesses this software is for.
 *
 * ── THE DOOR THAT STAYS OPEN ────────────────────────────────────────────────
 * A REDIRECT gateway runs no third-party script here and stays out of PCI
 * scope: the customer leaves for the processor's own hosted page, types their
 * card THERE, and comes back. No third-party script on our page, no card
 * number through our server.
 * `Method::isOffline()` is the seam — an implementation that returns false
 * would carry a `redirectUrl()` and the checkout controller would send them to
 * it instead of straight to the order-received page. Nothing else would change.
 *
 * ── AND THE TITLE IS COPIED ONTO THE ORDER ──────────────────────────────────
 * `orders.payment_method_title` stores what the method was CALLED at the time.
 * Renaming "Direct bank transfer" to "e-Transfer" in settings must not rewrite
 * the instructions on a receipt already sent. Same rule as the line items.
 */
final class PaymentMethods
{
    /**
     * The methods this install can offer, in the order they are drawn.
     *
     * `instructions_default` is what the customer is shown after ordering,
     * unless the owner has written their own in settings. Blank is not an
     * option: a customer told "pay by bank transfer" and given no account
     * number cannot pay, and will email instead.
     */
    public const METHODS = [
        'bacs' => [
            'title'        => 'Direct bank transfer',
            'description'  => 'Pay by transfer to our bank account.',
            'instructions' => 'Your order will not ship until the funds have cleared.',
        ],
        'cheque' => [
            'title'        => 'Cheque',
            'description'  => 'Send a cheque by post.',
            'instructions' => 'Please post your cheque, quoting your order number.',
        ],
        'cod' => [
            'title'        => 'Cash on delivery',
            'description'  => 'Pay in cash when your order arrives.',
            'instructions' => 'Please have the exact amount ready for the driver.',
        ],
    ];

    /** Option key holding the enabled ids, comma separated. */
    private const ENABLED_KEY = 'commerce_payment_methods';

    /** Per-method overrides live under these prefixes. */
    private const TITLE_PREFIX        = 'commerce_pay_title_';
    private const INSTRUCTIONS_PREFIX = 'commerce_pay_instructions_';

    /**
     * The methods a customer may choose from, as configured.
     *
     * ── AN EMPTY SETTING MEANS BANK TRANSFER, NOT "NO WAY TO PAY" ───────────
     * A fresh install has never visited the commerce settings screen, so the
     * option is unset. Treating that as "nothing enabled" gives a checkout with
     * no payment methods on it — a shop that cannot take an order, presented as
     * if that were a decision somebody made. One sensible default, changeable
     * on the settings screen, is the honest reading of "not configured yet".
     *
     * Turning them ALL off, on the other hand, is a decision, and it is
     * respected: `Checkout` refuses and the settings screen says so.
     *
     * @return array<string, array{title: string, description: string, instructions: string}>
     */
    public static function enabled(): array
    {
        $raw = Option::get(self::ENABLED_KEY, null);

        if ($raw === null) {
            $ids = ['bacs'];                       // never configured
        } else {
            $ids = array_filter(array_map('trim', explode(',', $raw)));
        }

        $out = [];
        foreach (self::METHODS as $id => $default) {
            if (in_array($id, $ids, true)) {
                $out[$id] = self::describe($id);
            }
        }

        return $out;
    }

    /** Is this id one the customer may actually pick right now? */
    public static function isEnabled(string $id): bool
    {
        return isset(self::enabled()[$id]);
    }

    /**
     * One method, with the owner's wording where they have written some.
     *
     * @return array{title: string, description: string, instructions: string}
     */
    public static function describe(string $id): array
    {
        $default = self::METHODS[$id] ?? ['title' => $id, 'description' => '', 'instructions' => ''];

        $title = trim((string) Option::get(self::TITLE_PREFIX . $id, ''));
        $instr = trim((string) Option::get(self::INSTRUCTIONS_PREFIX . $id, ''));

        return [
            'title'        => $title !== '' ? $title : $default['title'],
            'description'  => $default['description'],
            'instructions' => $instr !== '' ? $instr : $default['instructions'],
        ];
    }

    /**
     * Every method is offline in this version.
     *
     * A real function rather than a constant `true` so the seam described in
     * the class header is a call site that already exists: adding a redirect
     * gateway changes what this returns, and the checkout controller — which
     * already branches on it — starts sending people to the processor without
     * being edited.
     */
    public static function isOffline(string $id): bool
    {
        return isset(self::METHODS[$id]);
    }

    /** Store the enabled set. Ids not in METHODS are dropped, not stored. */
    public static function setEnabled(array $ids): void
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if (isset(self::METHODS[$id]) && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        Option::set(self::ENABLED_KEY, implode(',', $clean));
    }

    public static function titleOptionKey(string $id): string
    {
        return self::TITLE_PREFIX . $id;
    }

    public static function instructionsOptionKey(string $id): string
    {
        return self::INSTRUCTIONS_PREFIX . $id;
    }
}
