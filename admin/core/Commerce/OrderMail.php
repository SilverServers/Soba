<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Mail\Mailer;
use Cms\Models\Option;
use Cms\Models\Order;

/**
 * THE EMAILS AN ORDER SENDS, and what happens when they do not.
 *
 * ── ONE TO THE CUSTOMER, ONE TO THE SHOP, WHEN IT IS PLACED ─────────────────
 * The customer's is a receipt AND the instructions for paying, because payment
 * here is offline: a confirmation that does not say where to send the money is
 * a confirmation the customer has to email back about. The shop's is a
 * notification — what was ordered, by whom, and a link straight to the order.
 *
 * ── AND TO THE CUSTOMER AS IT MOVES ─────────────────────────────────────────
 * Paid, and sent: the two moments a customer who paid by e-Transfer otherwise
 * spends wondering about; and each refund, with what it was for. A note the
 * owner writes to the customer is emailed too, and any of them can be sent
 * again from the order, for the customer who lost one.
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

    /**
     * The emails a status sends the customer, and the setting that stops each.
     *
     * CANCELLED SENDS NOTHING: a cancellation is a conversation the owner has
     * in their own words, which a note to the customer carries. On hold is a
     * pause the customer has no part in.
     */
    public const STATUS_MAIL = [
        'processing' => 'commerce_mail_paid',
        'completed'  => 'commerce_mail_sent',
    ];

    /** After `Order::setStatus()` has moved an order: its status's email, if it has one and it is on. */
    public static function statusChanged(int $orderId, string $status, string $by = ''): void
    {
        $key = self::STATUS_MAIL[$status] ?? null;
        if ($key === null || (string) Option::get($key, '1') !== '1') {
            return;
        }
        $order = Order::find($orderId);
        if ($order !== null) {
            self::statusMail($order, $status, $by, false);
        }
    }

    /**
     * A note the owner writes to the customer: emailed, and kept on the order
     * marked as sent.
     *
     * ONE THAT COULD NOT BE SENT IS KEPT AS A PRIVATE NOTE, with a second note
     * saying so. "Sent to the customer" on a note that never left would be the
     * order's own record saying something that did not happen.
     */
    public static function noteToCustomer(array $order, string $note, string $by = ''): bool
    {
        $note = trim($note);
        if ($note === '') {
            return false;
        }

        $id    = (int) $order['id'];
        $email = trim((string) $order['email']);
        $sent  = $email !== '' && Mailer::send(
            [$email],
            self::siteName() . ' — a note about order ' . Order::number($order),
            self::noteBody($order, Order::items($id), $note),
            ['reply_to' => Mailer::adminAddress()]
        );

        Order::addNote($id, $note, $sent, $by);
        if (!$sent) {
            Order::addNote(
                $id,
                'The note "' . mb_strimwidth($note, 0, 40, '…') . '" could not be emailed to '
                    . ($email !== '' ? $email . '. Check Settings → Email.' : 'the customer, who left no address.'),
                false,
                'Shop'
            );
        }

        return $sent;
    }

    /**
     * What can be sent again from an order: the confirmation, the email of the
     * status it is in if that status has one, and the shop's own notification
     * when the shop has an address to send it to.
     *
     * @return array<string, string> key => what the owner reads in the list
     */
    public static function resendChoices(array $order): array
    {
        // NAMED AS THE CUSTOMER READS THEM, under the panel's own "Emails":
        // the one that goes to the shop says so, the rest go to the customer.
        $out    = ['confirmation' => 'Order confirmation'];
        $status = (string) $order['status'];
        if ($status === 'processing') {
            $out['status'] = 'Payment received';
        } elseif ($status === 'completed') {
            $out['status'] = self::ships($order) ? 'On its way' : 'Order complete';
        }
        if (Mailer::adminAddress() !== '') {
            $out['shop'] = 'New order, to the shop';
        }

        return $out;
    }

    /**
     * Send one of `resendChoices()` again, and note it on the order either way.
     *
     * THE EMAIL IS BUILT FROM THE ORDER AS IT IS NOW: an address corrected
     * since, or a tracking number added since, is what the customer gets.
     */
    public static function resend(array $order, string $which, string $by = ''): bool
    {
        if (!isset(self::resendChoices($order)[$which])) {
            return false;
        }
        $items = Order::items((int) $order['id']);
        $by    = $by !== '' ? $by : 'Shop';

        return match ($which) {
            'confirmation' => self::toCustomer($order, $items, $by),
            'shop'         => self::toShop($order, $items, $by),
            default        => self::statusMail($order, (string) $order['status'], $by, true),
        };
    }

    /**
     * The confirmation, to the customer of an order the shop took itself, and
     * a note that it went. The shop needs no email about an order it typed.
     */
    public static function confirmation(array $order, string $by = ''): bool
    {
        $email = trim((string) $order['email']);
        if ($email === '') {
            return false;
        }
        $sent = self::toCustomer($order, Order::items((int) $order['id']));
        if ($sent) {
            Order::addNote((int) $order['id'], 'The order confirmation was emailed to ' . $email . '.', false,
                $by !== '' ? $by : 'Shop');
        }

        return $sent;
    }

    /**
     * One status's email to the customer, and a note on the order saying it
     * went, or that it could not.
     */
    private static function statusMail(array $order, string $status, string $by, bool $again): bool
    {
        $email = trim((string) $order['email']);
        if ($email === '') {
            return false;
        }

        $items  = Order::items((int) $order['id']);
        $number = Order::number($order);
        if ($status === 'processing') {
            $subject = self::siteName() . ' — order ' . $number . ': payment received';
            $body    = self::paidBody($order, $items);
            $what    = 'that the payment arrived';
        } else {
            $ships   = self::ships($order);
            $subject = self::siteName() . ' — order ' . $number . ($ships ? ' is on its way' : ' is complete');
            $body    = self::sentBody($order, $items);
            $what    = $ships ? 'that the order is on its way' : 'that the order is complete';
        }

        $sent = Mailer::send([$email], $subject, $body, ['reply_to' => Mailer::adminAddress()]);

        Order::addNote(
            (int) $order['id'],
            $sent
                ? 'Emailed ' . $email . ' ' . $what . ($again ? ', again' : '') . '.'
                : 'The email telling ' . $email . ' ' . $what . ' could not be sent. Check Settings → Email.',
            false,
            $by !== '' ? $by : 'Shop'
        );

        return $sent;
    }

    /**
     * A refund, to the customer: how much, for what, the tax in it, and why.
     * `Refund` sends it when it records one, unless Settings → Shop turned it
     * off, and the order notes that it went or could not.
     *
     * @param array<string, mixed> $refund one of `Refund::forOrder()`
     */
    public static function refunded(int $orderId, array $refund, string $by = ''): bool
    {
        if ((string) Option::get('commerce_mail_refunded', '1') !== '1') {
            return false;
        }
        $order = Order::find($orderId);
        $email = $order !== null ? trim((string) $order['email']) : '';
        if ($order === null || $email === '') {
            return false;
        }

        $amount = Money::format((int) $refund['amount'], (string) $order['currency']);
        $sent   = Mailer::send(
            [$email],
            self::siteName() . ' — order ' . Order::number($order) . ': ' . $amount . ' refunded',
            self::refundBody($order, $refund),
            ['reply_to' => Mailer::adminAddress()]
        );
        Order::addNote(
            $orderId,
            $sent
                ? 'Emailed ' . $email . ' that ' . $amount . ' was refunded.'
                : 'The email telling ' . $email . ' about the refund could not be sent. Check Settings → Email.',
            false,
            $by !== '' ? $by : 'Shop'
        );

        return $sent;
    }

    /**
     * A reminder to pay, to the customer of an order still waiting the day
     * before it lapses (`Lapse`): when it will be cancelled, and how to pay,
     * as the confirmation said. The order notes that it went or could not.
     *
     * @param int $lapsesAt when it will be cancelled, a timestamp
     */
    public static function paymentReminder(array $order, int $lapsesAt): bool
    {
        $email = trim((string) $order['email']);
        if ($email === '') {
            return false;
        }
        $rule   = str_repeat('-', self::WIDTH);
        $method = PaymentMethods::describe((string) $order['payment_method']);
        $when   = \Cms\Helpers\Dates::format($lapsesAt, 'g:i A \o\n l, j F');
        $body   = 'Order ' . Order::number($order) . ' is still waiting for its payment. If none arrives by ' . $when
                . ', it will be cancelled and its items put back on sale.' . "\n\n"
                . $rule . "\n" . 'HOW TO PAY — ' . (string) $order['payment_method_title'] . "\n" . $rule . "\n"
                . (trim($method['instructions']) !== '' ? trim($method['instructions']) . "\n" : '')
                . 'Please quote order ' . Order::number($order) . '.' . "\n\n"
                . self::orderPart($order, Order::items((int) $order['id'])) . self::footer();

        $sent = Mailer::send(
            [$email],
            self::siteName() . ' — order ' . Order::number($order) . ' is waiting for payment',
            $body,
            ['reply_to' => Mailer::adminAddress()]
        );
        Order::addNote(
            (int) $order['id'],
            $sent
                ? 'Reminded ' . $email . ' to pay, before it is cancelled at ' . $when . '.'
                : 'The reminder to pay could not be emailed to ' . $email . '. Check Settings → Email.',
            false,
            'Shop'
        );

        return $sent;
    }

    /** Posted to an address: not collected, and not an order with nothing to post. */
    private static function ships(array $order): bool
    {
        $method = (string) ($order['shipping_method'] ?? '');

        return $method !== '' && $method !== Shipping::PICKUP;
    }

    private static function toCustomer(array $order, array $items, string $again = ''): bool
    {
        $email = trim((string) $order['email']);
        if ($email === '') {
            return false;   // guest checkout requires one, so this is belt and braces
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

        // SENT AGAIN BY SOMEBODY: noted either way, under their name, so the
        // order says the customer has a copy. The first sending is the
        // checkout's own and is noted only when it fails.
        if ($again !== '' && $sent) {
            Order::addNote((int) $order['id'], 'The order confirmation was emailed again to ' . $email . '.', false, $again);
        }
        if (!$sent) {
            Order::addNote(
                (int) $order['id'],
                'The confirmation email to ' . $email . ' could not be sent. '
                    . 'Check Settings → Email, and let the customer know how to pay.',
                false,
                $again !== '' ? $again : 'Shop'
            );
        }

        return $sent;
    }

    private static function toShop(array $order, array $items, string $again = ''): bool
    {
        $to = Mailer::adminAddress();
        if ($to === '') {
            // NOT A FAILURE, AND NOT A NOTE. A shop that has configured no
            // address has chosen not to be emailed; writing "could not send"
            // on every order would be a false alarm on every order.
            return false;
        }

        $sent = Mailer::send(
            [$to],
            'New order ' . Order::number($order) . ' — '
                . Money::format((int) $order['total'], (string) $order['currency']),
            self::shopBody($order, $items),
            // The CUSTOMER, so hitting reply answers the person who ordered.
            ['reply_to' => (string) $order['email']]
        );

        if ($again !== '' && $sent) {
            Order::addNote((int) $order['id'], 'The new order email was sent again to ' . $to . '.', false, $again);
        }
        if (!$sent) {
            Order::addNote(
                (int) $order['id'],
                'The notification email to ' . $to . ' could not be sent.',
                false,
                $again !== '' ? $again : 'Shop'
            );
        }

        return $sent;
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
        //
        // UNLESS IT IS PAID: the same email sent again later says so, rather
        // than asking a customer who has paid to pay.
        if (in_array((string) $order['status'], Order::PAID, true)) {
            $body .= 'PAID — ' . (string) $order['payment_method_title'] . "\n\n";
        } else {
            $method = PaymentMethods::describe((string) $order['payment_method']);
            $body .= $rule . "\n";
            $body .= 'HOW TO PAY — ' . (string) $order['payment_method_title'] . "\n";
            $body .= $rule . "\n";
            $body .= trim($method['instructions']) . "\n";
            $body .= 'Please quote order ' . Order::number($order) . '.' . "\n\n";
        }

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
        return $body . self::footer();
    }

    /** Paid: the money arrived, and what happens next. */
    private static function paidBody(array $order, array $items): string
    {
        $number = Order::number($order);
        $method = (string) ($order['shipping_method'] ?? '');
        $body   = match (true) {
            self::ships($order)        => 'Your payment for order ' . $number . ' has arrived, and we are getting your order ready to send.',
            $method === Shipping::PICKUP => 'Your payment for order ' . $number . ' has arrived, and we are getting your order ready to collect.',
            default                    => 'Your payment for order ' . $number . ' has arrived. Thank you.',
        } . "\n\n";

        return $body . self::orderPart($order, $items) . self::footer();
    }

    /** Sent: on its way, with the carrier and tracking when there are some; or done. */
    private static function sentBody(array $order, array $items): string
    {
        $number = Order::number($order);
        $method = (string) ($order['shipping_method'] ?? '');
        $body   = match (true) {
            self::ships($order)        => 'Order ' . $number . ' is on its way.',
            $method === Shipping::PICKUP => 'Order ' . $number . ' has been collected. Thank you.',
            default                    => 'Order ' . $number . ' is complete. Thank you.',
        } . "\n\n";

        // THE TRACKING IS WHY A CUSTOMER OPENS THIS EMAIL, so it comes first.
        $tracking = trim((string) ($order['tracking_carrier'] ?? '') . ' ' . (string) ($order['tracking_number'] ?? ''));
        $link     = trim((string) ($order['tracking_url'] ?? ''));
        if (self::ships($order) && ($tracking !== '' || $link !== '')) {
            $body .= 'TRACKING' . "\n"
                   . ($tracking !== '' ? $tracking . "\n" : '')
                   . ($link !== '' ? $link . "\n" : '')
                   . "\n";
        }

        return $body . self::orderPart($order, $items) . self::footer();
    }

    /**
     * A refund: what came back, the tax in it, the total, and the reason.
     *
     * THE GOODS ARE NAMED, NOT PRICED: a refund of part of a line's value, or
     * one with its share of a discount taken off, would print a price beside a
     * name that is not what went back. The money is the tax lines and the total.
     */
    private static function refundBody(array $order, array $refund): string
    {
        $currency = (string) $order['currency'];
        $rule     = str_repeat('-', self::WIDTH);

        $body  = 'We have refunded ' . Money::format((int) $refund['amount'], $currency)
               . ' for order ' . Order::number($order) . '.' . "\n\n";
        $body .= $rule . "\n";
        foreach ((array) $refund['items'] as $item) {
            $label = trim((string) ($item['variation_label'] ?? ''));
            $body .= (string) $item['name'] . ($label !== '' ? ' (' . $label . ')' : '')
                   . ' x' . (int) $item['quantity'] . "\n";
        }
        if ((int) $refund['shipping'] > 0) {
            $body .= 'Delivery' . "\n";
        }
        $body .= $rule . "\n";
        foreach ((array) $refund['taxLines'] as $line) {
            $label = TaxRates::line((string) $line['label'], (int) $line['rate']);
            $body .= self::row(!empty($order['tax_inclusive']) ? 'Includes ' . $label : $label,
                               Money::format((int) $line['amount'], $currency));
        }
        $body .= self::row('REFUNDED', Money::format((int) $refund['amount'], $currency)) . "\n";

        $reason = trim((string) $refund['reason']);
        if ($reason !== '') {
            $body .= 'Reason: ' . $reason . "\n\n";
        }

        $body .= 'Order ' . Order::number($order) . "\n" . self::stamp((string) $order['created_at']) . "\n\n";

        return $body . self::footer();
    }

    /** A note from the shop, with the order it is about beneath it. */
    private static function noteBody(array $order, array $items, string $note): string
    {
        return $note . "\n\n" . self::orderPart($order, $items) . self::footer();
    }

    /**
     * The order, as every email after the confirmation shows it: its number
     * and date, what was bought, and where it goes.
     */
    private static function orderPart(array $order, array $items): string
    {
        $body  = 'Order ' . Order::number($order) . "\n";
        $body .= self::stamp((string) $order['created_at']) . "\n\n";
        $body .= self::itemTable($items, $order) . "\n";

        if ((string) $order['shipping_line1'] !== '') {
            $body .= self::addressBlock($order, 'shipping', 'DELIVERY ADDRESS');
        } elseif (self::ships($order)) {
            $body .= self::addressBlock($order, 'billing', 'DELIVERY ADDRESS');
        }
        if ((string) ($order['shipping_method'] ?? '') === Shipping::PICKUP
                && (string) ($order['shipping_method_title'] ?? '') !== '') {
            $body .= 'COLLECTION' . "\n" . (string) $order['shipping_method_title'] . "\n\n";
        }

        return $body;
    }

    /** Whose email it is: the business when Settings → Shop names it, else the site. */
    private static function footer(): string
    {
        $business = Business::lines();

        return ($business !== [] ? implode("\n", $business) : self::siteName()) . "\n";
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
