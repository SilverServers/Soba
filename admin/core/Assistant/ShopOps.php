<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Cms\Commerce\Coupon;
use Cms\Commerce\Money;
use Cms\Commerce\Product;
use Cms\Commerce\Stock;
use Cms\Commerce\StockLog;
use Cms\Commerce\Variation;
use Cms\Database\Database;
use Cms\Models\Meta;
use Cms\Models\Option;
use Cms\Models\Order;
use Cms\Models\Post;
use Cms\Models\Term;
use Cms\Models\User;

/**
 * THE CHANGES THE ASSISTANT MAY PROPOSE TO THE SHOP: a count, an order's
 * status, a new discount code, a new option on a product.
 *
 * ── THE SAME RULES AS EVERY OTHER CHANGE ────────────────────────────────────
 * The model proposes; a person approves the rendered diff; this applies it
 * inside the change set's transaction, through the calls the shop's own
 * screens make: `Variation::setCount()` and `Stock::setCount()` as the Stock
 * screen does, `Order::setStatus()` and `setTracking()` as the order screen
 * does, `Coupon::check()` and `store()` as Discount codes does, and
 * `Variation::add()` by the rules the product's Options panel saves. Each
 * operation carries `from`, the value read, and is refused when it has moved.
 *
 * ── WHAT WAS THERE BEFORE IS KEPT, AND UNDO WORKS FROM IT ───────────────────
 * Revisions keep posts and pages; counts, orders, codes and options live in
 * tables of their own. So each result records what the applier did and what
 * stood before (`apply_results`), and `reverse()` puts it back, refusing,
 * line by line and in words, wherever the shop has moved on since: a count
 * that has changed, an order moved again, a code already used, an option
 * already ordered.
 *
 * ── NOTHING LEAVES BEFORE THE CHANGE STANDS ─────────────────────────────────
 * An order's email to its customer is sent after the change set commits
 * (`afterCommit()`), so a change rolled back has told nobody anything.
 * Webhooks and stock alerts already wait for the request's end and check
 * that what they report stood.
 */
final class ShopOps
{
    public const OPS = ['set_stock', 'set_order_status', 'create_coupon', 'add_option'];

    /**
     * Where the assistant may move an order. Not Refunded: money going back
     * is the refund form's, on the order's screen. Not Pending or Failed,
     * which describe a payment rather than anything a person does.
     */
    public const ORDER_MOVES = ['processing', 'on-hold', 'completed', 'cancelled'];

    /**
     * Why an operation cannot be applied, each a sentence; [] when it can.
     *
     * @return list<string>
     */
    public static function validate(string $type, array $op, ToolContext $ctx): array
    {
        if (!ShopTools::open()) {
            return ['This site has no shop switched on.'];
        }
        if (!$ctx->hasRole('shop_manager')) {
            return ['Only a shop manager or an administrator can change the shop.'];
        }

        return match ($type) {
            'set_stock'        => self::checkStock($op)['errors'],
            'set_order_status' => self::checkOrder($op)['errors'],
            'create_coupon'    => self::checkCoupon($op),
            'add_option'       => self::checkOption($op)['errors'],
            default            => ["'{$type}' is not a shop operation."],
        };
    }

    /**
     * Apply one validated operation; what was done, and what stood before.
     * Throws when the shop moved between the check and the write, which rolls
     * back the whole change set.
     *
     * @return array<string, mixed>
     */
    public static function apply(string $type, array $op, ChangeSet $changeSet, ToolContext $ctx): array
    {
        return match ($type) {
            'set_stock'        => self::applyStock($op, $changeSet, $ctx),
            'set_order_status' => self::applyOrder($op, $ctx),
            'create_coupon'    => self::applyCoupon($op),
            'add_option'       => self::applyOption($op),
            default            => throw new \RuntimeException("'{$type}' is not a shop operation."),
        };
    }

