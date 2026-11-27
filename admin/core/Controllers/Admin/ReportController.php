<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Commerce\Reports;
use Cms\Http\Cache;

/**
 * SHOP → REPORTS: what sold over a stretch of days, by day or month, by product
 * and option, and the tax collected by where it was charged (`Commerce\Reports`).
 *
 * The stretch is GET parameters, so a report is an address that can be sent
 * on, and each table downloads as a spreadsheet for the same stretch. The shop
 * manager's, as the orders are.
 */
class ReportController
{
    /** The stretches offered by name; `custom` reads its two days. */
    public const PERIODS = [
        'this-month' => 'This month',
        'last-month' => 'Last month',
        'this-year'  => 'This year',
        'last-year'  => 'Last year',
        'custom'     => 'Between two days',
    ];

    public function index(array $vars = []): void
    {
        Auth::requireRole('shop_manager');

        [$period, $from, $to, $by] = $this->asked();
        $summary  = Reports::summary($from, $to);
        $byDate   = Reports::byDate($from, $to, $by);
        $products = Reports::byProduct($from, $to);
        $options  = Reports::byProduct($from, $to, true);
        $taxes    = Reports::taxes($from, $to);

        $pageTitle   = 'Reports';
        $currentPage = 'reports';

        require ROOT_PATH . '/admin/templates/reports/index.php';
    }

    /** GET /admin/reports/export — one table, for the same stretch, as a spreadsheet. */
    public function export(array $vars = []): void
    {
        Auth::requireRole('shop_manager');

        [$period, $from, $to, $by] = $this->asked();
        $which = (string) ($_GET['report'] ?? 'sales');
        [$rows, $columns] = match ($which) {
            'products' => [Reports::byProduct($from, $to), ['name' => 'Product', 'units' => 'Units', 'sales' => 'Sales']],
            'options'  => [Reports::byProduct($from, $to, true), ['name' => 'Product and option', 'units' => 'Units', 'sales' => 'Sales']],
            'taxes'    => [Reports::taxes($from, $to), ['place' => 'Where', 'tax' => 'Tax', 'orders' => 'Orders',
                           'collected' => 'Collected', 'refunded' => 'Refunded', 'net' => 'Net']],
            default    => [Reports::byDate($from, $to, $by), ['when' => $by === 'month' ? 'Month' : 'Day', 'orders' => 'Orders',
                           'items' => 'Items', 'total' => 'Total', 'refunded' => 'Refunded', 'net' => 'Net']],
        };
        $which = in_array($which, ['products', 'options', 'taxes'], true) ? $which : 'sales';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $which . '-' . $from . '-to-' . $to . '.csv"');
        Cache::noStore();
        // THE BYTE-ORDER MARK FIRST, as the orders spreadsheet's.
        echo "\xEF\xBB\xBF" . Reports::csv($rows, $columns);
        exit;
    }

    /**
     * The stretch asked for: a period by name, or two days; and by day or by
     * month. Two days the wrong way round are taken the right way round.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} period, first day, last day, by
     */
    private function asked(): array
    {
        $period = (string) ($_GET['period'] ?? 'this-month');
        $period = isset(self::PERIODS[$period]) ? $period : 'this-month';
        $by     = ($_GET['by'] ?? '') === 'month' ? 'month' : 'day';

        [$from, $to] = Reports::named($period === 'custom' ? 'this-month' : $period);
        if ($period === 'custom') {
            $day  = static fn (mixed $v): ?string => is_string($v) && \DateTimeImmutable::createFromFormat('!Y-m-d', $v) !== false
                && \DateTimeImmutable::createFromFormat('!Y-m-d', $v)->format('Y-m-d') === $v ? $v : null;
            $from = $day($_GET['from'] ?? null) ?? $from;
            $to   = $day($_GET['to'] ?? null) ?? $to;
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
        }

        return [$period, $from, $to, $by];
    }
}
