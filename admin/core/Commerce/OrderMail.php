<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Mail\Mailer;
use Cms\Models\Option;
use Cms\Models\Order;

/**
 * THE TWO EMAILS AN ORDER SENDS, and what happens when they do not.
 *
 * ── ONE TO THE CUSTOMER, ONE TO THE SHOP ────────────────────────────────────
 * The customer's is a receipt AND the instructions for paying, because payment
 * here is offline: a confirmation that does not say where to send the money is
 * a confirmation the customer has to email back about. The shop's is a
 * notification — what was ordered, by whom, and a link straight to the order.
 *
 * ── A FAILED EMAIL IS RECORDED ON THE ORDER, NOT ONLY IN A LOG ──────────────
 * `Mailer::send()` returns false rather than throwing, precisely so the order
 * survives a mail server being down — the money and the address are already
 * committed and an exception here would show the buyer an error for an order
 * that worked. But swallowing it entirely means the owner's first sign that
 * their confirmations stopped is a customer telephoning.
 *
 * So a failure writes an order note. The owner reads that screen anyway, and
 * "the confirmation could not be sent" beside the order it belongs to is worth
 * more than a line in `php_error.log` that nobody opens.
 *
 * ── THE BODIES ARE BUILT FROM THE ORDER ROW ─────────────────────────────────
 * Never from the cart, which is empty by the time this runs, and never from the
 * product — `Money::format($n, $order['currency'])` throughout, so a receipt
 * says what was actually charged even after the shop changes currency. Same
 * rule as the order screen and the migration's own header.
 */
final class OrderMail
{
    /**
     * How wide the plain-text tables are.
     *
     * ONE NUMBER, read by the rules and by the padding. It was written as a
     * literal 52 in four places, which is three chances for a rule to stop
     * being as wide as the rows it divides.
     */
    private const WIDTH = 52;

    /**
     * Send both, and note anything that failed.
     *
     * Called after the order row exists. Returns nothing: there is no caller
     * that should behave differently, because there is nothing useful to do
     * about a failed send at that moment except record it, which happens here.
     */
    public static function afterOrder(array $order): void
    {
        $items = Order::items((int) $order['id']);

        self::toCustomer($order, $items);
        self::toShop($order, $items);
    }

    private static function toCustomer(array $order, array $items): void
    {
        $email = trim((string) $order['email']);
        if ($email === '') {
            return;   // guest checkout requires one, so this is belt and braces
        }

        $sent = Mailer::send(
            [$email],
            self::siteName() . ' — order ' . Order::number($order),
            self::customerBody($order, $items),
            // REPLY-TO IS THE SHOP. A customer answering their confirmation is
            // asking the shop a question, and the default sender may well be a
            // no-reply address on a mail provider.
            ['reply_to' => Mailer::adminAddress()]
        );

        if (!$sent) {
            Order::addNote(
                (int) $order['id'],
                'The confirmation email to ' . $email . ' could not be sent. '
                    . 'Check Settings → Email, and let the customer know how to pay.',
                false,
                'Shop'
            );
        }
    }

    private static function toShop(array $order, array $items): void
    {
        $to = Mailer::adminAddress();
        if ($to === '') {
            // NOT A FAILURE, AND NOT A NOTE. A shop that has configured no
            // address has chosen not to be emailed; writing "could not send"
            // on every order would be a false alarm on every order.
            return;
        }

        $sent = Mailer::send(
            [$to],
            'New order ' . Order::number($order) . ' — '
                . Money::format((int) $order['total'], (string) $order['currency']),
            self::shopBody($order, $items),
            // The CUSTOMER, so hitting reply answers the person who ordered.
            ['reply_to' => (string) $order['email']]
        );

        if (!$sent) {
            Order::addNote(
                (int) $order['id'],
                'The notification email to ' . $to . ' could not be sent.',
                false,
                'Shop'
            );
        }
    }

    // ── The bodies ──────────────────────────────────────────────────────────

    private static function customerBody(array $order, array $items): string
    {
        $rule = str_repeat('-', self::WIDTH);

        $body  = 'Thank you for your order.' . "\n\n";
        $body .= 'Order ' . Order::number($order) . "\n";
        $body .= self::stamp((string) $order['created_at']) . "\n\n";

        $body .= self::itemTable($items, $order) . "\n";

        // THE INSTRUCTIONS ARE THE POINT OF THIS EMAIL. Offline payment means
        // the customer has to do something next, and this is where they find
        // out what. Above the addresses, not in a footer.
        $method = PaymentMethods::describe((string) $order['payment_method']);
        $body .= $rule . "\n";
        $body .= 'HOW TO PAY — ' . (string) $order['payment_method_title'] . "\n";
        $body .= $rule . "\n";
        $body .= trim($method['instructions']) . "\n";
        $body .= 'Please quote order ' . Order::number($order) . '.' . "\n\n";

        $body .= self::addressBlock($order, 'billing', 'BILLING ADDRESS');

        if ((string) $order['shipping_line1'] !== '') {
            $body .= self::addressBlock($order, 'shipping', 'DELIVERY ADDRESS');
        }

        // HOW IT REACHES THEM — "Delivery to Ontario", or where and when to
        // collect it, in the words the order kept.
        if ((string) ($order['shipping_method_title'] ?? '') !== '') {
            $body .= ((string) $order['shipping_method'] === 'pickup' ? 'COLLECTION' : 'DELIVERY') . "\n"
                   . (string) $order['shipping_method_title'] . "\n\n";
        }

        if (trim((string) ($order['customer_note'] ?? '')) !== '') {
            $body .= 'YOUR NOTES' . "\n" . trim((string) $order['customer_note']) . "\n\n";
        }

        // WHOSE RECEIPT IT IS: the business's name, address and GST/HST
        // number when Settings → Shop has them, else the site's name.
        $business = Business::lines();
        $body .= ($business !== [] ? implode("\n", $business) : self::siteName()) . "\n";

        return $body;
    }