    /**
     * After the change set has committed: each order moved is its customer's
     * email, as a move on the order's screen would be.
     *
     * @param list<array<string, mixed>> $results
     */
    public static function afterCommit(array $results, ToolContext $ctx): void
    {
        foreach ($results as $r) {
            if (($r['op'] ?? '') === 'set_order_status') {
                \Cms\Commerce\OrderMail::statusChanged((int) $r['order_id'], (string) $r['to'], self::by($ctx));
            }
        }
    }

    /**
     * Put back what a change set's shop operations did, newest first.
     *
     * ONLY FOR WHO COULD MAKE THE CHANGE NOW. Applying checks the person as they
     * are at that moment, and undoing is a change to the shop like any other: a
     * shop manager since made an editor undoes nothing of the shop's.
     *
     * EACH LINE WHOLE. One that cannot finish goes back as it was and says so,
     * and the lines after it still get their turn.
     *
     * @param list<array<string, mixed>> $results its `apply_results`
     * @return array{reversed: int, kept: list<string>}
     */
    public static function reverse(array $results, ToolContext $ctx): array
    {
        $out  = ['reversed' => 0, 'kept' => []];
        $mine = array_filter($results, static fn (array $r): bool => in_array((string) ($r['op'] ?? ''), self::OPS, true));
        if ($mine === []) {
            return $out;
        }
        if (!ShopTools::open() || !$ctx->hasRole('shop_manager')) {
            $out['kept'][] = 'Only a shop manager or an administrator can change the shop, so its changes were left as they are.';

            return $out;
        }
        $db = Database::getInstance();
        foreach (array_reverse($mine) as $r) {
            $db->beginTransaction();
            try {
                $kept = match ((string) $r['op']) {
                    'set_stock'        => self::undoStock($r, $ctx),
                    'set_order_status' => self::undoOrder($r, $ctx),
                    'create_coupon'    => self::undoCoupon($r),
                    'add_option'       => self::undoOption($r),
                };
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('[assistant] undoing ' . (string) $r['op'] . ' failed: ' . $e->getMessage());
                $kept = 'One change could not be put back, so it was left as it is: ' . $e->getMessage();
            }
            if ($kept === null) {
                $out['reversed']++;
            } else {
                $out['kept'][] = $kept;
            }
        }

        return $out;
    }

