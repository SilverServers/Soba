<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use PDO;

/**
 * EVERY CHANGE TO A COUNT ON THE SHELF, AND WHY.
 *
 * ── RECORDED WHERE THE COUNT CHANGES ────────────────────────────────────────
 * Four places move stock: a product's own count taken and given back
 * (`Stock::take()`, `Stock::give()`) and an option's (`Variation::take()`,
 * `Variation::give()`), plus a product saved with its counts typed over. Each
 * writes a line here: what, by how much, what the count is after, and why. The
 * why comes from whoever asked (`during()`): a checkout selling, an order
 * cancelled, a refund putting goods back, the stock screen receiving a
 * delivery. A change nobody named is recorded as a change, never left out.
 *
 * ── THE ORDER IS KNOWN AFTER THE STOCK IS TAKEN ─────────────────────────────
 * A checkout takes the goods first, so a sold-out size refuses before an order
 * exists. The lines it wrote are given their order once it has one
 * (`claimFor()`), within the same request.
 *
 * ── NAMED AS IT WAS ─────────────────────────────────────────────────────────
 * The product's and the option's names are copied onto the line, as an order
 * copies its lines', so a product renamed or deleted later leaves its history
 * reading as it happened.
 */
final class StockLog
{
    /** The reasons a count changes, as the history says them. */
    public const REASONS = [
        'sold'       => 'Sold',
        'cancelled'  => 'Order cancelled',
        'reinstated' => 'Order reinstated',
        'refunded'   => 'Refunded',
        'deleted'    => 'Order deleted',
        'exchanged'  => 'Exchanged',
        'received'   => 'Received',
        'returned'   => 'Returned',
        'damaged'    => 'Damaged or lost',
        'recount'    => 'Recounted',
        'edited'     => 'Edited on the product',
        'bulk'       => 'Bulk edit',
        'imported'   => 'Imported',
        'api'        => 'Set by another program',
        'assistant'  => 'Set by the assistant',
        'undone'     => 'Assistant change undone',
        'changed'    => 'Changed',
    ];

    /** The reasons an owner gives on the stock screen. */
    public const SCREEN_REASONS = ['received', 'returned', 'damaged', 'recount'];

    /** @var list<array{reason: string, order: ?int, by: string, note: string}> who is changing stock, innermost last */
    private static array $context = [];

    /** @var list<int> lines written in this request with no order yet */
    private static array $unclaimed = [];

    /**
     * Run `$work` with every stock change it makes recorded under this reason.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function during(string $reason, ?int $orderId, string $by, callable $work, string $note = ''): mixed
    {
        self::$context[] = [
            'reason' => isset(self::REASONS[$reason]) ? $reason : 'changed',
            'order'  => $orderId,
            'by'     => $by,
            'note'   => $note,
        ];
        try {
            return $work();
        } finally {
            array_pop(self::$context);
        }
    }

    /**
     * One change to one count. Called by the four places stock moves, never by
     * a screen: a screen names its reason with `during()`.
     */
    public static function record(int $postId, ?int $variationId, int $change, ?int $after, ?string $name = null): void
    {
        if ($change === 0) {
            return;
        }
        $context = self::$context === [] ? ['reason' => 'changed', 'order' => null, 'by' => '', 'note' => '']
                                          : self::$context[count(self::$context) - 1];
        $label   = mb_substr($name ?? self::label($postId, $variationId), 0, 255);

        $stmt = Database::getInstance()->prepare(
            'INSERT INTO stock_moves (post_id, variation_id, label, change_qty, qty_after, reason, note, order_id, author)
             VALUES (:p, :v, :l, :c, :a, :r, :n, :o, :b)'
        );
        $stmt->execute([
            ':p' => $postId,
            ':v' => $variationId,
            ':l' => $label,
            ':c' => $change,
            ':a' => $after,
            ':r' => $context['reason'],
            ':n' => mb_substr($context['note'], 0, 255),
            ':o' => $context['order'],
            ':b' => mb_substr($context['by'], 0, 120),
        ]);
        $lineId = (int) Database::getInstance()->lastInsertId();
        if ($context['order'] === null) {
            self::$unclaimed[] = $lineId;
        }

        // RUNNING LOW OR SOLD OUT, told to the shop; back, told to the
        // customers who asked: when the request ends, if this line stood.
        StockAlert::afterChange($lineId, $postId, $variationId, $change, $after, $label);
    }

    /**
     * Give the lines this request wrote without an order to the order that
     * now exists: a checkout, or an order the shop took, took its goods first.
     */
    public static function claimFor(int $orderId): void
    {
        if (self::$unclaimed === []) {
            return;
        }
        $ids  = array_map('intval', self::$unclaimed);
        $stmt = Database::getInstance()->prepare(
            'UPDATE stock_moves SET order_id = ? WHERE order_id IS NULL AND id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute(array_merge([$orderId], $ids));
        self::$unclaimed = [];
    }

    /**
     * A product's history, newest first, its options' with it.
     *
     * @return list<array<string, mixed>>
     */
    public static function forProduct(int $postId, int $limit = 200): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM stock_moves WHERE post_id = :p ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(2000, $limit))
        );
        $stmt->execute([':p' => $postId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * A product's counts compared before and after its screen was saved, or
     * a spreadsheet imported (`ProductSheet`): each one that changed, a line
     * under "Edited on the product", or the reason given. An option removed
     * takes its count with it, named as it was.
     *
     * @param array<string, array{0: int, 1: ?int, 2: int, 3: string}> $before `Stock::countsOf()`
     * @param array<string, array{0: int, 1: ?int, 2: int, 3: string}> $after
     */
    public static function edited(array $before, array $after, string $by, string $reason = 'edited'): void
    {
        self::during($reason, null, $by, static function () use ($before, $after): void {
            foreach ($after + $before as $key => [$postId, $variationId, $count, $name]) {
                $was = isset($before[$key]) ? $before[$key][2] : 0;
                $now = isset($after[$key]) ? $after[$key][2] : 0;
                if ($was === $now) {
                    continue;
                }
                $title = self::label($postId, null);
                self::record($postId, isset($after[$key]) ? $variationId : null, $now - $was, isset($after[$key]) ? $now : null,
                    $title . ($name !== '' ? ' — ' . $name : ''));
            }
        });
    }

    /** What the history calls a reason. */
    public static function reasonLabel(string $reason): string
    {
        return self::REASONS[$reason] ?? ucfirst($reason);
    }

    /** "Union Hoodie — M / Navy", or the product's title alone. */
    private static function label(int $postId, ?int $variationId): string
    {
        $stmt = Database::getInstance()->prepare('SELECT title FROM posts WHERE id = :id');
        $stmt->execute([':id' => $postId]);
        $title = (string) ($stmt->fetchColumn() ?: '');
        if ($variationId === null) {
            return $title;
        }
        $v = Variation::find($variationId);

        return $title . ($v !== null ? ' — ' . (string) $v['label'] : '');
    }
}
