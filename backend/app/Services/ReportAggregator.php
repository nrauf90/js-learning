<?php

namespace App\Services;

use App\Models\CashEntry;
use App\Models\ExpenseCategory;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Pos\DayBookService;
use App\Support\SaleProfit;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class ReportAggregator
{
    /** A shop reads the top of its list, not the tail of it. */
    private const TOP_PRODUCTS = 10;

    /**
     * The cash-ledger side of wastage, for a shop that writes off "Rs 500 of
     * sabzi" by hand instead of adjusting stock. Seeded by
     * ExpenseCategorySeeder; named here so the P&L can move it out of general
     * expenses and onto the wastage line, where it cannot be counted twice.
     */
    private const WASTAGE_CATEGORY_SLUG = 'wastage';

    /**
     * The drawer figure is the till's own, not a second implementation of it.
     * A reports screen that disagrees with the day-book screen about how much
     * cash is in the drawer is worse than no reports screen at all.
     */
    public function __construct(private DayBookService $dayBook) {}

    /**
     * All aggregation happens in SQL (SUM/GROUP BY) rather than pulling every
     * matching row into PHP — a user's whole history no longer has to be
     * loaded into memory just to compute a report.
     *
     * @return array{
     *     total_income: float,
     *     total_expense: float,
     *     net: float,
     *     income_by_category: list<array{category: string, amount: float}>,
     *     expense_by_category: list<array{category: string, amount: float}>,
     *     by_category: list<array{category: string, amount: float}>,
     *     by_day: list<array{date: string, income: float, expense: float}>
     * }
     */
    public function aggregate(int $userId, string $from, string $to): array
    {
        $totalsByType = CashEntry::query()
            ->where('user_id', $userId)
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->groupBy('type')
            ->selectRaw('type, SUM(amount) as total')
            ->pluck('total', 'type');

        $totalIncome = (float) ($totalsByType['income'] ?? 0);
        $totalExpense = (float) ($totalsByType['expense'] ?? 0);

        $categoryRows = CashEntry::query()
            ->join('expense_categories', 'expense_categories.id', '=', 'cash_entries.category_id')
            ->where('cash_entries.user_id', $userId)
            ->whereDate('cash_entries.entry_date', '>=', $from)
            ->whereDate('cash_entries.entry_date', '<=', $to)
            ->groupBy('cash_entries.type', 'expense_categories.id', 'expense_categories.name')
            ->orderByDesc(DB::raw('SUM(cash_entries.amount)'))
            ->get([
                'cash_entries.type as type',
                'expense_categories.name as category',
                DB::raw('SUM(cash_entries.amount) as amount'),
            ]);

        $incomeRows = [];
        $expenseRows = [];
        foreach ($categoryRows as $row) {
            $formatted = ['category' => $row->category, 'amount' => round((float) $row->amount, 2)];
            if ($row->type === 'income') {
                $incomeRows[] = $formatted;
            } else {
                $expenseRows[] = $formatted;
            }
        }

        $dayRows = CashEntry::query()
            ->where('user_id', $userId)
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->groupBy('entry_date', 'type')
            ->orderBy('entry_date')
            ->get(['entry_date', 'type', DB::raw('SUM(amount) as amount')]);

        /** @var array<string, array{date: string, income: float, expense: float}> $byDay */
        $byDay = [];
        foreach ($dayRows as $row) {
            $date = $row->entry_date?->format('Y-m-d') ?? (string) $row->entry_date;
            $byDay[$date] ??= ['date' => $date, 'income' => 0.0, 'expense' => 0.0];
            $byDay[$date][$row->type] += (float) $row->amount;
        }
        ksort($byDay);
        $byDay = array_values(array_map(
            fn (array $d) => ['date' => $d['date'], 'income' => round($d['income'], 2), 'expense' => round($d['expense'], 2)],
            $byDay
        ));

        return [
            'total_income' => round($totalIncome, 2),
            'total_expense' => round($totalExpense, 2),
            'net' => round($totalIncome - $totalExpense, 2),
            'income_by_category' => $incomeRows,
            'expense_by_category' => $expenseRows,
            'by_category' => $expenseRows,
            'by_day' => $byDay,
        ];
    }

    /**
     * "Aaj kitna kamaya?" — the trading account, as a shopkeeper reads it.
     *
     * Sales are taken from the sale headers, so this line is the same money the
     * sales list and the till's stats endpoint report and the owner can
     * reconcile it against the drawer. Cost of goods sold comes from the lines,
     * where the cost actually lives.
     *
     * The two only subtract cleanly once uncosted takings are removed:
     *
     *     gross_profit = sales_net - unknown_cost_revenue - cogs
     *
     * so the statement reads `sales - cogs = gross profit` exactly whenever
     * every line has a cost, and the shortfall is spelled out rather than
     * booked as profit whenever one does not. Ticket discounts need no special
     * handling — `sales.total` is already net of them, so a discount comes out
     * of the margin, which is where it comes from in real life.
     *
     * @return array{
     *     sales_net: float,
     *     sales_count: int,
     *     cogs: float,
     *     gross_profit: float,
     *     gross_margin_pct: float,
     *     wastage: float,
     *     wastage_uncosted: int,
     *     expenses: float,
     *     net_profit: float,
     *     unknown_cost_revenue: float,
     *     unknown_cost_lines: int,
     *     by_product: list<array<string, mixed>>,
     *     by_category: list<array<string, mixed>>
     * }
     */
    public function profit(int $ownerId, string $from, string $to): array
    {
        $bounds = $this->soldAtBounds($from, $to);

        $sales = DB::table('sales')
            ->where('user_id', $ownerId)
            ->whereBetween('sold_at', $bounds)
            ->selectRaw("SUM(total - refunded_amount) as net,
                SUM(CASE WHEN status <> 'refunded' THEN 1 ELSE 0 END) as sales_count")
            ->first();

        $lines = $this->saleItems($ownerId, $bounds)
            ->selectRaw(SaleProfit::COST_SUM.' as cogs, '
                .SaleProfit::UNKNOWN_REVENUE_SUM.' as unknown_revenue, '
                .SaleProfit::UNKNOWN_LINES_SUM.' as unknown_lines')
            ->first();

        $salesNet = round((float) ($sales?->net ?? 0), 2);
        $cogs = round((float) ($lines?->cogs ?? 0), 2);
        $unknownRevenue = round((float) ($lines?->unknown_revenue ?? 0), 2);
        $grossProfit = round($salesNet - $unknownRevenue - $cogs, 2);
        $wastage = $this->wastage($ownerId, $from, $to, $bounds);
        $expenses = $this->shopExpenses($ownerId, $from, $to);

        return [
            'sales_net' => $salesNet,
            'sales_count' => (int) ($sales?->sales_count ?? 0),
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            // Measured against every rupee taken, not just the costed ones: a
            // shop with half its cost prices missing should see the margin sag
            // until it fills them in, rather than a flattering number drawn
            // from the half it happens to have recorded.
            'gross_margin_pct' => $salesNet > 0 ? round($grossProfit / $salesNet * 100, 1) : 0.0,
            // Sabzi wilts and doodh turns most days, so wastage is a standing
            // cost of trading and not an exception — but it is not cost of
            // goods *sold*, so it sits below the gross line with the overheads.
            'wastage' => $wastage['amount'],
            'wastage_uncosted' => $wastage['uncosted'],
            'expenses' => $expenses,
            'net_profit' => round($grossProfit - $wastage['amount'] - $expenses, 2),
            'unknown_cost_revenue' => $unknownRevenue,
            'unknown_cost_lines' => (int) ($lines?->unknown_lines ?? 0),
            'by_product' => $this->profitByProduct($ownerId, $bounds),
            'by_category' => $this->profitByCategory($ownerId, $bounds),
        ];
    }

    /**
     * "Paisa kahan hai?" — where the money is standing right now.
     *
     * Deliberately a different question from profit. A shop can trade at a
     * healthy margin all month and still have an empty drawer because the
     * takings are sitting in udhaar or on the shelf; the three figures below
     * are the three places the money can be.
     *
     * The drawer, the debts and the stock are all *as of now* — a balance has
     * no date range, only a moment. Only the takings split honours the
     * requested period, because that is a flow and not a balance.
     *
     * @return array{
     *     cash_in_drawer: float,
     *     drawer: array<string, mixed>,
     *     receivable: float,
     *     receivable_count: int,
     *     stock_at_cost: float,
     *     stock_uncosted_products: int,
     *     takings_total: float,
     *     by_payment_method: array<string, float>
     * }
     */
    public function cashPosition(User $user, string $from, string $to): array
    {
        $ownerId = $user->dataOwnerId();
        $today = $this->dayBook->businessDate();
        $day = $this->dayBook->findForDate($user, $today);
        $drawer = $this->dayBook->cashPosition($user, $today);

        $opening = round((float) ($day?->opening_amount ?? 0), 2);

        $receivable = DB::table('sales')
            ->where('user_id', $ownerId)
            ->where('payment_status', '<>', 'paid')
            // Mirrors Sale::outstandingAmount(): refunded goods are no longer
            // collectable, and a sale can never owe less than nothing.
            ->selectRaw('SUM(CASE WHEN total - refunded_amount - paid_amount > 0
                    THEN total - refunded_amount - paid_amount ELSE 0 END) as owed,
                SUM(CASE WHEN total - refunded_amount - paid_amount > 0 THEN 1 ELSE 0 END) as sales_count')
            ->first();

        // Inactive products are counted too: taking a line off the till screen
        // does not take the sacks off the shelf.
        $stock = DB::table('products')
            ->where('user_id', $ownerId)
            ->where('track_stock', true)
            ->selectRaw('SUM(CASE WHEN cost IS NULL THEN 0 ELSE stock_quantity * cost END) as value,
                SUM(CASE WHEN cost IS NULL AND stock_quantity > 0 THEN 1 ELSE 0 END) as uncosted')
            ->first();

        $takings = DB::table('sales')
            ->where('user_id', $ownerId)
            ->whereBetween('sold_at', $this->soldAtBounds($from, $to))
            ->groupBy('payment_method')
            ->selectRaw('payment_method, SUM(total - refunded_amount) as amount')
            ->pluck('amount', 'payment_method');

        // Every method is listed, zeros included, so the shape does not change
        // with the day and the UI never has to guess what is missing.
        $byMethod = [];
        foreach (Sale::PAYMENT_METHODS as $method) {
            $byMethod[$method] = round((float) ($takings[$method] ?? 0), 2);
        }

        return [
            'cash_in_drawer' => round($opening + $drawer['net'], 2),
            'drawer' => [
                'date' => $today,
                'is_open' => $day !== null && $day->isOpen(),
                'opening' => $opening,
                'cash_in' => $drawer['in'],
                'cash_refunds' => $drawer['refunds'],
            ],
            'receivable' => round((float) ($receivable?->owed ?? 0), 2),
            'receivable_count' => (int) ($receivable?->sales_count ?? 0),
            'stock_at_cost' => round((float) ($stock?->value ?? 0), 2),
            // The valuation is only as complete as the cost prices behind it.
            'stock_uncosted_products' => (int) ($stock?->uncosted ?? 0),
            'takings_total' => round(array_sum($byMethod), 2),
            'by_payment_method' => $byMethod,
        ];
    }

    /**
     * Expenses the shop actually paid out, from the manual cash ledger.
     *
     * The day book's own float and closing count are excluded. They are not
     * spending — they are the same rupees leaving the owner's hand in the
     * morning and coming back at night — and counting the float as an expense
     * would wipe out a day of profit every day.
     *
     * Hand-written wastage is excluded too, but only because it is reported on
     * its own line instead; see wastage() for why both sources feed it.
     */
    private function shopExpenses(int $ownerId, string $from, string $to): float
    {
        return round((float) CashEntry::query()
            ->where('user_id', $ownerId)
            ->where('type', 'expense')
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->whereNotIn('category_id', ExpenseCategory::query()
                ->whereIn('slug', [
                    DayBookService::FLOAT_CATEGORY_SLUG,
                    DayBookService::CLOSE_CATEGORY_SLUG,
                    self::WASTAGE_CATEGORY_SLUG,
                ])
                ->select('id'))
            ->sum('amount'), 2);
    }

    /**
     * What the shop threw away, from both ends of the same feature.
     *
     * Stock written off the shelf carries the cost it was worth at the moment
     * it was written off, and a shop that instead files "Rs 500 of sabzi" as a
     * cash expense means exactly the same thing. Both are added here and
     * neither is left in `expenses`, so the loss appears once however the shop
     * happens to record it.
     *
     * A recount is deliberately not wastage — see
     * StockMovement::WASTAGE_REASONS: goods that were never on the shelf were
     * never paid for twice.
     *
     * @param  array{0: string, 1: string}  $bounds
     * @return array{amount: float, uncosted: int}
     */
    private function wastage(int $ownerId, string $from, string $to, array $bounds): array
    {
        $movements = DB::table('stock_movements')
            ->where('user_id', $ownerId)
            ->whereIn('reason', StockMovement::WASTAGE_REASONS)
            ->whereBetween('created_at', $bounds)
            ->selectRaw('SUM(COALESCE(cost_value, 0)) as value,
                SUM(CASE WHEN cost_value IS NULL THEN 1 ELSE 0 END) as uncosted')
            ->first();

        $entries = (float) CashEntry::query()
            ->where('user_id', $ownerId)
            ->where('type', 'expense')
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->whereIn('category_id', ExpenseCategory::query()
                ->where('slug', self::WASTAGE_CATEGORY_SLUG)
                ->select('id'))
            ->sum('amount');

        return [
            'amount' => round((float) ($movements?->value ?? 0) + $entries, 2),
            // Write-offs of a product with no cost price. Silently worth zero,
            // so the count is the only thing that says the figure is short.
            'uncosted' => (int) ($movements?->uncosted ?? 0),
        ];
    }

    /**
     * Top sellers by takings, with the margin each one earned.
     *
     * Ranked by money rather than units on purpose: half the shelf is sold by
     * weight, so "3000" of atta and "3" of cola are not comparable quantities,
     * while rupees always are.
     *
     * Grouped by the snapshotted line name as well as the product id, so a
     * product deleted from the catalogue still reports under the name it was
     * sold as instead of collapsing into one nameless row.
     *
     * @param  array{0: string, 1: string}  $bounds
     * @return list<array<string, mixed>>
     */
    private function profitByProduct(int $ownerId, array $bounds): array
    {
        $rows = $this->saleItems($ownerId, $bounds)
            ->groupBy('sale_items.product_id', 'sale_items.name')
            ->orderByDesc(DB::raw(SaleProfit::REVENUE_SUM))
            ->limit(self::TOP_PRODUCTS)
            ->get([
                'sale_items.product_id',
                'sale_items.name',
                DB::raw(SaleProfit::REVENUE_SUM.' as revenue'),
                DB::raw(SaleProfit::COST_SUM.' as cogs'),
                DB::raw(SaleProfit::PROFIT_SUM.' as profit'),
                DB::raw(SaleProfit::UNKNOWN_LINES_SUM.' as unknown_lines'),
            ]);

        return $rows->map(fn (object $row) => [
            'product_id' => $row->product_id === null ? null : (int) $row->product_id,
            'name' => $row->name,
            'revenue' => round((float) $row->revenue, 2),
            'cogs' => round((float) $row->cogs, 2),
            'profit' => round((float) $row->profit, 2),
            'margin_pct' => $this->marginPct((float) $row->profit, (float) $row->revenue),
            'has_unknown_cost' => (int) $row->unknown_lines > 0,
        ])->all();
    }

    /**
     * The same margin, rolled up the way the shop is laid out.
     *
     * A left join: a line whose product has since been deleted has no category
     * to attribute it to, but its takings were still real and must not vanish
     * from the total.
     *
     * @param  array{0: string, 1: string}  $bounds
     * @return list<array<string, mixed>>
     */
    private function profitByCategory(int $ownerId, array $bounds): array
    {
        $label = "COALESCE(product_categories.name, 'Uncategorised')";

        $rows = $this->saleItems($ownerId, $bounds)
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.product_category_id')
            ->groupBy(DB::raw($label))
            ->orderByDesc(DB::raw(SaleProfit::REVENUE_SUM))
            ->get([
                DB::raw($label.' as category'),
                DB::raw(SaleProfit::REVENUE_SUM.' as revenue'),
                DB::raw(SaleProfit::COST_SUM.' as cogs'),
                DB::raw(SaleProfit::PROFIT_SUM.' as profit'),
                DB::raw(SaleProfit::UNKNOWN_LINES_SUM.' as unknown_lines'),
            ]);

        return $rows->map(fn (object $row) => [
            'category' => $row->category,
            'revenue' => round((float) $row->revenue, 2),
            'cogs' => round((float) $row->cogs, 2),
            'profit' => round((float) $row->profit, 2),
            'margin_pct' => $this->marginPct((float) $row->profit, (float) $row->revenue),
            'has_unknown_cost' => (int) $row->unknown_lines > 0,
            // The doughnuts and the PDF share one row shape with the cash
            // ledger's category lists, so both screens render from the same
            // helper instead of two near-identical ones.
            'amount' => round((float) $row->revenue, 2),
        ])->all();
    }

    /**
     * @param  array{0: string, 1: string}  $bounds
     */
    private function saleItems(int $ownerId, array $bounds): QueryBuilder
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.user_id', $ownerId)
            ->whereBetween('sales.sold_at', $bounds);
    }

    private function marginPct(float $profit, float $revenue): float
    {
        return $revenue > 0 ? round($profit / $revenue * 100, 1) : 0.0;
    }

    /**
     * `sold_at` is a timestamp while reports are asked for in whole days, so
     * the range is widened to the ends of those days — a sale rung up at 8pm
     * on the closing date belongs to the period the owner asked for.
     *
     * @return array{0: string, 1: string}
     */
    private function soldAtBounds(string $from, string $to): array
    {
        return [$from.' 00:00:00', $to.' 23:59:59'];
    }
}