    /**
     * One operation as the reviewer reads it: what, from what, to what.
     *
     * `$settled` for a change no longer waiting: its `from` is what it was
     * proposed from. The stored value is only the question while it can still
     * be approved; once applied, it is the `to`, and the row read "11 → 11".
     *
     * @return array<string, mixed>
     */
    public static function row(array $op, bool $settled = false): array
    {
        $type = (string) ($op['op'] ?? '');
        $row  = ['op' => $type, 'entry' => null, 'type' => null, 'label' => null, 'from' => null, 'to' => null, 'details' => []];

        if ($type === 'set_stock') {
            $check        = self::checkStock($op);
            $row['entry'] = $check['title'] ?? 'Product ' . (int) ($op['entry_id'] ?? 0);
            $row['type']  = 'Stock';
            $row['label'] = 'Count';
            $row['from']  = $settled ? self::text($op['from'] ?? null) : ($check['current'] ?? null);
            $row['to']    = self::text($op['to'] ?? null);
        } elseif ($type === 'set_order_status') {
            $order        = Order::find((int) ($op['order_id'] ?? 0));
            $to           = self::text($op['to'] ?? null);
            $row['entry'] = 'Order #' . (int) ($op['order_id'] ?? 0) . ($order !== null ? ', ' . (string) $order['billing_name'] : '');
            $row['type']  = 'Order';
            $row['label'] = 'Status';
            $row['from']  = $settled ? Order::statusLabel(self::text($op['from'] ?? null))
                : ($order !== null ? Order::statusLabel((string) $order['status']) : null);
            $row['to']    = Order::statusLabel($to);
            $t            = is_array($op['tracking'] ?? null) ? $op['tracking'] : [];
            if (self::text($t['number'] ?? null) !== '') {
                $row['details']['Tracking'] = trim(self::text($t['carrier'] ?? null) . ' ' . self::text($t['number']));
            }
            // WHAT THE CUSTOMER IS SENT, said before it is approved.
            $mail = \Cms\Commerce\OrderMail::STATUS_MAIL[$to] ?? null;
            if ($mail !== null && (string) Option::get($mail, '1') === '1') {
                $row['details']['Emails'] = $order !== null ? (string) $order['email'] : 'the customer';
            }
        } elseif ($type === 'create_coupon') {
            $form         = Coupon::check(self::couponValues($op))['form'];
            $row['entry'] = (string) $form['code'];
            $row['type']  = 'Discount code';
            $row['label'] = 'New code';
            $row['to']    = Coupon::describe((string) $form['kind'], (int) $form['amount'], !empty($form['free_shipping']));
            foreach ([
                'Least spend' => (int) $form['min_spend'] > 0 ? Money::format((int) $form['min_spend']) : '',
                'Uses'        => (int) $form['usage_limit'] > 0 ? (string) (int) $form['usage_limit'] : '',
                'Each customer' => (int) $form['per_customer'] > 0 ? (int) $form['per_customer'] . ' times' : '',
                'First day'   => (string) $form['starts_at'],
                'Last day'    => (string) $form['expires_at'],
                'Only on'     => implode(', ', array_map(static fn (int $id): string => (string) (Post::find($id)['title'] ?? $id), $form['product_ids'])),
                'Switched'    => empty($form['is_active']) ? 'off' : '',
            ] as $k => $v) {
                if ($v !== '') {
                    $row['details'][$k] = $v;
                }
            }
        } elseif ($type === 'add_option') {
            $check        = self::checkOption($op);
            $row['entry'] = $check['title'] ?? 'Product ' . (int) ($op['entry_id'] ?? 0);
            $row['type']  = 'Option';
            $row['label'] = 'New option';
            $row['to']    = $check['label'] ?? null;
            foreach ([
                'Price'      => self::money($op['price'] ?? null),
                'Sale price' => self::money($op['sale_price'] ?? null),
                'Count'      => self::isCount($op['stock'] ?? null) ? (string) (int) $op['stock'] : '',
                'Code'       => self::text($op['sku'] ?? null),
            ] as $k => $v) {
                if ($v !== '') {
                    $row['details'][$k] = $v;
                }
            }
            foreach ($check['new_terms'] ?? [] as $axis => $name) {
                $row['details']['New ' . $axis] = $name;
            }
        }

        return $row;
    }

    // ── A count ─────────────────────────────────────────────────────────────

    /** @return array{errors: list<string>, title?: string, current?: int} */
    private static function checkStock(array $op): array
    {
        $entry = self::product($op);
        if (is_string($entry)) {
            return ['errors' => [$entry]];
        }
        $title    = (string) $entry['title'];
        $optionId = (int) ($op['option_id'] ?? 0);
        if ($optionId > 0) {
            $v = Variation::find($optionId);
            if ($v === null || (int) $v['post_id'] !== (int) $entry['id']) {
                return ['errors' => ["{$title} has no option {$optionId}: read_entry lists its options."]];
            }
            $title .= ' — ' . (string) $v['label'];
            if (empty($v['manage_stock'])) {
                return ['errors' => ["{$title} does not count its stock."], 'title' => $title];
            }
            $current = (int) $v['stock_qty'];
        } else {
            if (Variation::has((int) $entry['id'])) {
                return ['errors' => ["{$title} is sold in options: name one by its option_id."], 'title' => $title];
            }
            $current = Stock::countOf((int) $entry['id']);
            if ($current === null) {
                return ['errors' => ["{$title} does not count its stock."], 'title' => $title];
            }
        }
        $errors = [];
        if (!self::isCount($op['to'] ?? null)) {
            $errors[] = "{$title}: a count is a whole number, 0 or more.";
        }
        $stale = self::stale($op, $current);
        if ($stale !== null) {
            $errors[] = "{$title}: {$stale}";
        }

        return ['errors' => $errors, 'title' => $title, 'current' => $current];
    }