    private static function shopBody(array $order, array $items): string
    {
        $body  = 'A new order has been placed.' . "\n\n";
        $body .= 'Order ' . Order::number($order) . "\n";
        $body .= self::stamp((string) $order['created_at']) . "\n";
        $body .= 'Paying by: ' . (string) $order['payment_method_title'] . "\n";
        $body .= 'Status: ' . Order::statusLabel((string) $order['status']) . "\n\n";

        $body .= self::itemTable($items, $order) . "\n";
        $body .= self::addressBlock($order, 'billing', 'BILLING ADDRESS');

        if ((string) $order['shipping_line1'] !== '') {
            $body .= self::addressBlock($order, 'shipping', 'DELIVERY ADDRESS');
        }
        if ((string) ($order['shipping_method_title'] ?? '') !== '') {
            $body .= 'How it goes: ' . (string) $order['shipping_method_title'] . "\n";
        }
        if ((string) ($order['terms_agreed'] ?? '') !== '') {
            $body .= 'Agreed to: ' . (string) $order['terms_agreed'] . "\n";
        }
        if (!empty($order['marketing_opt_in'])) {
            $body .= 'Asked for news and offers by email.' . "\n";
        }

        $body .= 'Email: ' . (string) $order['email'] . "\n";
        if ((string) $order['phone'] !== '') {
            $body .= 'Phone: ' . (string) $order['phone'] . "\n";
        }

        if (trim((string) ($order['customer_note'] ?? '')) !== '') {
            $body .= "\n" . 'CUSTOMER NOTE' . "\n" . trim((string) $order['customer_note']) . "\n";
        }

        // A LINK STRAIGHT TO THE ORDER. `ADMIN_URL` is defined at bootstrap and
        // honours a renamed admin slug, so an obscured install does not get a
        // link to a route it does not serve.
        if (defined('ADMIN_URL')) {
            $body .= "\n" . ADMIN_URL . '/orders/' . (int) $order['id'] . "\n";
        }

        return $body;
    }

    /**
     * The line items, as a plain-text table.
     *
     * ── THE FIGURES HAVE TO LINE UP, AND str_pad COUNTS BYTES ─────────────
     * `Mailer` sends plain text on purpose — its header says why — so a
     * receipt's alignment is done with spaces, and `str_pad()` measures in
     * BYTES. A product called "Café membership" is 15 characters and 16 bytes,
     * so its row would come out a column short of every other row.
     *
     * `mb_strlen()` counts characters, which is what a monospace column is in.
     * (`mb_str_pad()` arrived in PHP 8.3 and exists on this install — checked —
     * but `row()` needs the WIDTH to compute the gap either way, so the length
     * is measured directly rather than padding twice.)
     */
    private static function itemTable(array $items, array $order): string
    {
        $currency = (string) $order['currency'];
        $rule     = str_repeat('-', self::WIDTH);

        $out = $rule . "\n";
        foreach ($items as $item) {
            // THE OPTION IS PART OF THE NAME HERE. This is a plain-text
            // email with one column of names and one of figures — there is
            // nowhere else to put it, and an email that says "Associate" for
            // what was bought as "Associate — Small" is the document the
            // customer quotes back when the wrong parcel arrives.
            $label = trim((string) ($item['variation_label'] ?? ''));
            $name  = (string) $item['name']
                   . ($label !== '' ? ' (' . $label . ')' : '')
                   . ' x' . (int) $item['quantity'];
            $sum  = Money::format((int) $item['line_total'], $currency);
            $out .= self::row($name, $sum);
        }
        $out .= $rule . "\n";

        // ── THE SAME ROWS THE RECEIPT PAGE PRINTS ──────────────────────────
        // Written out here separately, this said "Tax" against a number while
        // the web page said "GST (5%)" — the same order, two documents, two
        // different stories, and the email is the one the customer keeps.
        // `Totals::ofOrder()` reads the rate and the name off the ROW, so an
        // email resent next year still says what it said the day it was sent.
        foreach (Totals::rows(Totals::ofOrder($order)) as [$label, $amount, $isNote]) {
            $out .= self::row($label, Money::format($amount, $currency));
        }
        $out .= self::row('TOTAL', Money::format((int) $order['total'], $currency));

        return $out;
    }

    /** One padded `label ....... figure` line. */
    private static function row(string $label, string $value): string
    {
        $used = mb_strlen($label) + mb_strlen($value);
        $gap  = max(1, self::WIDTH - $used);

        return $label . str_repeat(' ', $gap) . $value . "\n";
    }

    private static function addressBlock(array $order, string $prefix, string $heading): string
    {
        $parts = array_filter([
            (string) $order[$prefix . '_name'],
            (string) $order[$prefix . '_line1'],
            (string) $order[$prefix . '_line2'],
            trim((string) $order[$prefix . '_city'] . ' ' . (string) $order[$prefix . '_region']),
            (string) $order[$prefix . '_post'],
            Countries::name((string) $order[$prefix . '_country']),
        ], static fn (string $s): bool => trim($s) !== '');

        return $heading . "\n" . implode("\n", $parts) . "\n\n";
    }

    private static function stamp(string $datetime): string
    {
        // IN THE SITE'S ZONE, as the order screen shows it: `date()` here is
        // UTC, and an order placed at 9 pm in Vancouver read as the next day.
        return \Cms\Helpers\Dates::format($datetime, 'l, j F Y \a\t g:i A');
    }

    private static function siteName(): string
    {
        return \Cms\SEO\Head::globalTokens()['site_name'];
    }
}
