<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use Cms\Mail\Mailer;
use Cms\Models\Option;
use PDO;

/**
 * WHEN A COUNT IS LOW, OR GONE, OR BACK: the shop told it is running low or
 * sold out, and the customers who asked told it is back.
 *
 * ── ONE PLACE SEES EVERY CHANGE ─────────────────────────────────────────────
 * `StockLog::record()` is called wherever a count moves, with the change and
 * the count after. A count that goes down and is left at the shop's low mark
 * or under tells the shop it is running low, and one left at nothing that it
 * has sold out: each time it goes down, so the next sale of a size still
 * running low says so again, as a shop's stock emails do. A count that goes
 * up from nothing is back.
 *
 * ── TOLD WHEN THE REQUEST ENDS, AND ONLY WHAT STOOD ─────────────────────────
 * A refund, an exchange and an order deleted move stock inside a transaction,
 * and an email cannot be taken back with it. So each change is held with its
 * line in the history, and when the request ends (`flush()`) only the changes
 * whose line is still there are told: one undone with its transaction left
 * none. The page is handed back first, as the security alerts do
 * (`Security\Alerts`), so a checkout does not wait on a mail server, and a
 * delivery with thirty customers waiting for it does not wait on thirty.
 *
 * ── THE SHOP IS EMAILED AT ITS NOTIFICATION ADDRESS ─────────────────────────
 * The one the new-order emails go to (Settings → Shop), and only when Settings
 * → Shop says to. A shop with no address has chosen not to be emailed.
 *
 * ── A CUSTOMER WHO ASKED IS EMAILED ONCE, AND FORGOTTEN ─────────────────────
 * An address left on a sold-out option is kept for that one email and removed
 * as it is sent: it was given for that and for nothing else. It goes only
 * while the thing can still be bought when the request ends, and an address
 * whose email could not be sent is kept for the next time it is back.
 */
final class StockAlert
{
    public const MAIL_KEY = 'commerce_mail_low';

    /** @var list<array{line: int, post: int, variation: ?int, change: int, after: int, label: string}> held until the request ends */
    private static array $held = [];

    private static bool $flushQueued = false;