    private static function applyStock(array $op, ChangeSet $changeSet, ToolContext $ctx): array
    {
        $check    = self::checkStock($op);
        $entryId  = (int) $op['entry_id'];
        $optionId = (int) ($op['option_id'] ?? 0);
        $from     = (int) $op['from'];
        $to       = (int) $op['to'];
        // COMPARE AND SET: refused if a sale took one between the check and
        // this write, and the whole change set goes back with it.
        $set = StockLog::during('assistant', null, self::by($ctx), static fn (): bool => $optionId > 0
            ? Variation::setCount($optionId, $from, $to)
            : Stock::setCount($entryId, $from, $to), $changeSet->summary);
        if (!$set) {
            throw new \RuntimeException(($check['title'] ?? 'The count') . ' changed while this waited. Nothing was changed.');
        }

        return ['op' => 'set_stock', 'entry_id' => $entryId, 'option_id' => $optionId > 0 ? $optionId : null,
                'title' => $check['title'] ?? '', 'from' => $from, 'to' => $to];
    }

    /** null when put back; a sentence when kept. */
    private static function undoStock(array $r, ToolContext $ctx): ?string
    {
        $optionId = (int) ($r['option_id'] ?? 0);
        $entryId  = (int) $r['entry_id'];
        $now      = $optionId > 0 ? (($v = Variation::find($optionId)) !== null ? (int) $v['stock_qty'] : null) : Stock::countOf($entryId);
        $title    = (string) ($r['title'] ?? '');
        if ($now !== (int) $r['to']) {
            return "{$title}: the count is " . ($now ?? 'gone') . ' now, not ' . (int) $r['to'] . ', so it was left as it is.';
        }
        $set = StockLog::during('undone', null, self::by($ctx), static fn (): bool => $optionId > 0
            ? Variation::setCount($optionId, (int) $r['to'], (int) $r['from'])
            : Stock::setCount($entryId, (int) $r['to'], (int) $r['from']));

        return $set ? null : "{$title}: the count changed as it was put back, so it was left as it is.";
    }

    // ── An order's status ───────────────────────────────────────────────────

    /** @return array{errors: list<string>, order?: array<string, mixed>} */
    private static function checkOrder(array $op): array
    {
        $id    = (int) ($op['order_id'] ?? 0);
        $order = $id > 0 ? Order::find($id) : null;
        if ($order === null) {
            return ['errors' => ["There is no order #{$id}: find_orders lists them."]];
        }
        $errors = [];
        $to     = self::text($op['to'] ?? null);
        if (!in_array($to, self::ORDER_MOVES, true)) {
            $errors[] = "Order #{$id} can be moved to " . implode(', ', self::ORDER_MOVES)
                . '. Money going back to a customer is a refund, made on the order\'s screen.';
        } elseif ($to === (string) $order['status']) {
            $errors[] = "Order #{$id} is " . Order::statusLabel($to) . ' already.';
        }
        $stale = self::stale($op, (string) $order['status']);
        if ($stale !== null) {
            $errors[] = "Order #{$id}: {$stale}";
        }
        if (isset($op['tracking'])) {
            // NOT QUIETLY DROPPED: approved, it would go without what was proposed.
            $t = is_array($op['tracking']) ? $op['tracking'] : [[]];
            if (array_filter($t, static fn (mixed $v): bool => !is_scalar($v)) !== []) {
                $errors[] = "Order #{$id}: its tracking is a carrier, a number and a link, each in words.";
            }
            $problem = Order::trackingProblem(self::text($t['carrier'] ?? null), self::text($t['number'] ?? null), self::text($t['url'] ?? null));
            if ($problem !== null) {
                $errors[] = "Order #{$id}: {$problem}";
            }
        }

        return ['errors' => $errors, 'order' => $order];
    }

