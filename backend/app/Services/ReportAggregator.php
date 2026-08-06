<?php

namespace App\Services;

use App\Models\CashEntry;
use Illuminate\Support\Facades\DB;

class ReportAggregator
{
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
}