    /**
     * A count moved, and `$lineId` is its line in the history. Held until the
     * request ends when it says something: down to the low mark or under, or
     * up from nothing.
     */
    public static function afterChange(int $lineId, int $postId, ?int $variationId, int $change, ?int $after, string $label): void
    {
        if ($after === null || $change === 0) {
            return;
        }
        if ($change < 0 ? $after > Stock::lowAt() : $after - $change > 0) {
            return;
        }
        self::$held[] = ['line' => $lineId, 'post' => $postId, 'variation' => $variationId,
                         'change' => $change, 'after' => $after, 'label' => $label];
        if (!self::$flushQueued) {
            self::$flushQueued = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /**
     * When the request ends: each held change whose line stood, told. Never
     * throws: a mail server that is down must not become a broken page.
     */
    public static function flush(): void
    {
        $held       = self::$held;
        self::$held = [];
        if ($held === []) {
            return;
        }
        try {
            $db = Database::getInstance();
            // A REQUEST THAT ENDED INSIDE A TRANSACTION ended on an error, and
            // nothing in it stands.
            if ($db->inTransaction()) {
                return;
            }
            $ids   = array_column($held, 'line');
            $stmt  = $db->prepare('SELECT id FROM stock_moves WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            $stood = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

            $shop    = [];
            $waiting = [];
            foreach ($held as $h) {
                if (!isset($stood[$h['line']])) {
                    continue;
                }
                if ($h['change'] < 0) {
                    $shop[] = $h['after'] <= 0
                        ? ['Sold out: ' . $h['label'], $h['label'] . ' has sold out.']
                        : ['Running low: ' . $h['label'], $h['label'] . ' is running low: ' . $h['after'] . ' left.'];
                } elseif (self::buyable($h['post'], $h['variation'])) {
                    $waiting[] = $h;
                }
            }
            $to = Mailer::adminAddress();
            if ($to === '' || (string) Option::get(self::MAIL_KEY, '1') !== '1') {
                $shop = [];
            }
            if ($shop === [] && $waiting === []) {
                return;
            }

            // THE PAGE HANDED BACK FIRST, its session written so the next click
            // sees this one's.
            if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                fastcgi_finish_request();
            }
            foreach ($shop as [$subject, $body]) {
                Mailer::send([$to], $subject, $body . "\n\n" . (defined('ADMIN_URL') ? ADMIN_URL . '/stock' . "\n" : ''));
            }
            foreach ($waiting as $h) {
                self::backInStock($h['post'], $h['variation'], $h['label']);
            }
        } catch (\Throwable $e) {
            error_log('CMS stock alerts: ' . $e->getMessage());
        }
    }

    /**
     * A customer's address left on a sold-out product or option, once.
     *
     * @return string|null what is wrong, in a sentence, or null when kept
     */
    public static function waitFor(int $postId, ?int $variationId, string $email): ?string
    {
        $email = trim($email);
        if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'That does not look like an email address.';
        }
        $db   = Database::getInstance();
        $seen = $db->prepare(
            'SELECT COUNT(*) FROM stock_waitlist WHERE post_id = :p AND email = :e AND variation_id <=> :v'
        );
        $seen->execute([':p' => $postId, ':e' => $email, ':v' => $variationId]);
        if ((int) $seen->fetchColumn() === 0) {
            $stmt = $db->prepare('INSERT INTO stock_waitlist (post_id, variation_id, email) VALUES (:p, :v, :e)');
            $stmt->execute([':p' => $postId, ':v' => $variationId, ':e' => $email]);
        }

        return null;
    }

    /**
     * Each address waiting for this, told it is back. An address is taken off
     * the list before its email goes, so two requests cannot both send it, and
     * put back if the email could not be sent.
     */
    private static function backInStock(int $postId, ?int $variationId, string $label): void
    {
        $db   = Database::getInstance();
        $find = $db->prepare('SELECT id, email, created_at FROM stock_waitlist WHERE post_id = :p AND variation_id <=> :v');
        $find->execute([':p' => $postId, ':v' => $variationId]);
        $waiting = $find->fetchAll(PDO::FETCH_ASSOC);
        if ($waiting === []) {
            return;
        }

        $product = Product::find($postId);
        $url     = $product !== null ? self::productUrl($product) : '';
        $site    = \Cms\SEO\Head::globalTokens()['site_name'];
        $sign    = Business::lines() !== [] ? implode("\n", Business::lines()) : $site;
        $take    = $db->prepare('DELETE FROM stock_waitlist WHERE id = :id');
        $again   = $db->prepare('INSERT INTO stock_waitlist (post_id, variation_id, email, created_at) VALUES (:p, :v, :e, :c)');
        foreach ($waiting as $row) {
            $take->execute([':id' => (int) $row['id']]);
            if ($take->rowCount() !== 1) {
                continue;
            }
            $sent = Mailer::send(
                [(string) $row['email']],
                $site . ' — ' . $label . ' is back',
                $label . ' is back in stock.' . "\n\n"
                    . ($url !== '' ? $url . "\n\n" : '')
                    . 'You asked to hear when it was. This is the only email you will get about it.' . "\n\n"
                    . $sign . "\n",
                ['reply_to' => Mailer::adminAddress()]
            );
            if (!$sent) {
                $again->execute([':p' => $postId, ':v' => $variationId, ':e' => (string) $row['email'], ':c' => (string) $row['created_at']]);
            }
        }
    }

    /** Whether it can be bought now: back, and not sold again since. */
    private static function buyable(int $postId, ?int $variationId): bool
    {
        if ($variationId !== null) {
            $v = Variation::find($variationId);

            return $v !== null && Variation::isPurchasable($v);
        }
        $product = Product::find($postId);

        return $product !== null && $product['purchasable'];
    }

    /** Where the product is on the site, from its type's public address. */
    private static function productUrl(array $product): string
    {
        $type = \Cms\ContentType\Registry::get((string) $product['post_type']);
        if ($type === null || (string) ($type['public_slug'] ?? '') === '') {
            return '';
        }

        return \Cms\Helpers\Url::page(trim((string) $type['public_slug'], '/') . '/' . (string) $product['slug']);
    }
}