    private static function applyOrder(array $op, ToolContext $ctx): array
    {
        $id    = (int) $op['order_id'];
        $order = (array) Order::find($id);
        $by    = self::by($ctx);
        $was   = ['carrier' => (string) $order['tracking_carrier'], 'number' => (string) $order['tracking_number'], 'url' => (string) $order['tracking_url']];
        // THE PARCEL FIRST, so the email the move sends after the commit
        // carries its number.
        $tracking = null;
        if (is_array($op['tracking'] ?? null)) {
            $t        = $op['tracking'];
            $tracking = ['carrier' => self::text($t['carrier'] ?? null), 'number' => self::text($t['number'] ?? null), 'url' => self::text($t['url'] ?? null)];
            $problem  = Order::setTracking($id, $tracking['carrier'], $tracking['number'], $tracking['url'], $by);
            if ($problem !== null) {
                throw new \RuntimeException('Order #' . $id . ': ' . $problem);
            }
        }
        // QUIETLY HERE; the customer is told once it has committed.
        if (!Order::setStatus($id, (string) $op['to'], $by, false)) {
            throw new \RuntimeException('Order #' . $id . ' could not be moved to ' . Order::statusLabel((string) $op['to']) . '.');
        }

        return ['op' => 'set_order_status', 'order_id' => $id, 'from' => (string) $order['status'], 'to' => (string) $op['to'],
                'paid_at_was' => $order['paid_at'], 'tracking_was' => $was, 'tracking' => $tracking];
    }

    private static function undoOrder(array $r, ToolContext $ctx): ?string
    {
        $id    = (int) $r['order_id'];
        $order = Order::find($id);
        if ($order === null) {
            return "Order #{$id} is gone, so there was nothing to put back.";
        }
        if ((string) $order['status'] !== (string) $r['to']) {
            return "Order #{$id} is " . Order::statusLabel((string) $order['status']) . ' now, so it was left as it is.';
        }
        $by = self::by($ctx);
        // BACK WITHOUT AN EMAIL: the customer was told of the move, and the
        // note on the order says it was undone.
        Order::setStatus($id, (string) $r['from'], $by, false);
        // NOT PAID AFTER ALL, if this change is what stamped it.
        Order::restorePaidAt($id, $r['paid_at_was'] ?? null);
        if (is_array($r['tracking'] ?? null)) {
            $was = (array) $r['tracking_was'];
            Order::setTracking($id, (string) $was['carrier'], (string) $was['number'], (string) $was['url'], $by);
        }
        Order::addNote($id, 'The assistant\'s change was undone: back to ' . Order::statusLabel((string) $r['from']) . '. The customer was not emailed again.', false, $by);

        return null;
    }

    // ── A discount code ─────────────────────────────────────────────────────

    /** @return list<string> */
    private static function checkCoupon(array $op): array
    {
        $values = self::couponValues($op);
        $errors = Coupon::check($values)['errors'];
        // NOT QUIETLY DROPPED: approved, it would be a code without what was proposed.
        if (array_diff_key(is_array($op['values'] ?? null) ? $op['values'] : [], $values) !== []) {
            $errors[] = 'Each of a code\'s values is a word or a number, and its product_ids a list.';
        }
        // THE FORM'S SELECT CANNOT SEND ANYTHING ELSE; a proposal can, and read
        // as a percentage "5.00" fixed is a different code from the one asked for.
        if (!in_array($values['kind'] ?? '', [Coupon::PERCENT, Coupon::FIXED], true)) {
            $errors[] = 'A code takes off a percentage or a fixed amount: its kind is "percent" or "fixed".';
        }

        return $errors;
    }

    /**
     * A proposed code as the form would post it: plain values and its list of
     * products. On unless the proposal says otherwise; the form's unticked box
     * posts nothing, and a code asked for should not arrive switched off.
     *
     * @return array<string, mixed>
     */
    private static function couponValues(array $op): array
    {
        $out = [];
        foreach (is_array($op['values'] ?? null) ? $op['values'] : [] as $key => $value) {
            if ($key === 'product_ids' ? is_array($value) : is_scalar($value)) {
                $out[$key] = $value;
            }
        }

        return $out + ['is_active' => true];
    }

    private static function applyCoupon(array $op): array
    {
        $checked = Coupon::check(self::couponValues($op));
        if ($checked['errors'] !== []) {
            throw new \RuntimeException(implode(' ', $checked['errors']));
        }
        $id = Coupon::store($checked['form']);

        return ['op' => 'create_coupon', 'coupon_id' => $id, 'code' => (string) $checked['form']['code']];
    }

    private static function undoCoupon(array $r): ?string
    {
        $coupon = Coupon::findById((int) $r['coupon_id']);
        if ($coupon === null) {
            return 'The code ' . (string) $r['code'] . ' is gone already.';
        }
        if ((int) $coupon['times_used'] > 0) {
            return 'The code ' . (string) $r['code'] . ' has been used ' . (int) $coupon['times_used']
                . ' time' . ((int) $coupon['times_used'] === 1 ? '' : 's') . ' since, so it was kept. Switch it off on Discount codes instead.';
        }
        Coupon::delete((int) $coupon['id']);

        return null;
    }

    // ── A new option ────────────────────────────────────────────────────────

    /**
     * @return array{errors: list<string>, title?: string, label?: string,
     *               terms?: array<string, array{id: ?int, name: string}>, new_terms?: array<string, string>}
     */
    private static function checkOption(array $op): array
    {
        $entry = self::product($op);
        if (is_string($entry)) {
            return ['errors' => [$entry]];
        }
        $title   = (string) $entry['title'];
        $postId  = (int) $entry['id'];
        $axes    = Variation::optionTaxonomies((string) $entry['post_type']);
        $rows    = Variation::forProduct($postId);
        $errors  = [];
        $chosen  = [];   // taxonomy => ['id' => ?int, 'name' => string]
        $newOnes = [];   // axis label => name, for terms that do not exist yet

        // EACH CHOICE BY ITS AXIS — "size", "Size" or the taxonomy's key.
        foreach (is_array($op['choices'] ?? null) ? $op['choices'] : [] as $axis => $name) {
            $name = self::text($name);
            $tax  = null;
            foreach ($axes as $key => $def) {
                $names = array_map('mb_strtolower', [(string) $key, (string) ($def['label'] ?? ''), (string) ($def['label_single'] ?? '')]);
                if (in_array(mb_strtolower(trim((string) $axis)), $names, true)) {
                    $tax = (string) $key;
                }
            }
            if ($tax === null) {
                $errors[] = "{$title} is not sold by " . $axis . ($axes === [] ? '.' : '; its options are by '
                    . implode(' and ', array_map(static fn (array $d): string => (string) ($d['label_single'] ?? $d['label']), $axes)) . '.');
                continue;
            }
            if ($name === '' || mb_strlen($name) > 60) {
                $errors[] = "{$title}: each choice is a name of 1 to 60 characters.";
                continue;
            }
            $term = self::termNamed($tax, $name);
            $chosen[$tax] = ['id' => $term !== null ? (int) $term['id'] : null, 'name' => $term !== null ? (string) $term['name'] : $name];
            if ($term === null) {
                $newOnes[mb_strtolower((string) ($axes[$tax]['label_single'] ?? $axes[$tax]['label'] ?? $tax))] = $name;
            }
        }

        // THE SAME AXES AS ITS SIBLINGS: a tee sold by size and colour gets a
        // new option with both, or the chooser cannot offer it.
        $used = [];
        foreach ($rows as $v) {
            $used += Variation::termsOf($v);
        }
        $missing = array_diff(array_keys($used), array_keys($chosen));
        if ($chosen !== [] || $used !== []) {
            foreach ($missing as $tax) {
                $errors[] = "{$title}'s options each have a " . mb_strtolower((string) ($axes[$tax]['label_single'] ?? $tax)) . ': choose one.';
            }
        }

        // ITS NAME: its choices in the axes' order, as "Make options" names one.
        $label = '';
        if ($chosen !== []) {
            $parts = [];
            foreach (array_keys($axes) as $tax) {
                if (isset($chosen[$tax])) {
                    $parts[] = $chosen[$tax]['name'];
                }
            }
            $label = implode(' / ', $parts);
        } else {
            $label = self::text($op['label'] ?? null);
        }
        if ($label === '') {
            $errors[] = "{$title}: a new option needs its choices, or a name.";
        }
        foreach ($rows as $v) {
            if ($label !== '' && mb_strtolower((string) $v['label']) === mb_strtolower($label)) {
                $errors[] = "{$title} already has {$label}.";
            }
        }

        foreach (['price' => 'price', 'sale_price' => 'sale price'] as $key => $words) {
            $raw = self::text($op[$key] ?? null);
            if ((isset($op[$key]) && !is_scalar($op[$key])) || ($raw !== '' && Money::parse($raw) === null)) {
                $errors[] = "{$title}: the {$words} is not an amount of money.";
            }
        }
        $price = Money::parse(self::text($op['price'] ?? null) ?: (string) (Meta::get($postId, Product::PRICE) ?? ''));
        $sale  = Money::parse(self::text($op['sale_price'] ?? null));
        if ($sale !== null && $price !== null && $sale >= $price) {
            $errors[] = "{$title}: a sale price is less than the price.";
        }
        if (isset($op['stock']) && !self::isCount($op['stock'])) {
            $errors[] = "{$title}: a count is a whole number, 0 or more.";
        }
        $sku = self::text($op['sku'] ?? null);
        if (isset($op['sku']) && !is_scalar($op['sku'])) {
            $errors[] = "{$title}: its code is a word.";
        }
        if ($sku !== '') {
            foreach (Product::skuProblems((string) (Meta::get($postId, Product::SKU) ?? ''),
                array_merge($rows, [['label' => $label, 'sku' => $sku]]), $postId) as $problem) {
                $errors[] = $problem;
            }
        }
        foreach ($chosen as $tax => $c) {
            $ids = Term::getPostTermIds($postId, $tax);
            if ($c['id'] !== null && !in_array($c['id'], $ids, true)) {
                $problem = Term::countViolation($tax, array_merge($ids, [$c['id']]));
                if ($problem !== null) {
                    $errors[] = "{$title}: {$problem}";
                }
            }
        }

        return ['errors' => $errors, 'title' => $title, 'label' => $label, 'terms' => $chosen, 'new_terms' => $newOnes];
    }

    private static function applyOption(array $op): array
    {
        $check  = self::checkOption($op);
        $postId = (int) $op['entry_id'];
        $made   = [];
        $ticked = [];
        $terms  = [];
        foreach ($check['terms'] ?? [] as $tax => $c) {
            $id = $c['id'];
            if ($id === null) {
                // A SIZE THE SHOP DID NOT HAVE, made as the Sizes screen
                // would make it.
                $id = Term::create(['taxonomy_key' => $tax, 'name' => $c['name'], 'slug' => Term::generateSlug($c['name'], $tax)]);
                $made[] = $id;
            }
            $have = Term::getPostTermIds($postId, $tax);
            if (!in_array($id, $have, true)) {
                Term::setPostTerms($postId, $tax, array_merge($have, [$id]));
                $ticked[] = ['taxonomy' => $tax, 'id' => $id];
            }
            $terms[$tax] = $id;
        }
        $rows    = Variation::forProduct($postId);
        $counted = isset($op['stock']) || ($rows !== [] && !empty($rows[0]['manage_stock']));
        $count   = isset($op['stock']) ? (int) $op['stock'] : 0;
        $id      = Variation::add($postId, [
            'label'        => (string) $check['label'],
            'sku'          => self::text($op['sku'] ?? null),
            'price'        => self::text($op['price'] ?? null),
            'sale_price'   => self::text($op['sale_price'] ?? null),
            'terms'        => $terms,
            'manage_stock' => $counted ? 1 : 0,
            'stock_qty'    => $count,
        ]);
        if ($id === null) {
            throw new \RuntimeException('The new option had no name.');
        }

        return ['op' => 'add_option', 'entry_id' => $postId, 'option_id' => $id, 'title' => (string) $check['title'],
                'label' => (string) $check['label'], 'count' => $counted ? $count : null, 'made_terms' => $made, 'ticked_terms' => $ticked];
    }

    private static function undoOption(array $r): ?string
    {
        $name = (string) $r['title'] . ' — ' . (string) $r['label'];
        $v    = Variation::find((int) $r['option_id']);
        if ($v === null) {
            return "{$name} is gone already.";
        }
        $db   = Database::getInstance();
        $sold = $db->prepare('SELECT COUNT(*) FROM order_items WHERE variation_id = :id');
        $sold->execute([':id' => (int) $v['id']]);
        if ((int) $sold->fetchColumn() > 0) {
            return "{$name} has been ordered since, so it was kept.";
        }
        if ($r['count'] !== null && (int) $v['stock_qty'] !== (int) $r['count']) {
            return "{$name}'s count has changed since, so it was kept.";
        }
        Variation::remove((int) $v['id']);
        $postId = (int) $r['entry_id'];
        // THE SIZE IT TICKED ON THE PRODUCT, unticked if no option still has it.
        $left = [];
        foreach (Variation::forProduct($postId) as $other) {
            $left = array_merge($left, array_values(Variation::termsOf($other)));
        }
        foreach ((array) $r['ticked_terms'] as $t) {
            if (!in_array((int) $t['id'], $left, true)) {
                Term::setPostTerms($postId, (string) $t['taxonomy'],
                    array_values(array_diff(Term::getPostTermIds($postId, (string) $t['taxonomy']), [(int) $t['id']])));
            }
        }
        // AND THE SIZE IT MADE, if nothing else has it: filed on no entry, and
        // in no option of any product.
        $inOptions = [];
        foreach ($db->query('SELECT terms FROM product_variations WHERE terms IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN) as $raw) {
            $inOptions = array_merge($inOptions, array_values(Variation::termsOf(['terms' => $raw])));
        }
        foreach ((array) $r['made_terms'] as $termId) {
            $filed = $db->prepare('SELECT COUNT(*) FROM post_term_relationships WHERE term_id = :t');
            $filed->execute([':t' => (int) $termId]);
            if ((int) $filed->fetchColumn() === 0 && !in_array((int) $termId, $inOptions, true)) {
                Term::delete((int) $termId);
            }
        }

        return null;
    }

    // ── Shared ──────────────────────────────────────────────────────────────

    /** The product an operation names, or why it cannot be one. @return array<string, mixed>|string */
    private static function product(array $op): array|string
    {
        $id    = (int) ($op['entry_id'] ?? 0);
        $entry = $id > 0 ? Post::find($id) : null;
        if ($entry === null) {
            return "There is no product {$id}.";
        }
        if (!Product::isSellableType((string) $entry['post_type'])) {
            return (string) $entry['title'] . ' is not something the shop sells.';
        }

        return $entry;
    }

    /** A term of a taxonomy by its name, without regard to case. */
    private static function termNamed(string $tax, string $name): ?array
    {
        foreach (Term::allForTaxonomy($tax) as $term) {
            if (mb_strtolower((string) $term['name']) === mb_strtolower($name)) {
                return $term;
            }
        }

        return null;
    }

    /** Why `from` does not match what is stored, or null; `from` is required. */
    private static function stale(array $op, int|string $current): ?string
    {
        if (!array_key_exists('from', $op)) {
            return "missing 'from', the value read.";
        }
        if (!is_scalar($op['from']) || (string) $op['from'] !== (string) $current) {
            return 'it has changed since this was proposed (expected "' . self::text($op['from'])
                . '", found "' . $current . '"). Read it again and propose again.';
        }

        return null;
    }

    /**
     * A value the model sent where words or a number belong. Anything may arrive
     * here: casting an array is "Array" and a warning in the log, for a proposal
     * that is refused anyway.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** An amount the model sent, as the shop writes it; '' when it is not one. */
    private static function money(mixed $value): string
    {
        $minor = Money::parse(self::text($value));

        return $minor === null ? '' : Money::format($minor);
    }

    private static function isCount(mixed $n): bool
    {
        return (is_int($n) && $n >= 0) || (is_string($n) && ctype_digit($n));
    }

    /** Who did it, as the stock history and the order's notes name them. */
    private static function by(ToolContext $ctx): string
    {
        $user = User::find($ctx->userId);

        return ($user !== null ? User::displayName($user) : 'Someone') . ', with the assistant';
    }
}
